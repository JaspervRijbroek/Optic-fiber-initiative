<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    public function __construct(
        private readonly ?string $turnstileSiteKey,
    ) {}

    #[Route('/', name: 'page_index')]
    public function index(): Response
    {
        return $this->render('page/index.html.twig', [
            'turnstileSiteKey' => $this->turnstileSiteKey ?? '',
        ]);
    }

    #[Route('/info', name: 'page_info')]
    public function info(): Response
    {
        return $this->render('page/info.html.twig');
    }
}
