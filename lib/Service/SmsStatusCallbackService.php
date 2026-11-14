<?php

/**
 * Integriq SMS status callback service.
 *
 * Takes a vendor delivery callback from CM.com, MessageBird or Twilio to the
 * `sms_message` it names. The callback URL carries a per-source token, which
 * picks the source and is compared in constant time before anything else is
 * read; the binding then checks the vendor's signature where the vendor signs,
 * and a checked status goes to {@see SmsDispatchService::handleStatusCallback()},
 * which leaves an unknown message id unchanged.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\Integriq\Service\Sms\SmsStatusCallbackInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Checks a vendor's SMS status callback and applies it.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsStatusCallbackService {

	/**
	 * Configuration key of a source's callback token.
	 *
	 * @var string
	 */
	public const TOKEN_KEY = 'statusCallbackToken';

	/**
	 * Configuration key of a source's encrypted callback signing key.
	 *
	 * @var string
	 */
	public const SIGNING_KEY = 'encryptedWebhookSigningKey';

	/**
	 * Most SMS sources one callback looks through for its token.
	 *
	 * @var int
	 */
	private const SOURCE_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService     $objectService     Finds the SMS sources.
	 * @param RawSourceResolver   $rawSourceResolver Re-reads the matched source raw.
	 * @param SmsProviderRegistry $providers         The SMS bindings by provider id.
	 * @param SmsDispatchServiceInterface $dispatch          Applies a checked status to its message.
	 * @param ICrypto             $crypto            Decrypts the signing key for the instant of the check.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly RawSourceResolver $rawSourceResolver,
		private readonly SmsProviderRegistry $providers,
		private readonly SmsDispatchServiceInterface $dispatch,
		private readonly ICrypto $crypto,
	) {

	}//end __construct()

	/**
	 * The enabled SMS source of a provider whose callback token is the given one.
	 *
	 * Every candidate is compared in constant time, and a binding that reads
	 * no callbacks never matches.
	 *
	 * @param string $provider The provider id from the callback URL.
	 * @param string $token    The token from the callback URL.
	 *
	 * @return ObjectEntity|null The source, raw, or null when none matches.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function sourceFor(string $provider, string $token): ?ObjectEntity {
		if ($token === '' || ($this->providers->resolve(providerId: $provider) instanceof SmsStatusCallbackInterface) === false) {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => RawSourceResolver::REGISTER,
					'schema' => RawSourceResolver::SCHEMA,
					'type' => SmsDispatchService::SOURCE_TYPE,
					'isEnabled' => true,
				],
				'limit' => self::SOURCE_LIMIT,
			]
		);
		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return null;
		}

		$found = null;
		foreach ($results as $candidate) {
			if ($candidate instanceof ObjectEntity && $found === null && self::answersTo(source: $candidate, provider: $provider, token: $token) === true) {
				$found = $candidate;
			}
		}

		if ($found === null) {
			return null;
		}

		return $this->rawSourceResolver->resolveRaw(source: $found);
	}//end sourceFor()

	/**
	 * Check a callback through the source's binding and apply the status it reports.
	 *
	 * @param ObjectEntity      $source   The source the token picked.
	 * @param SmsStatusCallback $callback The callback, without its signing key.
	 *
	 * @return ObjectEntity|null The updated message, or null when the callback names a message nobody sent.
	 *
	 * @throws SmsProviderException With code `bad_signature` when the vendor's signature does not verify,
	 *                              or `unreadable_callback` when the callback names no message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function receive(ObjectEntity $source, SmsStatusCallback $callback): ?ObjectEntity {
		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$binding = $this->providers->resolve(providerId: (string)($configuration['provider'] ?? ''));
		if (($binding instanceof SmsStatusCallbackInterface) === false) {
			throw new SmsProviderException(message: 'This source reads no status callbacks.', errorCode: 'bad_signature');
		}

		$signingKey = null;
		if ($binding->signsStatusCallbacks() === true) {
			$signingKey = $this->signingKey(configuration: $configuration);
		}

		$result = $binding->readStatusCallback(
			sourceConfiguration: $configuration,
			callback: new SmsStatusCallback(
				url: $callback->url,
				rawBody: $callback->rawBody,
				params: $callback->params,
				headers: $callback->headers,
				signingKey: $signingKey
			)
		);

		return $this->dispatch->handleStatusCallback(
			providerMessageId: $result->providerMessageId,
			status: $result->status,
			detail: $result->detail
		);
	}//end receive()

	/**
	 * Whether a source is of the provider and carries the token.
	 *
	 * @param ObjectEntity $source   The candidate.
	 * @param string       $provider The provider id.
	 * @param string       $token    The token.
	 *
	 * @return bool True on a match.
	 */
	private static function answersTo(ObjectEntity $source, string $provider, string $token): bool {
		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false || ($configuration['provider'] ?? null) !== $provider) {
			return false;
		}

		$stored = ($configuration[self::TOKEN_KEY] ?? null);
		if (is_string($stored) === false || $stored === '') {
			return false;
		}

		return hash_equals($stored, $token);

	}//end answersTo()

	/**
	 * The source's signing key, decrypted, or null when it stores none or it does not decrypt.
	 *
	 * @param array<string, mixed> $configuration The source's configuration.
	 *
	 * @return string|null The key.
	 */
	private function signingKey(array $configuration): ?string {
		$encrypted = ($configuration[self::SIGNING_KEY] ?? null);
		if (is_string($encrypted) === false || $encrypted === '') {
			return null;
		}

		try {
			return $this->crypto->decrypt($encrypted);
		} catch (Throwable) {
			return null;
		}

	}//end signingKey()
}//end class
