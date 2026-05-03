<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class UnsubscribeController extends AbstractController
{
    public function __construct(
        private readonly RegistrationRepository $registrationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/unsubscribe', name: 'api_unsubscribe', methods: ['GET'])]
    public function unsubscribe(Request $request): Response
    {
        $token = trim((string) $request->query->get('token', ''));

        if (!$token) {
            return $this->renderPage('Enlace no válido', '<p>El enlace de baja no es válido.</p>', Response::HTTP_BAD_REQUEST);
        }

        $registration = $this->registrationRepository->findByUnsubscribeToken($token);

        if ($registration === null) {
            return $this->renderPage(
                'Solicitud procesada',
                '<p>Tus datos ya han sido eliminados de nuestra lista o el enlace no es válido.</p>'
            );
        }

        $this->em->remove($registration);
        $this->em->flush();

        return $this->renderPage(
            '¡Baja confirmada!',
            '<p>Hemos eliminado todos tus datos de nuestra lista. No recibirás más comunicaciones de esta iniciativa.</p>'
                . '<p class="mt-4"><a href="/" class="font-semibold text-[#1a237e] hover:underline">Volver al inicio</a></p>'
        );
    }

    private function renderPage(string $title, string $bodyContent, int $status = Response::HTTP_OK): Response
    {
        return $this->render('unsubscribe/page.html.twig', [
            'title' => $title,
            'bodyContent' => $bodyContent,
        ], new Response('', $status));
    }
}
