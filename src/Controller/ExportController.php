<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RegistrationRepository;
use App\Service\CadastralReferenceService;
use App\Service\Exception\CadastralReferenceException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly RegistrationRepository $registrationRepository,
        private readonly CadastralReferenceService $cadastralReferenceService,
        private readonly LoggerInterface $logger,
        private readonly string $exportSecret,
    ) {}

    #[Route('/export', name: 'api_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if (!$this->exportSecret) {
            return new Response('Configuración del servidor incompleta.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $token = (string) $request->query->get('token', '');

        if (!$this->timingSafeEqual($token, $this->exportSecret)) {
            return new Response(
                'No autorizado. Proporciona el token correcto.',
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer realm="Exportar registros"']
            );
        }

        $registrations = $this->registrationRepository->findAllOrderedByCreatedAt();

        $filename = sprintf('registros-fibra-optica-%s.csv', date('Y-m-d'));

        $response = new StreamedResponse(function () use ($registrations): void {
            $handle = fopen('php://output', 'w');

            // BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['ID', 'Nombre', 'Email', 'Referencia Catastral', 'Dirección', 'Código Postal', 'Municipio', 'Fecha de Registro']);

            foreach ($registrations as $registration) {
                $streetAddress = '';
                $zipcode = '';
                $city = '';

                try {
                    $addressData = $this->cadastralReferenceService->resolveAddressDataFromReference(
                        $registration->getCadastralReference()
                    );
                    $streetAddress = $addressData['address'];
                    $zipcode = $addressData['zipcode'];
                    $city = $addressData['city'];
                } catch (CadastralReferenceException $e) {
                    $this->logger->warning('Could not resolve address for cadastral reference during export.', [
                        'cadastral_reference' => $registration->getCadastralReference(),
                        'exception' => $e,
                    ]);
                }

                fputcsv($handle, [
                    $registration->getId(),
                    $registration->getNombre(),
                    $registration->getEmail(),
                    $registration->getCadastralReference(),
                    $streetAddress,
                    $zipcode,
                    $city,
                    $registration->getCreatedAt()->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * Constant-time string comparison to mitigate timing-based token guessing.
     */
    private function timingSafeEqual(string $a, string $b): bool
    {
        if (!$a || !$b || strlen($a) !== strlen($b)) {
            return false;
        }

        return hash_equals($a, $b);
    }
}
