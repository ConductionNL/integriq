<?php

/**
 * OpenConnector KISS Klantinteracties Client.
 *
 * Thin REST binding for {@see KlantinteractiesProviderInterface} against the
 * VNG "Klantinteracties API" (the OpenKlant 2.x reference model that KISS —
 * the open-source Common Ground Klantinteractie Servicesysteem built by
 * Utrecht + Dimpact — implements and exposes). Deliberately a hand-rolled
 * HTTP client (Guzzle, already an app dependency) rather than an SDK
 * dependency — the contract is a handful of REST resources
 * (`klantcontacten`, `betrokkenen`, `onderwerpobjecten`) behind one token
 * auth scheme, mirroring {@see \OCA\OpenConnector\Service\Sms\RestNotifyNlProvider}
 * and {@see \OCA\OpenConnector\Service\Peppol\RestPeppolAccessPointProvider}.
 *
 * ASSUMED API SHAPE — no live KISS instance was available to verify against
 * in this environment; every endpoint/field/param below is an explicit,
 * documented assumption. See design.md "API-shape assumptions" for the full
 * list and its grounding (the published VNG Klantinteracties OpenAPI
 * specification, OpenKlant 2.x, and this app's OWN already-implemented
 * server-side half of the same dialect — see
 * `openspec/specs/vng-klantinteracties-adapter/spec.md` and
 * `lib/Rule/AvgBsnPolicyRule.php` — which grounds the field vocabulary used
 * here: `onderwerp`, `kanaal`, `tekst`, `plaatsgevondenOp`, `registratiedatum`,
 * `partijIdentificator{codeSoortObjectId,objectId}`,
 * `onderwerpobjectidentificator{objectId,codeObjecttype,codeRegister,codeSoortObjectId}`,
 * and the `field__operator` VNG filter convention):
 * - `GET  {baseUrl}/klantcontacten?registratiedatum__gte=<iso>&expand=betrokkenen,onderwerpobjecten&sorteer=registratiedatum&pageSize=<n>`
 *   returns `{count, next, previous, results: [...]}` (VNG's standard DRF-style
 *   pagination envelope), each result carrying `uuid`, `registratiedatum`, and
 *   (via `expand=`) inline `betrokkenen`/`onderwerpobjecten` arrays.
 * - `POST {baseUrl}/klantcontacten` with the klantcontact fields returns the
 *   created resource; its `uuid` is the KISS-assigned id.
 * - `POST {baseUrl}/betrokkenen` with `{klantcontact: {uuid}, ...}` attaches a
 *   betrokkene to an existing klantcontact (VNG models betrokkenen as a
 *   separate resource with a klantcontact FK, not an embedded sub-object).
 * - `POST {baseUrl}/onderwerpobjecten` with
 *   `{klantcontact: {uuid}, onderwerpobjectidentificator: {objectId,
 *   codeObjecttype, codeRegister, codeSoortObjectId}}` is the mechanism that
 *   ties a klantcontact to a case/zaak — `codeRegister: "ZRC"` (Zaakregistratie-
 *   component, i.e. OpenZaak) and `codeSoortObjectId: "UUID"|"IDENTIFICATIE"`
 *   (derived from the shape of the supplied case reference) are ASSUMED
 *   defaults, overridable via `configuration.onderwerpobject`.
 * - Auth: `Authorization: <scheme> <token>` where `<scheme>` defaults to
 *   `Token` (the VNG/Common Ground convention used by OpenZaak/OpenKlant,
 *   distinct from OAuth's `Bearer`), overridable via
 *   `configuration.authentication.scheme`.
 *
 * CREDENTIAL STORAGE: the KISS API token is a static bearer-style secret (no
 * per-request signing, unlike NotifyNL's JWT) — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never logged,
 * never persisted decrypted). A static-secret binding like this one is
 * exactly the "v1 scope" {@see \OCA\OpenConnector\Service\BrokeredCallService}
 * targets (see the Peppol `rest` provider's `credentialRef` precedent); this
 * client uses direct `ICrypto` storage instead, mirroring the
 * notifynl-sms-channel leaf, so the KISS bridge has no hard dependency on the
 * optional OpenRegister credential-broker class and stays self-contained and
 * unit-testable exactly like the other provider bindings in this app.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Kiss
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
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Exception\KissProviderException;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST KISS/Klantinteracties provider: token-authenticated list/create/link.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class KlantinteractiesClient implements KlantinteractiesProviderInterface {

	/**
	 * Default `Authorization` header scheme (VNG/Common Ground convention).
	 *
	 * @var string
	 */
	private const DEFAULT_AUTH_SCHEME = 'Token';

	/**
	 * Default expand parameter — inline betrokkenen + onderwerpobjecten on every klantcontact pull.
	 *
	 * @var string
	 */
	private const DEFAULT_EXPAND = 'betrokkenen,onderwerpobjecten';

	/**
	 * Default `onderwerpobjectidentificator.codeRegister` (Zaakregistratiecomponent, i.e. OpenZaak).
	 *
	 * @var string
	 */
	private const DEFAULT_CODE_REGISTER = 'ZRC';

	/**
	 * Default `onderwerpobjectidentificator.codeObjecttype` when the caller does not specify one.
	 *
	 * @var string
	 */
	public const DEFAULT_CASE_OBJECT_TYPE = 'zaak';

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param ICrypto $crypto Encrypts/decrypts the stored API token at rest.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
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
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getProviderId(): string {
		return 'rest';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The KISS source configuration JSON Schema.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['authentication'],
			'properties' => [
				'baseUrl' => [
					'type' => 'string',
					'description' => 'KISS / VNG Klantinteracties API base URL (no trailing slash)',
				],
				'authentication' => [
					'type' => 'object',
					'required' => ['encryptedToken'],
					'properties' => [
						'encryptedToken' => [
							'type' => 'string',
							'description' => 'The KISS API token, encrypted at rest via OCP\\Security\\ICrypto — '
								. 'never store the raw token.',
						],
						'scheme' => [
							'type' => 'string',
							'description' => 'Authorization header scheme.',
							'default' => self::DEFAULT_AUTH_SCHEME,
						],
					],
				],
				'onderwerpobject' => [
					'type' => 'object',
					'description' => 'Overrides for the onderwerpobjectidentificator used when linking a case.',
					'properties' => [
						'codeRegister' => [
							'type' => 'string',
							'default' => self::DEFAULT_CODE_REGISTER,
						],
					],
				],
				'pageSize' => [
					'type' => 'integer',
					'description' => 'Klantcontacten pulled per sync sweep.',
					'default' => 100,
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param string|null $since ISO 8601 lower bound (exclusive-by-convention via `__gte`
	 *                           on a strictly-advancing cursor — see design.md "Cursor semantics").
	 * @param integer $pageSize Maximum klantcontacten to return in this call.
	 *
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: string|null}
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function listKlantcontacten(array $sourceConfiguration, ?string $since, int $pageSize): array {
		$query = [
			'expand' => self::DEFAULT_EXPAND,
			'sorteer' => 'registratiedatum',
			'pageSize' => $pageSize,
		];
		if ($since !== null && $since !== '') {
			$query['registratiedatum__gte'] = $since;
		}

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			path: '/klantcontacten',
			query: $query,
			jsonBody: null
		);

		$decoded = json_decode($response, true);
		$results = [];
		if (is_array($decoded) === true) {
			$results = ($decoded['results'] ?? $decoded);
		}

		if (is_array($results) === false) {
			throw new KissProviderException(
				message: 'KISS returned a non-JSON-array response for the klantcontacten list.'
			);
		}

		$results = array_values($results);
		$nextCursor = null;
		foreach ($results as $item) {
			$registrationDate = (string)($item['registratiedatum'] ?? '');
			if ($registrationDate !== '' && ($nextCursor === null || $registrationDate > $nextCursor)) {
				$nextCursor = $registrationDate;
			}
		}

		return ['items' => $results, 'nextCursor' => $nextCursor];
	}//end listKlantcontacten()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param array $payload The klantcontact fields, plus an optional `betrokkene` sub-array.
	 *
	 * @return string The KISS-assigned klantcontact id (`uuid`).
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function createKlantcontact(array $sourceConfiguration, array $payload): string {
		$involvedParty = ($payload['betrokkene'] ?? null);
		unset($payload['betrokkene']);

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'POST',
			path: '/klantcontacten',
			query: [],
			jsonBody: $payload
		);

		$customerContactId = $this->extractId(response: $response, context: 'klantcontact');

		if (is_array($involvedParty) === true && $involvedParty !== []) {
			// Best-effort: a klantcontact with no betrokkene is still a valid
			// partial success, so a betrokkene-creation failure is logged, not
			// propagated (the caller already has a usable klantcontact id).
			try {
				$involvedParty['klantcontact'] = ['uuid' => $customerContactId];
				$this->dispatch(
					sourceConfiguration: $sourceConfiguration,
					method: 'POST',
					path: '/betrokkenen',
					query: [],
					jsonBody: $involvedParty
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[KlantinteractiesClient] betrokkene creation failed; klantcontact was still created',
					['klantcontactId' => $customerContactId, 'exception' => $exception->getMessage()]
				);
			}
		}

		return $customerContactId;
	}//end createKlantcontact()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param string $customerContactId The KISS klantcontact id to attach the link to.
	 * @param string $caseReference The case identifier (bare UUID or zaak identificatie).
	 * @param string $caseObjectType The onderwerpobjectidentificator `codeObjecttype`.
	 *
	 * @return string The KISS-assigned onderwerpobject id (`uuid`).
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function linkOnderwerpobject(
		array $sourceConfiguration,
		string $customerContactId,
		string $caseReference,
		string $caseObjectType,
	): string {
		$codeRegister = (string)($sourceConfiguration['onderwerpobject']['codeRegister'] ?? self::DEFAULT_CODE_REGISTER);

		$payload = [
			'klantcontact' => ['uuid' => $customerContactId],
			'onderwerpobjectidentificator' => [
				'objectId' => $caseReference,
				'codeObjecttype' => $caseObjectType,
				'codeRegister' => $codeRegister,
				'codeSoortObjectId' => $this->resolveKindObjectId(caseReference: $caseReference),
			],
		];

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'POST',
			path: '/onderwerpobjecten',
			query: [],
			jsonBody: $payload
		);

		return $this->extractId(response: $response, context: 'onderwerpobject');
	}//end linkOnderwerpobject()

	/**
	 * Classify a case reference as a UUID or a free-form identificatie, for
	 * `onderwerpobjectidentificator.codeSoortObjectId`.
	 *
	 * @param string $caseReference The case identifier.
	 *
	 * @return string `UUID` when the reference is RFC-4122-shaped, `IDENTIFICATIE` otherwise.
	 */
	private function resolveKindObjectId(string $caseReference): string {
		$isUuid = (preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$caseReference
		) === 1);

		if ($isUuid === true) {
			return 'UUID';
		}

		return 'IDENTIFICATIE';
	}//end resolveSoortObjectId()

	/**
	 * Extract the `uuid` (falling back to `id`) from a created-resource response body.
	 *
	 * @param string $response The raw response body.
	 * @param string $context Human-readable resource name for the error message.
	 *
	 * @return string The extracted id.
	 *
	 * @throws KissProviderException When no usable id is present.
	 */
	private function extractId(string $response, string $context): string {
		$decoded = json_decode($response, true);
		$id = null;
		if (is_array($decoded) === true) {
			$id = ($decoded['uuid'] ?? ($decoded['id'] ?? null));
		}

		if (is_string($id) === false || $id === '') {
			throw new KissProviderException(
				message: 'KISS accepted the ' . $context . ' request but returned no usable id.'
			);
		}

		return $id;
	}//end extractId()

	/**
	 * Dispatch one token-authenticated request and return its raw body,
	 * mapping every failure mode to a secret-free {@see KissProviderException}
	 * — never a 500 crash.
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $path The API path (relative to `configuration.baseUrl`).
	 * @param array $query Query string parameters.
	 * @param array|null $jsonBody Optional JSON request body.
	 *
	 * @return string The response body.
	 *
	 * @throws KissProviderException On any configuration, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, string $method, string $path, array $query, ?array $jsonBody): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		if ($baseUrl === '') {
			throw new KissProviderException(
				message: $this->l->t('KISS base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		$url = $baseUrl . $path;

		$requestOptions = [
			'headers' => [
				'Authorization' => $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration),
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'http_errors' => false,
		];
		if ($query !== []) {
			$requestOptions['query'] = $query;
		}

		if ($jsonBody !== null) {
			$requestOptions['json'] = $jsonBody;
		}

		try {
			$response = $this->httpClient->request($method, $url, $requestOptions);
		} catch (GuzzleException $exception) {
			$this->logger->warning(
				'[KlantinteractiesClient] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new KissProviderException(
				message: 'The KISS request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new KissProviderException(message: 'KISS responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end dispatch()

	/**
	 * Build the per-request `Authorization: <scheme> <token>` header value.
	 *
	 * Decrypts `configuration.authentication.encryptedToken` (never logged,
	 * never persisted decrypted).
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 *
	 * @return string The Authorization header value.
	 *
	 * @throws KissProviderException When the credential is missing or undecryptable.
	 */
	private function buildAuthorizationHeader(array $sourceConfiguration): string {
		$encrypted = (string)($sourceConfiguration['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			throw new KissProviderException(
				message: $this->l->t('KISS credential missing') . ': `configuration.authentication.encryptedToken` '
					. 'is required. No plaintext-token fallback is permitted.'
			);
		}

		try {
			$token = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new KissProviderException(
				message: 'The stored KISS API token could not be decrypted: ' . $exception->getMessage()
			);
		}

		$scheme = (string)($sourceConfiguration['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

		return $scheme . ' ' . $token;
	}//end buildAuthorizationHeader()
}//end class
