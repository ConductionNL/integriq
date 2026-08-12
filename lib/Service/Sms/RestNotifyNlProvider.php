<?php

/**
 * OpenConnector REST NotifyNL SMS Provider.
 *
 * Thin REST binding for {@see SmsProviderInterface} against NotifyNL — the NL
 * government notification service, a GOV.UK Notify fork exposing the same
 * `POST /v2/notifications/sms` + `GET /v2/notifications/{id}` REST contract
 * and the same JWT authentication scheme: every request carries
 * `Authorization: Bearer <jwt>` where the JWT is freshly signed per request
 * (HS256, payload `{iss: <serviceId>, iat: <now>}`) using the second half of
 * the API key as the HMAC secret. Deliberately a hand-rolled HTTP client
 * (Guzzle, already an app dependency) rather than a `notifynl`/`gov-uk-notify`
 * SDK dependency — the contract is two endpoints and one auth scheme.
 *
 * CREDENTIAL STORAGE — why this does NOT use {@see \OCA\OpenConnector\Service\BrokeredCallService}
 * (the Peppol/PSD2 `credentialRef` precedent): verified against OpenRegister's
 * `CredentialBrokerService::injectAuth()` at HEAD, the broker's auth injection
 * is a STATIC single-placeholder substitution (`str_replace('{secret}', ...)`)
 * that unconditionally discards any caller-supplied Authorization header —
 * it can forward a raw, static secret verbatim but cannot compute a derived,
 * time-bound value. NotifyNL's real API (verified against the documented
 * GOV.UK Notify JWT contract it forks) requires exactly such a computed,
 * per-request JWT — a shape `BrokeredCallService` cannot express, for the same
 * structural reason its `assertScopeGuards()` already excludes SOAP/async/
 * TLS-client-cert sources from `credentialRef`'s "v1 scope" (computed/derived
 * auth material, not a static bearer substitution). The API key is therefore
 * stored `configuration.authentication.encryptedApiKey`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto` (AES + HMAC, keyed off the instance
 * secret) — never cleartext — and decrypted in-process only for the instant
 * needed to sign each request's JWT (never logged, never persisted decrypted).
 * This is a deliberate, better-than-existing-precedent choice: this app's own
 * LTI signing keys are documented as "plaintext-pending-encryption" tech debt
 * (see `LtiKeyService`); NotifyNL's key does not repeat that debt.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Sms
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Sms;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenConnector\Exception\SmsProviderException;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST NotifyNL provider: JWT-signed template sends + status polling.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
class RestNotifyNlProvider implements SmsProviderInterface {

	/**
	 * Default NotifyNL API base URL, used when `configuration.baseUrl` is absent.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://api.notifynl.nl';

	/**
	 * Length in characters of one UUID segment of a NotifyNL API key.
	 *
	 * @var integer
	 */
	private const UUID_LENGTH = 36;

	/**
	 * Minimum total length of a well-formed `<name>-<serviceId>-<secret>` API key
	 * (two 36-char UUID segments plus the separating dash).
	 *
	 * @var integer
	 */
	private const MIN_API_KEY_LENGTH = (self::UUID_LENGTH * 2) + 1;

	/**
	 * Notification statuses reported by NotifyNL that map to the normalised `sent` status.
	 *
	 * @var string[]
	 */
	private const NOTIFY_STATUS_SENT = ['created', 'sending', 'pending'];

	/**
	 * Notification statuses reported by NotifyNL that map to the normalised `failed` status.
	 *
	 * @var string[]
	 */
	private const NOTIFY_STATUS_FAILED = ['permanent-failure', 'temporary-failure', 'technical-failure', 'cancelled'];

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param ICrypto $crypto Encrypts/decrypts the stored API key at rest.
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
	 * @return string The stable `notifynl` provider identifier.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getProviderId(): string {
		return 'notifynl';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider display name.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getProviderName(): string {
		return 'NotifyNL';
	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The NotifyNL source configuration JSON Schema.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['authentication'],
			'properties' => [
				'baseUrl' => [
					'type' => 'string',
					'description' => 'NotifyNL API base URL',
					'default' => self::DEFAULT_BASE_URL,
				],
				'authentication' => [
					'type' => 'object',
					'required' => ['encryptedApiKey'],
					'properties' => [
						'encryptedApiKey' => [
							'type' => 'string',
							'description' => 'The NotifyNL API key (`<name>-<serviceId>-<secret>`), encrypted at '
								. 'rest via OCP\\Security\\ICrypto — never store the raw key.',
						],
					],
				],
				'senderId' => [
					'type' => 'string',
					'description' => 'Optional `sms_sender_id` registered with NotifyNL (custom sender name/number).',
				],
				'templateMapping' => [
					'type' => 'object',
					'description' => 'Logical template name -> NotifyNL templateId, so callers reference '
						. 'a stable local name instead of a NotifyNL UUID.',
					'additionalProperties' => ['type' => 'string'],
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The NotifyNL source's `configuration` object.
	 * @param string $to The recipient in E.164 format.
	 * @param string $body Unused for the wire call (NotifyNL is template-only) — audit context only.
	 * @param array $options `templateId` (required, or a key resolvable via `templateMapping`) and
	 *                       `personalisation` (optional key/value map merged into the template).
	 *
	 * @return DeliveryResult The accepted `queued` result carrying NotifyNL's `id`.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		$templateId = $this->resolveTemplateId(sourceConfiguration: $sourceConfiguration, options: $options);

		$personalisation = ($options['personalisation'] ?? []);
		if (is_array($personalisation) === false) {
			$personalisation = [];
		}

		$payload = [
			'phone_number' => $to,
			'template_id' => $templateId,
			'personalisation' => $personalisation,
		];

		$senderId = (string)($sourceConfiguration['senderId'] ?? '');
		if ($senderId !== '') {
			$payload['sms_sender_id'] = $senderId;
		}

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'POST',
			path: '/v2/notifications/sms',
			jsonBody: $payload
		);

		$decoded = json_decode($response, true);
		$notificationId = null;
		if (is_array($decoded) === true) {
			$notificationId = ($decoded['id'] ?? null);
		}

		if (is_string($notificationId) === false || $notificationId === '') {
			throw new SmsProviderException(
				message: 'NotifyNL accepted the request but returned no usable notification id.'
			);
		}

		return new DeliveryResult(providerMessageId: $notificationId, status: 'queued', detail: null);
	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The NotifyNL source's `configuration` object.
	 * @param string $providerMessageId NotifyNL's notification `id` (from {@see send()}).
	 *
	 * @return DeliveryResult The current normalised status.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult {
		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'GET',
			path: '/v2/notifications/' . rawurlencode($providerMessageId),
			jsonBody: null
		);

		$decoded = json_decode($response, true);
		if (is_array($decoded) === false) {
			throw new SmsProviderException(
				message: 'NotifyNL returned a non-JSON response for the status lookup.'
			);
		}

		$notifyStatus = (string)($decoded['status'] ?? '');

		$detail = $notifyStatus;
		if ($notifyStatus === '') {
			$detail = null;
		}

		return new DeliveryResult(
			providerMessageId: $providerMessageId,
			status: $this->mapNotifyStatus(notifyStatus: $notifyStatus),
			detail: $detail
		);

	}//end fetchStatus()

	/**
	 * Resolve the NotifyNL templateId from `options.templateId`, falling back to
	 * `configuration.templateMapping[options.templateId]` when the caller passed a logical name.
	 *
	 * @param array $sourceConfiguration The NotifyNL source's `configuration` object.
	 * @param array $options The caller-supplied send options.
	 *
	 * @return string The resolved NotifyNL templateId.
	 *
	 * @throws SmsProviderException When no usable templateId can be resolved.
	 */
	private function resolveTemplateId(array $sourceConfiguration, array $options): string {
		$requested = (string)($options['templateId'] ?? '');
		if ($requested === '') {
			throw new SmsProviderException(
				message: $this->l->t('NotifyNL requires options.templateId') . ' (the GOV.UK-Notify-style template '
					. 'send contract has no free-text body endpoint).'
			);
		}

		$mapping = ($sourceConfiguration['templateMapping'] ?? []);
		if (is_array($mapping) === true && isset($mapping[$requested]) === true) {
			return (string)$mapping[$requested];
		}

		return $requested;
	}//end resolveTemplateId()

	/**
	 * Map a raw NotifyNL notification status to the app's normalised {@see DeliveryResult::STATUSES}.
	 *
	 * @param string $notifyStatus The raw NotifyNL status string.
	 *
	 * @return string One of {@see DeliveryResult::STATUSES}.
	 */
	private function mapNotifyStatus(string $notifyStatus): string {
		if ($notifyStatus === 'delivered') {
			return 'delivered';
		}

		if (in_array($notifyStatus, self::NOTIFY_STATUS_FAILED, true) === true) {
			return 'failed';
		}

		if (in_array($notifyStatus, self::NOTIFY_STATUS_SENT, true) === true) {
			return 'sent';
		}

		return 'queued';
	}//end mapNotifyStatus()

	/**
	 * Dispatch one JWT-authenticated request and return its raw body, mapping every
	 * failure mode to a secret-free {@see SmsProviderException} — never a 500 crash.
	 *
	 * @param array $sourceConfiguration The NotifyNL source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $path The API path (relative to `configuration.baseUrl`).
	 * @param array|null $jsonBody Optional JSON request body.
	 *
	 * @return string The response body.
	 *
	 * @throws SmsProviderException On any configuration, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, string $method, string $path, ?array $jsonBody): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
		$url = $baseUrl . $path;

		$jwt = $this->buildAuthorizationJwt(sourceConfiguration: $sourceConfiguration);

		$requestOptions = [
			'headers' => [
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type' => 'application/json',
			],
			'http_errors' => false,
		];
		if ($jsonBody !== null) {
			$requestOptions['json'] = $jsonBody;
		}

		try {
			$response = $this->httpClient->request($method, $url, $requestOptions);
		} catch (GuzzleException $exception) {
			$this->logger->warning(
				'[RestNotifyNlProvider] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new SmsProviderException(
				message: 'The NotifyNL request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new SmsProviderException(message: 'NotifyNL responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end dispatch()

	/**
	 * Build the per-request `Authorization: Bearer <jwt>` value.
	 *
	 * Decrypts `configuration.authentication.encryptedApiKey` (never logged, never
	 * persisted decrypted) and splits it into `<name>-<serviceId>-<secret>` using
	 * the same suffix-based approach as GOV.UK Notify's own reference clients
	 * (the two UUID segments are always exactly 36 characters, so the `name`
	 * segment — which may itself contain dashes — is never split on).
	 *
	 * @param array $sourceConfiguration The NotifyNL source's `configuration` object.
	 *
	 * @return string The compact-serialised HS256 JWT.
	 *
	 * @throws SmsProviderException When the credential is missing, undecryptable, or malformed.
	 */
	private function buildAuthorizationJwt(array $sourceConfiguration): string {
		$encrypted = (string)($sourceConfiguration['authentication']['encryptedApiKey'] ?? '');
		if ($encrypted === '') {
			throw new SmsProviderException(
				message: $this->l->t('NotifyNL credential missing') . ': `configuration.authentication.'
					. 'encryptedApiKey` is required. No plaintext-key fallback is permitted.'
			);
		}

		try {
			$apiKey = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new SmsProviderException(
				message: 'The stored NotifyNL API key could not be decrypted: ' . $exception->getMessage()
			);
		}

		if (strlen($apiKey) < self::MIN_API_KEY_LENGTH) {
			throw new SmsProviderException(
				message: 'The stored NotifyNL API key is malformed (expected `<name>-<serviceId>-<secret>`).'
			);
		}

		$serviceId = substr($apiKey, -self::MIN_API_KEY_LENGTH, self::UUID_LENGTH);
		$secret = substr($apiKey, -self::UUID_LENGTH);

		return $this->signJwt(serviceId: $serviceId, secret: $secret);
	}//end buildAuthorizationJwt()

	/**
	 * Sign a fresh `{iss, iat}` HS256 JWT with the given secret (never logged).
	 *
	 * Mirrors AuthenticationService::getHSJWK()/generateJWT() — same
	 * `web-token/jwt-framework` primitives already used elsewhere in this app,
	 * so no new signing dependency is introduced.
	 *
	 * @param string $serviceId The NotifyNL service id (`iss` claim; not secret material).
	 * @param string $secret The raw HMAC signing secret (the API key's second UUID segment).
	 *
	 * @return string The compact-serialised JWT.
	 */
	private function signJwt(string $serviceId, string $secret): string {
		$base64url = rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');
		$jwk = new JWK(['kty' => 'oct', 'k' => $base64url]);

		$algorithmManager = new AlgorithmManager([new HS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$jwsSerializer = new CompactSerializer();

		$payload = json_encode(
			[
				'iss' => $serviceId,
				'iat' => (new DateTimeImmutable())->getTimestamp(),
			]
		);

		$jws = $jwsBuilder
			->create()
			->withPayload($payload)
			->addSignature($jwk, ['alg' => 'HS256', 'typ' => 'JWT'])
			->build();

		return $jwsSerializer->serialize($jws, 0);
	}//end signJwt()
}//end class
