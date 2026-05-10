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
        $cadastralReference = strtoupper(trim((string) ($data['cadastral_reference'] ?? '')));
        $latitudeRaw = $data['latitude'] ?? null;
        $longitudeRaw = $data['longitude'] ?? null;
        $turnstileToken = trim((string) ($data['turnstileToken'] ?? ''));

        $latitude = $this->normalizeCoordinate($latitudeRaw);
        $longitude = $this->normalizeCoordinate($longitudeRaw);
        $hasAnyCoordinates = $latitude !== null || $longitude !== null;
        $hasCompleteCoordinates = $latitude !== null && $longitude !== null;

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
        if (!$cadastralReference && !$hasAnyCoordinates) {
            return $this->json(['error' => 'Debes introducir una Referencia Catastral o compartir tu ubicación GPS.'], Response::HTTP_BAD_REQUEST);
        }
        if ($cadastralReference && !preg_match('/^[A-Z0-9]{20}$/', $cadastralReference)) {
            return $this->json(['error' => 'La Referencia Catastral debe tener exactamente 20 caracteres alfanuméricos (letras y números).'], Response::HTTP_BAD_REQUEST);
        }
        if ($hasAnyCoordinates && !$hasCompleteCoordinates) {
            return $this->json(['error' => 'Debes proporcionar latitud y longitud válidas para la ubicación GPS.'], Response::HTTP_BAD_REQUEST);
        }
        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            return $this->json(['error' => 'La latitud GPS debe estar entre -90 y 90.'], Response::HTTP_BAD_REQUEST);
        }
        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            return $this->json(['error' => 'La longitud GPS debe estar entre -180 y 180.'], Response::HTTP_BAD_REQUEST);
        }

        // Duplicate cadastral reference check
        if ($cadastralReference && $this->registrationRepository->findByCadastralReference($cadastralReference) !== null) {
            return $this->json(['error' => 'Esta Referencia Catastral ya está registrada.'], Response::HTTP_CONFLICT);
        }

        // Persist new registration
        $unsubscribeToken = bin2hex(random_bytes(24));
        $registration = new Registration(
            $nombre,
            $email,
            $cadastralReference ?: null,
            $latitude,
            $longitude,
            $unsubscribeToken
        );

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

    private function corsResponse(): JsonResponse
    {
        $response = $this->json(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
