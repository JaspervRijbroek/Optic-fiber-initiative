<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Registration;
use App\Message\SendConfirmationEmail;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        $inputMode = trim((string) ($data['input_mode'] ?? 'cadastral_reference'));
        $cadastralReference = strtoupper(trim((string) ($data['cadastral_reference'] ?? '')));
        $coordinateXRaw = trim((string) ($data['coordinate_x'] ?? ''));
        $coordinateYRaw = trim((string) ($data['coordinate_y'] ?? ''));
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
        $coordinateX = null;
        $coordinateY = null;

        if ($inputMode === 'coordinates') {
            if ($coordinateXRaw === '' || $coordinateYRaw === '') {
                return $this->json(['error' => 'Las coordenadas son obligatorias cuando seleccionas esta opción.'], Response::HTTP_BAD_REQUEST);
            }

            if (!is_numeric($coordinateXRaw) || !is_numeric($coordinateYRaw)) {
                return $this->json(['error' => 'Las coordenadas deben ser valores numéricos válidos.'], Response::HTTP_BAD_REQUEST);
            }

            $coordinateX = (float) $coordinateXRaw;
            $coordinateY = (float) $coordinateYRaw;

            if ($coordinateX < -180 || $coordinateX > 180 || $coordinateY < -90 || $coordinateY > 90) {
                return $this->json(['error' => 'Las coordenadas recibidas no son válidas.'], Response::HTTP_BAD_REQUEST);
            }

            $resolved = $this->resolveCadastralReferenceFromCoordinates($coordinateX, $coordinateY);
            if ($resolved === null) {
                return $this->json(['error' => 'No se ha podido obtener la Referencia Catastral desde las coordenadas.'], Response::HTTP_BAD_REQUEST);
            }

            $cadastralReference = $resolved;
        } elseif ($inputMode === 'cadastral_reference') {
            if (!$cadastralReference) {
                return $this->json(['error' => 'El campo "Referencia Catastral" es obligatorio.'], Response::HTTP_BAD_REQUEST);
            }
            if (!preg_match('/^[A-Z0-9]{20}$/', $cadastralReference)) {
                return $this->json(['error' => 'La Referencia Catastral debe tener exactamente 20 caracteres alfanuméricos (letras y números).'], Response::HTTP_BAD_REQUEST);
            }
        } else {
            return $this->json(['error' => 'Modo de entrada no válido.'], Response::HTTP_BAD_REQUEST);
        }

        // Duplicate cadastral reference check
        if ($this->registrationRepository->findByCadastralReference($cadastralReference) !== null) {
            return $this->json(['error' => 'Esta Referencia Catastral ya está registrada.'], Response::HTTP_CONFLICT);
        }

        // Persist new registration
        $unsubscribeToken = bin2hex(random_bytes(24));
        $registration = new Registration($nombre, $email, $cadastralReference, $unsubscribeToken, $coordinateX, $coordinateY);

        $this->em->persist($registration);
        $this->em->flush();

        // Dispatch confirmation email to the async message queue
        $siteUrl = rtrim($this->siteUrl ?: $request->getSchemeAndHttpHost(), '/');
        $this->bus->dispatch(new SendConfirmationEmail($nombre, $email, $unsubscribeToken, $siteUrl));

        return $this->json(['success' => true], Response::HTTP_CREATED);
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

    private function resolveCadastralReferenceFromCoordinates(float $coordinateX, float $coordinateY): ?string
    {
        try {
            $response = $this->httpClient->request('GET', 'http://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCoordenadas.svc/rest/Consulta_RCCOOR_Distancia', [
                'query' => [
                    'CoorX' => (string) $coordinateX,
                    'CoorY' => (string) $coordinateY,
                    'SRS' => 'EPSG:4326',
                ],
            ]);

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                return null;
            }

            return $this->extractCadastralReferenceFromResponse($response->getContent(false));
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractCadastralReferenceFromResponse(string $content): ?string
    {
        $reference = $this->findCadastralReferenceInText($content);
        if ($reference !== null) {
            return $reference;
        }

        $json = json_decode($content, true);
        if (is_array($json)) {
            $reference = $this->findCadastralReferenceInArray($json);
            if ($reference !== null) {
                return $reference;
            }
        }

        $xml = @simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml !== false) {
            $xmlJson = json_encode($xml);
            if (is_string($xmlJson)) {
                $xmlArray = json_decode($xmlJson, true);
                if (is_array($xmlArray)) {
                    return $this->findCadastralReferenceInArray($xmlArray);
                }
            }
        }

        return null;
    }

    private function findCadastralReferenceInArray(array $payload): ?string
    {
        $reference = null;

        array_walk_recursive($payload, function (mixed $value) use (&$reference): void {
            if (!is_string($value)) {
                return;
            }

            $match = $this->findCadastralReferenceInText($value);
            if ($match !== null) {
                $reference = $match;
            }
        });

        return $reference ?? null;
    }

    private function findCadastralReferenceInText(string $text): ?string
    {
        if (preg_match('/([A-Z0-9]{20})/i', $text, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function corsResponse(): JsonResponse
    {
        $response = $this->json(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }
}
