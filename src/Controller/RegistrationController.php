<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Registration;
use App\Message\SendConfirmationEmail;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api')]
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly RegistrationRepository $registrationRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly string $siteUrl,
        private readonly ?string $turnstileSecretKey,
    ) {}

    #[Route('/register', name: 'api_register', methods: ['POST', 'OPTIONS'])]
    public function register(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse();
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Cuerpo de la solicitud no válido.'], Response::HTTP_BAD_REQUEST);
        }

        $nombre = trim((string) ($data['nombre'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $cadastralReference = strtoupper(trim((string) ($data['cadastral_reference'] ?? '')));
        $turnstileToken = trim((string) ($data['turnstileToken'] ?? ''));

        // Verify Turnstile (skipped when secret key is not configured)
        if ($this->turnstileSecretKey && !$this->verifyTurnstile($turnstileToken, $request->getClientIp())) {
            return $this->json(
                ['error' => 'Verificación de seguridad fallida. Por favor, inténtalo de nuevo.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Validate required fields
        if (!$nombre) {
            return $this->json(['error' => 'El campo "nombre" es obligatorio.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Introduce un correo electrónico válido.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$cadastralReference) {
            return $this->json(['error' => 'El campo "Referencia Catastral" es obligatorio.'], Response::HTTP_BAD_REQUEST);
        }
        if (!preg_match('/^[A-Z0-9]{20}$/', $cadastralReference)) {
            return $this->json(['error' => 'La Referencia Catastral debe tener exactamente 20 caracteres alfanuméricos (letras y números).'], Response::HTTP_BAD_REQUEST);
        }

        // Duplicate cadastral reference check
        if ($this->registrationRepository->findByCadastralReference($cadastralReference) !== null) {
            return $this->json(['error' => 'Esta Referencia Catastral ya está registrada.'], Response::HTTP_CONFLICT);
        }

        // Persist new registration
        $unsubscribeToken = bin2hex(random_bytes(24));
        $registration = new Registration($nombre, $email, $cadastralReference, $unsubscribeToken);
        $registration->setGpsCoordinates(
            $this->getSessionLatitude($request, $cadastralReference),
            $this->getSessionLongitude($request, $cadastralReference)
        );

        $this->em->persist($registration);
        $this->em->flush();
        $this->clearGpsSession($request);

        // Dispatch confirmation email to the async message queue
        $siteUrl = rtrim($this->siteUrl ?: $request->getSchemeAndHttpHost(), '/');
        $this->bus->dispatch(new SendConfirmationEmail($nombre, $email, $unsubscribeToken, $siteUrl));

        return $this->json(['success' => true], Response::HTTP_CREATED);
    }

    #[Route('/cadastral-reference-from-coordinates', name: 'api_cadastral_reference_from_coordinates', methods: ['POST', 'OPTIONS'])]
    public function cadastralReferenceFromCoordinates(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse();
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Cuerpo de la solicitud no válido.'], Response::HTTP_BAD_REQUEST);
        }

        $latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!is_float($latitude) || !is_float($longitude) || !$this->areValidCoordinates($latitude, $longitude)) {
            return $this->json(['error' => 'Coordenadas GPS no válidas.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                'http://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCoordenadas.svc/rest/Consulta_RCCOOR_Distancia',
                [
                    'query' => [
                        'CoorX' => (string) $longitude,
                        'CoorY' => (string) $latitude,
                        'SRS' => 'EPSG:4326',
                    ],
                ]
            );

            if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                return $this->json(['error' => 'No se pudo consultar Catastro en este momento.'], Response::HTTP_BAD_GATEWAY);
            }

            $payload = json_decode($response->getContent(false), true);
            if (!is_array($payload)) {
                return $this->json(['error' => 'Respuesta inesperada del Catastro.'], Response::HTTP_BAD_GATEWAY);
            }

            $cadastralReference = $this->findCadastralReference($payload);
            if ($cadastralReference === null) {
                return $this->json(
                    ['error' => 'No se ha encontrado una Referencia Catastral para esta ubicación.'],
                    Response::HTTP_NOT_FOUND
                );
            }

            $request->getSession()->set('gps_latitude', $latitude);
            $request->getSession()->set('gps_longitude', $longitude);
            $request->getSession()->set('gps_cadastral_reference', $cadastralReference);

            return $this->json(['cadastral_reference' => $cadastralReference], Response::HTTP_OK);
        } catch (\Throwable $exception) {
            $this->logger->warning('Catastro cadastral-reference lookup failed.', [
                'exception' => $exception,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            return $this->json(['error' => 'No se pudo obtener la Referencia Catastral automáticamente.'], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function verifyTurnstile(string $token, ?string $ip): bool
    {
        try {
            $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => array_filter([
                    'secret' => $this->turnstileSecretKey,
                    'response' => $token,
                    'remoteip' => $ip,
                ]),
            ]);

            $data = $response->toArray();

            return $data['success'] === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function corsResponse(): JsonResponse
    {
        $response = $this->json(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }

    private function areValidCoordinates(float $latitude, float $longitude): bool
    {
        return $latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0;
    }

    private function findCadastralReference(mixed $payload): ?string
    {
        if (is_string($payload)) {
            $candidate = strtoupper(trim($payload));
            if (preg_match('/^[A-Z0-9]{20}$/', $candidate)) {
                return $candidate;
            }

            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach ($payload as $value) {
            $found = $this->findCadastralReference($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function getSessionLatitude(Request $request, string $cadastralReference): ?float
    {
        return $this->getSessionCoordinate($request, $cadastralReference, 'gps_latitude');
    }

    private function getSessionLongitude(Request $request, string $cadastralReference): ?float
    {
        return $this->getSessionCoordinate($request, $cadastralReference, 'gps_longitude');
    }

    private function getSessionCoordinate(Request $request, string $cadastralReference, string $coordinateKey): ?float
    {
        $sessionReference = strtoupper(trim((string) $request->getSession()->get('gps_cadastral_reference', '')));
        $coordinate = $request->getSession()->get($coordinateKey);

        if ($sessionReference !== $cadastralReference || !is_numeric($coordinate)) {
            return null;
        }

        return (float) $coordinate;
    }

    private function clearGpsSession(Request $request): void
    {
        $request->getSession()->remove('gps_latitude');
        $request->getSession()->remove('gps_longitude');
        $request->getSession()->remove('gps_cadastral_reference');
    }
}
