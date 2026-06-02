<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Registration;
use App\Message\SendConfirmationEmail;
use App\Repository\RegistrationRepository;
use App\Service\CadastralReferenceService;
use App\Service\Exception\CadastralReferenceException;
use App\Service\Exception\CadastralReferenceNotFoundException;
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
        private readonly CadastralReferenceService $cadastralReferenceService,
        private readonly EntityManagerInterface $em,
        private readonly RegistrationRepository $registrationRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly string $siteUrl,
        private readonly ?string $turnstileSecretKey,
        private readonly string $nominatimUserAgent = 'OpticFiber/1.0',
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

        $this->em->persist($registration);
        $this->em->flush();

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

        if (!$this->isWithinSpain($latitude, $longitude)) {
            return $this->json(['error' => 'Las coordenadas GPS están fuera del territorio español.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->cadastralReferenceService->resolveFromCoordinates($latitude, $longitude);
        } catch (CadastralReferenceNotFoundException $e) {
            $this->logger->info('No cadastral reference found for coordinates.', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'exception' => $e,
            ]);

            return $this->json(
                ['error' => 'No se ha encontrado una Referencia Catastral para esta ubicación.'],
                Response::HTTP_NOT_FOUND
            );
        } catch (CadastralReferenceException $e) {
            $this->logger->warning('Catastro cadastral-reference lookup failed.', [
                'exception' => $e,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            return $this->json(['error' => 'No se pudo obtener la Referencia Catastral automáticamente.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'cadastral_reference' => $result['reference'],
            'address' => $result['address'],
        ], Response::HTTP_OK);
    }

    #[Route('/address-from-cadastral-reference', name: 'api_address_from_cadastral_reference', methods: ['GET', 'OPTIONS'])]
    public function addressFromCadastralReference(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse();
        }

        $reference = strtoupper(trim((string) $request->query->get('reference', '')));

        if (!preg_match('/^[A-Z0-9]{20}$/', $reference)) {
            return $this->json(['error' => 'Referencia Catastral no válida.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $address = $this->cadastralReferenceService->resolveAddressFromReference($reference);
        } catch (CadastralReferenceException $e) {
            $this->logger->info('Could not resolve address for cadastral reference.', [
                'reference' => $reference,
                'exception' => $e,
            ]);

            return $this->json(['error' => 'No se pudo obtener la dirección para esta Referencia Catastral.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['address' => $address], Response::HTTP_OK);
    }

    #[Route('/address-search', name: 'api_address_search', methods: ['GET', 'OPTIONS'])]
    public function addressSearch(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse();
        }

        $q = trim((string) $request->query->get('q', ''));

        if (mb_strlen($q) < 3) {
            return $this->json(['error' => 'La búsqueda debe tener al menos 3 caracteres.'], Response::HTTP_BAD_REQUEST);
        }

        $userAgent = $this->nominatimUserAgent ?: 'OpticFiber/1.0';

        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
                'query' => [
                    'q' => $q,
                    'format' => 'json',
                    'countrycodes' => 'es',
                    'addressdetails' => '0',
                    'limit' => '5',
                ],
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept-Language' => 'es',
                ],
                'timeout' => 10,
            ]);

            $results = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Nominatim address search failed.', ['exception' => $e, 'q' => $q]);

            return $this->json(['error' => 'No se pudo realizar la búsqueda de dirección.'], Response::HTTP_BAD_GATEWAY);
        }

        if (!is_array($results)) {
            return $this->json([], Response::HTTP_OK);
        }

        $simplified = array_map(static fn (array $item): array => [
            'display_name' => (string) ($item['display_name'] ?? ''),
            'lat' => (string) ($item['lat'] ?? ''),
            'lon' => (string) ($item['lon'] ?? ''),
        ], $results);

        return $this->json($simplified, Response::HTTP_OK);
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

    private function isWithinSpain(float $latitude, float $longitude): bool
    {
        // Bounding box covering mainland Spain, the Canary Islands, Ceuta, and Melilla.
        return $latitude >= 27.6 && $latitude <= 43.8 && $longitude >= -18.2 && $longitude <= 4.3;
    }

}
