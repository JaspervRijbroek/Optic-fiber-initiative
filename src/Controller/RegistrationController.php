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
        $cru = strtoupper(trim((string) ($data['cru'] ?? '')));
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
        if (!$cru) {
            return $this->json(['error' => 'El campo "CRU" es obligatorio.'], Response::HTTP_BAD_REQUEST);
        }

        // Duplicate CRU check
        if ($this->registrationRepository->findByCru($cru) !== null) {
            return $this->json(['error' => 'Este CRU ya está registrado.'], Response::HTTP_CONFLICT);
        }

        // Persist new registration
        $unsubscribeToken = bin2hex(random_bytes(24));
        $registration = new Registration($nombre, $email, $cru, $unsubscribeToken);

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
            $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v1/siteverify', [
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
}
