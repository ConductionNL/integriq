<?php

/**
 * Integriq KISS Klantinteracties Client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Thin REST binding for {@see KlantinteractiesProviderInterface} against the
 * VNG "Klantinteracties API" (the OpenKlant 2.x reference model that KISS —
 * the open-source Common Ground Klantinteractie Servicesysteem built by
 * Utrecht + Dimpact — implements and exposes). Deliberately a hand-rolled
 * HTTP client (Guzzle, already an app dependency) rather than an SDK
 * dependency — the contract is a handful of REST resources
 * (`klantcontacten`, `betrokkenen`, `onderwerpobjecten`) behind one token
 * auth scheme, mirroring {@see \OCA\Integriq\Service\Sms\RestNotifyNlProvider}
 * and {@see \OCA\Integriq\Service\Peppol\RestPeppolAccessPointProvider}.
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
 * exactly the "v1 scope" {@see \OCA\Integriq\Service\BrokeredCallService}
 * targets (see the Peppol `rest` provider's `credentialRef` precedent); this
 * client uses direct `ICrypto` storage instead, mirroring the
 * notifynl-sms-channel leaf, so the KISS bridge has no hard dependency on the
 * optional OpenRegister credential-broker class and stays self-contained and
 * unit-testable exactly like the other provider bindings in this app.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\KissProviderException;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST KISS/Klantinteracties provider: token-authenticated list/create/link.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class KlantinteractiesClient implements KlantinteractiesProviderInterface, CallerSearchInterface {

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
	 * Most parties read for one number. Two already make the caller
	 * ambiguous, so a few more only prove the point; the cap keeps a number
	 * shared by a switchboard from fanning out into a request per party.
	 */
	public const MAX_CALLER_CANDIDATES = 5;

	/**
	 * Klantcontacten read for a party's case references.
	 */
	public const CASE_CONTACTS_PAGE_SIZE = 50;

	/**
	 * A uuid, the only party id a request path is built from.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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
	public function listCustomerContacts(array $sourceConfiguration, ?string $since, int $pageSize): array {
		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			request: new KissRequest(method: 'GET', path: '/klantcontacten', query: $this->listQuery(since: $since, pageSize: $pageSize))
		);
		$results = $this->listResults(response: $response);

		return ['items' => $results, 'nextCursor' => $this->latestRegistrationDate(results: $results)];
	}//end listCustomerContacts()

	/**
	 * The klantcontacten list query: expanded, sorted by registration date, paged, and from `since` when given.
	 *
	 * @param string|null $since    The cursor; null or '' for the first page.
	 * @param int         $pageSize The page size.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function listQuery(?string $since, int $pageSize): array {
		$query = [
			'expand' => self::DEFAULT_EXPAND,
			'sorteer' => 'registratiedatum',
			'pageSize' => $pageSize,
		];
		if ($since !== null && $since !== '') {
			$query['registratiedatum__gte'] = $since;
		}

		return $query;

	}//end listQuery()

	/**
	 * The listed klantcontacten: a page's `results`, else the decoded array itself.
	 *
	 * @param string $response The response body.
	 *
	 * @return array<int, mixed> The items, re-indexed.
	 *
	 * @throws KissProviderException When the list is not an array.
	 */
	private function listResults(string $response): array {
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

		return array_values($results);

	}//end listResults()

	/**
	 * The latest `registratiedatum` among the items: the next page's cursor.
	 *
	 * @param array<int, mixed> $results The listed items.
	 *
	 * @return string|null The cursor, or null when no item carries a date.
	 */
	private function latestRegistrationDate(array $results): ?string {
		$nextCursor = null;
		foreach ($results as $item) {
			$registrationDate = (string)($item['registratiedatum'] ?? '');
			if ($this->isLaterDate(date: $registrationDate, cursor: $nextCursor) === true) {
				$nextCursor = $registrationDate;
			}
		}

		return $nextCursor;

	}//end latestRegistrationDate()

	/**
	 * Whether a registration date moves the cursor: it is set and later than the cursor so far.
	 *
	 * @param string      $date   The item's `registratiedatum`, '' when absent.
	 * @param string|null $cursor The latest date so far, null for none.
	 *
	 * @return bool True when the date is the new cursor.
	 */
	private function isLaterDate(string $date, ?string $cursor): bool {
		return ($date !== '' && ($cursor === null || $date > $cursor));

	}//end isLaterDate()

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
	public function createCustomerContact(array $sourceConfiguration, array $payload): string {
		$involvedParty = ($payload['involvedParty'] ?? null);
		unset($payload['involvedParty']);

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			request: new KissRequest(method: 'POST', path: '/klantcontacten', jsonBody: $payload)
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
					request: new KissRequest(method: 'POST', path: '/betrokkenen', jsonBody: $involvedParty)
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[KlantinteractiesClient] betrokkene creation failed; klantcontact was still created',
					['klantcontactId' => $customerContactId, 'exception' => $exception->getMessage()]
				);
			}
		}

		return $customerContactId;
	}//end createCustomerContact()

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
			request: new KissRequest(method: 'POST', path: '/onderwerpobjecten', jsonBody: $payload)
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
	 * @param array       $sourceConfiguration The KISS source's `configuration` object.
	 * @param KissRequest $request             The method, path (relative to `configuration.baseUrl`), query and JSON body.
	 *
	 * @return string The response body.
	 *
	 * @throws KissProviderException On any configuration, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, KissRequest $request): string {
		$url = $this->requireBaseUrl(sourceConfiguration: $sourceConfiguration) . $request->path;
		$requestOptions = $this->requestOptions(sourceConfiguration: $sourceConfiguration, request: $request);

		try {
			$response = $this->httpClient->request($request->method, $url, $requestOptions);
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

		return $this->successfulBody(response: $response);
	}//end dispatch()

	/**
	 * The configured base URL without a trailing slash; it is required.
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 *
	 * @return string The base URL.
	 *
	 * @throws KissProviderException When none is configured.
	 */
	private function requireBaseUrl(array $sourceConfiguration): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		if ($baseUrl === '') {
			throw new KissProviderException(
				message: $this->l->t('KISS base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		return $baseUrl;

	}//end requireBaseUrl()

	/**
	 * The Guzzle options: token and JSON headers, and the query and body when the request has them.
	 *
	 * @param array       $sourceConfiguration The KISS source's `configuration` object.
	 * @param KissRequest $request             The request.
	 *
	 * @return array The options.
	 *
	 * @throws KissProviderException When the token is missing or undecryptable.
	 */
	private function requestOptions(array $sourceConfiguration, KissRequest $request): array {
		$requestOptions = [
			'headers' => [
				'Authorization' => $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration),
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'http_errors' => false,
		];
		if ($request->query !== []) {
			$requestOptions['query'] = $request->query;
		}

		if ($request->jsonBody !== null) {
			$requestOptions['json'] = $request->jsonBody;
		}

		return $requestOptions;

	}//end requestOptions()

	/**
	 * The body of a 2xx answer; any other status is refused.
	 *
	 * @param ResponseInterface $response The answer.
	 *
	 * @return string The body.
	 *
	 * @throws KissProviderException When the status is not 2xx.
	 */
	private function successfulBody(ResponseInterface $response): string {
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new KissProviderException(message: 'KISS responded with HTTP ' . $status . '.');
		}

		return $body;

	}//end successfulBody()

	/**
	 * {@inheritDoc}
	 *
	 * Reads `GET /digitaleadressen?adres=<e164>` and then each party that
	 * provided one of those addresses, with the addresses attached as its
	 * `digitaleAdressen`. ASSUMED API SHAPE, like the rest of this client:
	 * the `adres` filter and `verstrektDoorPartij.uuid` are the Klantinteracties
	 * names. Whether a party really carries the number is decided by
	 * {@see CallerLookup}, exactly, so a backend that ignores the filter and
	 * returns more addresses than asked still matches nobody wrongly.
	 *
	 * @param array  $sourceConfiguration The KISS source's `configuration` object.
	 * @param string $e164                The caller's number in E.164.
	 *
	 * @return array<int, array<string, mixed>> The candidate parties.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findPartiesByPhoneNumber(array $sourceConfiguration, string $e164): array {
		$addresses = $this->listResults(
			response: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				request: new KissRequest(method: 'GET', path: '/digitaleadressen', query: ['adres' => $e164])
			)
		);

		$parties = [];
		foreach ($this->addressesByParty(addresses: $addresses) as $partyId => $partyAddresses) {
			$party = json_decode(
				$this->dispatch(
					sourceConfiguration: $sourceConfiguration,
					request: new KissRequest(method: 'GET', path: '/partijen/'.$partyId)
				),
				true
			);
			if (is_array($party) === false) {
				continue;
			}

			$party['digitaleAdressen'] = $partyAddresses;
			$parties[] = $party;
		}

		return $parties;

	}//end findPartiesByPhoneNumber()

	/**
	 * The listed addresses grouped by the party that provided them.
	 *
	 * An address no party provided, or whose party id is not a uuid, is left
	 * out: it names nobody, and a party id goes into a request path.
	 *
	 * @param array<int, mixed> $addresses The listed digitale adressen.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The addresses by party uuid, at most MAX_CALLER_CANDIDATES parties.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	private function addressesByParty(array $addresses): array {
		$byParty = [];
		foreach ($addresses as $address) {
			if (is_array($address) === false) {
				continue;
			}

			$partyId = (string) (($address['verstrektDoorPartij'] ?? [])['uuid'] ?? '');
			if (preg_match(self::UUID_PATTERN, $partyId) !== 1) {
				continue;
			}

			if (isset($byParty[$partyId]) === false && count($byParty) >= self::MAX_CALLER_CANDIDATES) {
				continue;
			}

			$byParty[$partyId][] = $address;
		}

		return $byParty;

	}//end addressesByParty()

	/**
	 * {@inheritDoc}
	 *
	 * Reads the party's klantcontacten, newest first, with their
	 * onderwerpobjecten, and returns each distinct case reference in the
	 * order first met. A case reference is the first onderwerpobject whose
	 * `codeObjecttype` contains `zaak`, the REQ-003 rule. Klantinteracties
	 * holds no case status, so a reference is a case this party contacted
	 * about; whether it is still open is the case system's to say.
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param array $party               The matched party.
	 *
	 * @return array<int, string> The case references, newest first.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findOpenCasesForParty(array $sourceConfiguration, array $party): array {
		$partyId = (string) ($party['uuid'] ?? '');
		if (preg_match(self::UUID_PATTERN, $partyId) !== 1) {
			return [];
		}

		$contacts = $this->listResults(
			response: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				request: new KissRequest(
					method: 'GET',
					path: '/klantcontacten',
					query: [
						'hadBetrokkene__wasPartij__uuid' => $partyId,
						'expand'                         => 'onderwerpobjecten',
						'sorteer'                        => '-plaatsgevondenOp',
						'pageSize'                       => self::CASE_CONTACTS_PAGE_SIZE,
					]
				)
			)
		);

		$references = [];
		foreach ($this->newestFirst(contacts: $contacts) as $contact) {
			$reference = $this->caseReferenceOf(contact: $contact);
			if ($reference !== null && in_array($reference, $references, true) === false) {
				$references[] = $reference;
			}
		}

		return $references;

	}//end findOpenCasesForParty()

	/**
	 * The klantcontacten sorted by `plaatsgevondenOp`, newest first.
	 *
	 * Sorted here as well as asked of the server, so a backend that ignores
	 * `sorteer` still yields newest first.
	 *
	 * @param array<int, mixed> $contacts The listed klantcontacten.
	 *
	 * @return array<int, array<string, mixed>> The array items, newest first.
	 */
	private function newestFirst(array $contacts): array {
		$items = array_values(array_filter($contacts, 'is_array'));
		usort(
			$items,
			static fn (array $a, array $b): int => strcmp(
				(string) ($b['plaatsgevondenOp'] ?? ''),
				(string) ($a['plaatsgevondenOp'] ?? '')
			)
		);

		return $items;

	}//end newestFirst()

	/**
	 * A klantcontact's case reference: the first onderwerpobject whose
	 * `codeObjecttype` contains `zaak` (REQ-003), or null.
	 *
	 * @param array<string, mixed> $contact The klantcontact.
	 *
	 * @return string|null The case reference.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md#requirement-mapping-onderwerpobjecten-to-a-case-reference-req-003
	 */
	private function caseReferenceOf(array $contact): ?string {
		foreach ((array) ($contact['onderwerpobjecten'] ?? []) as $subject) {
			$identificator = (array) (((array) $subject)['onderwerpobjectidentificator'] ?? []);
			$objectId      = (string) ($identificator['objectId'] ?? '');
			$type          = strtolower((string) ($identificator['codeObjecttype'] ?? ''));
			if ($objectId !== '' && str_contains($type, self::DEFAULT_CASE_OBJECT_TYPE) === true) {
				return $objectId;
			}
		}

		return null;

	}//end caseReferenceOf()

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
