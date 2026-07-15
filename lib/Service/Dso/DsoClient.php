<?php

/**
 * OpenConnector DSO (Digitaal Stelsel Omgevingswet) Client.
 *
 * Thin REST binding for {@see DsoConnectorProviderInterface} against a
 * DSO-LV-fronted "voortgangsinformatie"/besluit endpoint. Deliberately a
 * hand-rolled HTTP client (Guzzle, already an app dependency) rather than a
 * DSO SDK dependency — mirrors {@see \OCA\OpenConnector\Service\IwmoIjw\IStandaardenClient}
 * and {@see \OCA\OpenConnector\Service\Kiss\KlantinteractiesClient}.
 *
 * ASSUMED TRANSPORT SHAPE — no live DSO-LV/preprod connection was available
 * to verify against in this environment; every endpoint/header below is an
 * explicit, documented assumption. See design.md "Outbound message-shape
 * assumptions" for the full list:
 * - `POST {baseUrl}/statussen` with `{verzoekId, status, timestamp}` posts a
 *   status (voortgangsinformatie) update; `POST {baseUrl}/besluiten` with
 *   `{verzoekId, besluit, gemotiveerd, timestamp}` posts a besluit. Both
 *   accept a JSON `{ref: "..."}` response envelope, or an empty 2xx body (in
 *   which case a locally-derived reference is returned — see `extractRef()`).
 * - Auth: `Authorization: Bearer <token>` by default — a DELIBERATE
 *   DEVIATION from the real DSO-LV transport, which uses PKIoverheid
 *   client-certificate (mTLS) authentication for production traffic, not a
 *   bearer token (see {@see \OCA\OpenConnector\Service\DSOSignatureVerifierService},
 *   which already implements the *inbound* PKIoverheid chain-validation
 *   half of this — this class is the *outbound* leg and does not reuse it,
 *   since verifying an inbound signature and presenting an outbound client
 *   certificate are different operations). This is flagged explicitly in
 *   design.md "Open Questions" — mTLS client-certificate support on the
 *   OUTBOUND leg is a documented gap, not a silent omission. The
 *   bearer-token shape mirrors every other REST provider binding already in
 *   this app so the connector is at least demonstrable end-to-end against a
 *   stand-in endpoint, and is the same pre-production fallback shape
 *   `DSOSignatureVerifierService` already supports for the inbound leg
 *   (`MODE_HMAC` vs `MODE_RSA`).
 *
 * CREDENTIAL STORAGE: the token is a static bearer-style secret — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never
 * logged, never persisted decrypted). Mirrors `IStandaardenClient`'s
 * identical, already-accepted deviation from `credentialRef`/
 * `BrokeredCallService`.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Exception\DsoProviderException;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST DSO outbound provider: token-authenticated status/besluit dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class DsoClient implements DsoConnectorProviderInterface
{

    /**
     * Default `Authorization` header scheme.
     *
     * @var string
     */
    private const DEFAULT_AUTH_SCHEME = 'Bearer';

    /**
     * Outbound message-kind to endpoint-path mapping (relative to `configuration.baseUrl`).
     *
     * @var array<string, string>
     */
    private const PATHS = [
        'status'  => '/statussen',
        'besluit' => '/besluiten',
    ];

    /**
     * Constructor.
     *
     * @param Client          $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
     * @param ICrypto         $crypto     Encrypts/decrypts the stored API token at rest.
     * @param IL10N           $l          The localization service.
     * @param LoggerInterface $logger     Logger for secret-free failure diagnostics.
     */
    public function __construct(
        private readonly Client $httpClient,
        private readonly ICrypto $crypto,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The stable `rest` provider identifier.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getProviderId(): string
    {
        return 'rest';

    }//end getProviderId()

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed> The DSO source configuration JSON Schema.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['authentication'],
            'properties' => [
                'baseUrl'         => [
                    'type'        => 'string',
                    'description' => 'DSO-LV outbound (voortgangsinformatie/besluit) endpoint base URL (no trailing slash)',
                ],
                'authentication'  => [
                    'type'       => 'object',
                    'required'   => ['encryptedToken'],
                    'properties' => [
                        'encryptedToken' => [
                            'type'        => 'string',
                            'description' => 'The DSO-LV API token, encrypted at rest via OCP\\Security\\ICrypto — '
                                .'never store the raw token. NOTE: the real DSO-LV transport uses PKIoverheid '
                                .'client-certificate (mTLS) auth for production traffic, not a bearer token — see '
                                .'design.md "Open Questions".',
                        ],
                        'scheme'         => [
                            'type'        => 'string',
                            'description' => 'Authorization header scheme.',
                            'default'     => self::DEFAULT_AUTH_SCHEME,
                        ],
                    ],
                ],
                'bronorganisatie' => [
                    'type'        => 'string',
                    'description' => 'This bevoegd gezag\'s OIN/bronorganisatie code (outbound stuurgegevens default).',
                ],
            ],
        ];

    }//end getConfigSchema()

    /**
     * {@inheritDoc}
     *
     * @param array  $sourceConfiguration The `dso` source's `configuration` object.
     * @param string $verzoekId           The DSO `verzoekId` this message concerns.
     * @param string $type                The message kind: `status` or `besluit`.
     * @param array  $payload             The already-built message payload.
     *
     * @return string The extracted (or locally-derived) reference.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
     */
    public function send(array $sourceConfiguration, string $verzoekId, string $type, array $payload): string
    {
        $baseUrl = rtrim((string) ($sourceConfiguration['baseUrl'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new DsoProviderException(
                message: $this->l->t('DSO base URL missing').': `configuration.baseUrl` is required.'
            );
        }

        $path = (self::PATHS[$type] ?? null);
        if ($path === null) {
            throw new DsoProviderException(
                message: 'Unknown DSO outbound message type "'.$type.'" (must be `status` or `besluit`).'
            );
        }

        $requestOptions = [
            'headers'     => [
                'Authorization' => $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'json'        => $payload,
            'http_errors' => false,
        ];

        try {
            $response = $this->httpClient->request('POST', $baseUrl.$path, $requestOptions);
        } catch (GuzzleException $exception) {
            $this->logger->warning(
                '[DsoClient] unexpected transport failure',
                ['exception' => $exception->getMessage()]
            );
            throw new DsoProviderException(
                message: 'The DSO-LV request failed unexpectedly: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new DsoProviderException(message: 'DSO-LV endpoint responded with HTTP '.$status.'.');
        }

        return $this->extractRef(body: $body, verzoekId: $verzoekId, type: $type);

    }//end send()

    /**
     * Extract a usable reference from the response body — a JSON `{ref:
     * "..."}` envelope when present, otherwise a locally-derived reference
     * (the endpoint accepted the message but assigned no ref of its own).
     *
     * @param string $body      The raw response body.
     * @param string $verzoekId The DSO `verzoekId` this message concerned.
     * @param string $type      The message kind (`status`|`besluit`).
     *
     * @return string The extracted (or locally-derived) reference.
     */
    private function extractRef(string $body, string $verzoekId, string $type): string
    {
        $trimmed = trim($body);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) === true) {
                $ref = ($decoded['ref'] ?? null);
                if (is_string($ref) === true && $ref !== '') {
                    return $ref;
                }
            }
        }

        // No transport-assigned ref — derive a locally-unique one so the
        // caller still has something to persist/correlate on.
        return $verzoekId.'-'.$type.'-'.(string) time();

    }//end extractRef()

    /**
     * Build the per-request `Authorization: <scheme> <token>` header value.
     *
     * Decrypts `configuration.authentication.encryptedToken` (never logged,
     * never persisted decrypted).
     *
     * @param array $sourceConfiguration The `dso` source's `configuration` object.
     *
     * @return string The Authorization header value.
     *
     * @throws DsoProviderException When the credential is missing or undecryptable.
     */
    private function buildAuthorizationHeader(array $sourceConfiguration): string
    {
        $encrypted = (string) ($sourceConfiguration['authentication']['encryptedToken'] ?? '');
        if ($encrypted === '') {
            throw new DsoProviderException(
                message: $this->l->t('DSO credential missing').
                    ': `configuration.authentication.encryptedToken` is required. No plaintext-token fallback is permitted.'
            );
        }

        try {
            $token = $this->crypto->decrypt($encrypted);
        } catch (Throwable $exception) {
            throw new DsoProviderException(
                message: 'The stored DSO API token could not be decrypted: '.$exception->getMessage()
            );
        }

        $scheme = (string) ($sourceConfiguration['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

        return $scheme.' '.$token;

    }//end buildAuthorizationHeader()
}//end class
