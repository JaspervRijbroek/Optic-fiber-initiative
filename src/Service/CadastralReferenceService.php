<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\CadastralReferenceException;
use App\Service\Exception\CadastralReferenceNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Converts GPS coordinates (WGS84) to a 20-character Spanish cadastral reference
 * (referencia catastral) for single-unit buildings (single houses).
 *
 * Implements the mandatory two-step workflow documented by the Spanish Directorate
 * General for Cadastre:
 *   1. Consulta_RCCOOR  — GPS coordinates → 14-character parcel reference
 *   2. Consulta_DNPRC   — 14-character parcel reference → 20-character building unit reference
 *
 * Only single-unit buildings (<bico> response) are supported. Division horizontal
 * (apartment blocks) will throw a CadastralReferenceException.
 */
final class CadastralReferenceService
{
    private const ASMX_COORDINATES_URL = 'https://ovc.catastro.meh.es/ovcservweb/OVCSWLocalizacionRC/OVCCoordenadas.asmx/Consulta_RCCOOR';
    private const ASMX_CALLEJERO_URL = 'https://ovc.catastro.meh.es/ovcservweb/OVCSWLocalizacionRC/OVCCallejero.asmx/Consulta_DNPRC';
    private const API_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Converts GPS coordinates to a 20-character Spanish cadastral reference and its address.
     *
     * @param float $lat WGS84 latitude
     * @param float $lon WGS84 longitude
     *
     * @return array{reference: string, address: string}
     *
     * @throws CadastralReferenceNotFoundException when no cadastral parcel exists at the given coordinates
     * @throws CadastralReferenceException         when the Catastro API returns an error, the building
     *                                             is a multi-unit block (división horizontal), or the
     *                                             assembled reference is invalid
     */
    public function resolveFromCoordinates(float $lat, float $lon): array
    {
        $parcelRef = $this->fetchParcelReference($lat, $lon);

        return $this->fetchBuildingUnitData($parcelRef);
    }

    /**
     * Resolves the address for a known 20-character cadastral reference.
     *
     * Uses the first 14 characters (the parcel reference) to query the Catastro DNPRC API.
     *
     * @throws CadastralReferenceException when the API call fails or the response cannot be parsed
     */
    public function resolveAddressFromReference(string $cadastralReference): string
    {
        $parcelRef = substr($cadastralReference, 0, 14);

        $data = $this->fetchBuildingUnitData($parcelRef);

        return $data['address'];
    }

    /**
     * Step 1: GPS coordinates → 14-character parcel reference via Consulta_RCCOOR.
     */
    private function fetchParcelReference(float $lat, float $lon): string
    {
        try {
            $response = $this->httpClient->request('GET', self::ASMX_COORDINATES_URL, [
                'query' => [
                    'SRS' => 'EPSG:4326',
                    'Coordenada_X' => (string) $lon,
                    'Coordenada_Y' => (string) $lat,
                ],
                'timeout' => self::API_TIMEOUT_SECONDS,
            ]);

            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Catastro Consulta_RCCOOR request failed.', [
                'exception' => $e,
                'latitude' => $lat,
                'longitude' => $lon,
            ]);

            throw new CadastralReferenceException(
                sprintf('Catastro API request failed: %s', $e->getMessage()),
                previous: $e
            );
        }

        $xml = $this->parseXml($content, 'consulta_coordenadas');

        if (isset($xml->lerr)) {
            $code = (string) ($xml->lerr->err->cod ?? 'unknown');
            $description = (string) ($xml->lerr->err->des ?? 'unknown error');

            if ($code === '99' || str_contains(strtolower($description), 'no encontrad') || str_contains(strtolower($description), 'not found')) {
                throw new CadastralReferenceNotFoundException(
                    sprintf('No cadastral parcel found at coordinates (%s, %s): [%s] %s', $lat, $lon, $code, $description)
                );
            }

            throw new CadastralReferenceException(
                sprintf('Catastro RCCOOR error [%s]: %s', $code, $description)
            );
        }

        $coord = $xml->coordenadas->coord ?? null;
        if ($coord === null) {
            throw new CadastralReferenceNotFoundException(
                sprintf('No parcel data returned for coordinates (%s, %s)', $lat, $lon)
            );
        }

        // When multiple coords are returned, take the first (nearest)
        if ($coord->count() > 1) {
            $coord = $coord[0];
        }

        $pc1 = (string) ($coord->pc->pc1 ?? '');
        $pc2 = (string) ($coord->pc->pc2 ?? '');

        if ($pc1 === '' || $pc2 === '') {
            throw new CadastralReferenceNotFoundException(
                sprintf('Parcel reference components missing for coordinates (%s, %s)', $lat, $lon)
            );
        }

        return $pc1 . $pc2;
    }

    /**
     * Step 2: 14-character parcel reference → 20-character building unit reference and address via Consulta_DNPRC.
     *
     * Only handles single-unit buildings (<bico> response). Throws for división horizontal.
     *
     * @return array{reference: string, address: string}
     */
    private function fetchBuildingUnitData(string $parcelRef): array
    {
        try {
            $response = $this->httpClient->request('GET', self::ASMX_CALLEJERO_URL, [
                // Provincia and Municipio must be present as empty strings — omitting them causes error 99.
                'query' => [
                    'Provincia' => '',
                    'Municipio' => '',
                    'RC' => $parcelRef,
                ],
                'timeout' => self::API_TIMEOUT_SECONDS,
            ]);

            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Catastro Consulta_DNPRC request failed.', [
                'exception' => $e,
                'parcelRef' => $parcelRef,
            ]);

            throw new CadastralReferenceException(
                sprintf('Catastro API request failed: %s', $e->getMessage()),
                previous: $e
            );
        }

        $xml = $this->parseXml($content, 'consulta_dnp');

        if (isset($xml->lerr)) {
            $code = (string) ($xml->lerr->err->cod ?? 'unknown');
            $description = (string) ($xml->lerr->err->des ?? 'unknown error');

            throw new CadastralReferenceException(
                sprintf('Catastro DNPRC error [%s]: %s', $code, $description)
            );
        }

        // Multi-unit building (división horizontal) — not supported.
        if (isset($xml->lrcdnp)) {
            throw new CadastralReferenceException(
                sprintf('Parcel "%s" is a multi-unit building (división horizontal); only single houses are supported.', $parcelRef)
            );
        }

        $cudnp = (int) ($xml->control->cudnp ?? 1);
        if ($cudnp > 1) {
            throw new CadastralReferenceException(
                sprintf('Parcel "%s" contains %d units (división horizontal); only single houses are supported.', $parcelRef, $cudnp)
            );
        }

        $rc = $xml->bico->bi->idbi->rc ?? null;
        if ($rc === null) {
            throw new CadastralReferenceException(
                sprintf('Expected single-unit <bico> element not found in Catastro response for parcel "%s".', $parcelRef)
            );
        }

        $reference = strtoupper(
            ((string) ($rc->pc1 ?? '')) .
            ((string) ($rc->pc2 ?? '')) .
            ((string) ($rc->car ?? '')) .
            ((string) ($rc->cc1 ?? '')) .
            ((string) ($rc->cc2 ?? ''))
        );

        if (!preg_match('/^[A-Z0-9]{20}$/', $reference)) {
            throw new CadastralReferenceException(
                sprintf('Assembled cadastral reference "%s" is not a valid 20-character reference.', $reference)
            );
        }

        $address = trim((string) ($xml->bico->bi->ldt ?? ''));

        return ['reference' => $reference, 'address' => $address];
    }

    /**
     * Parses a Catastro XML response, stripping the default namespace to allow
     * straightforward SimpleXML property access.
     *
     * All Catastro responses carry xmlns="http://www.catastro.meh.es/". Without
     * stripping it, SimpleXML element access silently fails.
     */
    private function parseXml(string $content, string $expectedRootElement): \SimpleXMLElement
    {
        // Strip the default (un-prefixed) XML namespace declaration.
        $cleaned = (string) preg_replace('/\s+xmlns(?::[^=]+)?="[^"]*"/', '', $content);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($cleaned);
        libxml_clear_errors();

        if ($xml === false) {
            throw new CadastralReferenceException(
                sprintf('Failed to parse Catastro XML response (expected root: <%s>).', $expectedRootElement)
            );
        }

        return $xml;
    }
}
