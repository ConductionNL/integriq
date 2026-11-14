<?php

/**
 * Integriq REST NotifyNL SMS Provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * CREDENTIAL STORAGE — why this does NOT use {@see \OCA\Integriq\Service\BrokeredCallService}
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
 * @package  OCA\Integriq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Exception\SmsProviderException;
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
		$payload = [
			'phone_number' => $to,
			'template_id' => $this->resolveTemplateId(sourceConfiguration: $sourceConfiguration, options: $options),
			'personalisation' => self::personalisation(options: $options),
		] + self::sender(sourceConfiguration: $sourceConfiguration);

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			call: new NotifyCall(method: 'POST', path: '/v2/notifications/sms', jsonBody: $payload)
		);

		return new DeliveryResult(providerMessageId: self::notificationId(response: $response), status: 'queued', detail: null);
	}//end send()

	/**
	 * Send one e-mail through NotifyNL's `/v2/notifications/email`.
	 *
	 * The template id may be a logical name the source maps through
	 * `templateMapping`. A non-empty reference is sent as NotifyNL's
	 * `reference`, which NotifyNL hands back on its delivery callback. The
	 * source's `emailReplyToId`, when set, names the reply-to address.
	 *
	 * @param array  $sourceConfiguration The NotifyNL source's `configuration` object.
	 * @param string $emailAddress        The recipient address (validated by the caller).
	 * @param string $templateId          The NotifyNL template id, or a name `templateMapping` maps.
	 * @param array  $personalisation     The template's personalisation values.
	 * @param string $reference           The caller's own reference, or empty.
	 *
	 * @return DeliveryResult The accepted `queued` result carrying NotifyNL's `id`.
	 *
	 * @throws SmsProviderException When no template resolves, or NotifyNL is unreachable or refuses.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function sendEmail(
		array $sourceConfiguration,
		string $emailAddress,
		string $templateId,
		array $personalisation,
		string $reference = '',
	): DeliveryResult {
		$payload = [
			'email_address' => $emailAddress,
			'template_id' => $this->resolveTemplateId(sourceConfiguration: $sourceConfiguration, options: ['templateId' => $templateId]),
			'personalisation' => $personalisation,
		];
		if ($reference !== '') {
			$payload['reference'] = $reference;
		}

		$replyTo = (string)($sourceConfiguration['emailReplyToId'] ?? '');
		if ($replyTo !== '') {
			$payload['email_reply_to_id'] = $replyTo;
		}

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			call: new NotifyCall(method: 'POST', path: '/v2/notifications/email', jsonBody: $payload)
		);

		return new DeliveryResult(providerMessageId: self::notificationId(response: $response), status: 'queued', detail: null);
	}//end sendEmail()

	/**
	 * The template personalisation the options carry, or none.
	 *
	 * @param array $options The send options.
	 *
	 * @return array The personalisation.
	 */
	private static function personalisation(array $options): array {
		$personalisation = ($options['personalisation'] ?? []);
		if (is_array($personalisation) === false) {
			return [];
		}

		return $personalisation;
	}//end personalisation()

	/**
	 * The sender id field, when the source names one.
	 *
	 * @param array $sourceConfiguration The source's configuration.
	 *
	 * @return array{sms_sender_id?: string} The field, or nothing.
	 */
	private static function sender(array $sourceConfiguration): array {
		$senderId = (string)($sourceConfiguration['senderId'] ?? '');
		if ($senderId === '') {
			return [];
		}

		return ['sms_sender_id' => $senderId];
	}//end sender()

	/**
	 * The notification id NotifyNL answered a send with.
	 *
	 * @param string $response The answer body.
	 *
	 * @return string The id.
	 *
	 * @throws SmsProviderException When there is no usable id.
	 */
	private static function notificationId(string $response): string {
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

		return $notificationId;
	}//end notificationId()

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
			call: new NotifyCall(method: 'GET', path: '/v2/notifications/' . rawurlencode($providerMessageId))
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
	 * Send one authenticated call and return its body, refusing a transport failure or a non-2xx answer.
	 *
	 * @param array      $sourceConfiguration The source's configuration.
	 * @param NotifyCall $call                The method, path and optional JSON body.
	 *
	 * @return string The answer body.
	 *
	 * @throws SmsProviderException On a credential, transport or status failure.
	 */
	private function dispatch(array $sourceConfiguration, NotifyCall $call): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
		$url = $baseUrl . $call->path;

		$requestOptions = $this->requestOptions(sourceConfiguration: $sourceConfiguration, call: $call);

		try {
			$response = $this->httpClient->request($call->method, $url, $requestOptions);
		} catch (GuzzleException $exception) {
			throw $this->transportFailure(exception: $exception);
		}

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new SmsProviderException(message: 'NotifyNL responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end dispatch()

	/**
	 * The request options: the signed bearer token, the JSON content type and the body.
	 *
	 * @param array      $sourceConfiguration The source's configuration.
	 * @param NotifyCall $call                The call.
	 *
	 * @return array The Guzzle options.
	 *
	 * @throws SmsProviderException When the API key is missing, undecryptable or malformed.
	 */
	private function requestOptions(array $sourceConfiguration, NotifyCall $call): array {
		$jwt = $this->buildAuthorizationJwt(sourceConfiguration: $sourceConfiguration);

		$requestOptions = [
			'headers' => [
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type' => 'application/json',
			],
			'http_errors' => false,
		];
		if ($call->jsonBody !== null) {
			$requestOptions['json'] = $call->jsonBody;
		}

		return $requestOptions;
	}//end requestOptions()

	/**
	 * Log a transport failure and wrap it.
	 *
	 * @param GuzzleException $exception The failure.
	 *
	 * @return SmsProviderException The exception to throw.
	 */
	private function transportFailure(GuzzleException $exception): SmsProviderException {
		$this->logger->warning(
			'[RestNotifyNlProvider] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new SmsProviderException(
			message: 'The NotifyNL request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);
	}//end transportFailure()

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
