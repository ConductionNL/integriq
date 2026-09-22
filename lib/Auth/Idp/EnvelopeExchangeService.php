<?php

/**
 * Integriq EnvelopeExchangeService.
 *
 * The server-to-server half of the hand-off. A consumer presents the code it
 * received through the browser redirect together with its own shared secret,
 * and gets the signed envelope back exactly once.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use Psr\Log\LoggerInterface;

/**
 * Issues and redeems the one-time envelope codes.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */
class EnvelopeExchangeService {

	/**
	 * Constructor.
	 *
	 * @param IdpBrokerConfig $config The broker's tenant-wide settings.
	 * @param SubjectEnvelopeService $envelopeService Mints and verifies envelopes.
	 * @param EnvelopeCodeStore $codeStore Holds an envelope behind a code.
	 * @param LoggerInterface $logger Records a refused redemption.
	 */
	public function __construct(
		private readonly IdpBrokerConfig $config,
		private readonly SubjectEnvelopeService $envelopeService,
		private readonly EnvelopeCodeStore $codeStore,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Mint an envelope and put it behind a fresh code.
	 *
	 * @param SubjectEnvelope $envelope The verified subject.
	 *
	 * @return string The one-time code to put in the redirect.
	 *
	 * @throws IdpAssertionException When the broker is off, unkeyed, or has no shared cache.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function issueCode(SubjectEnvelope $envelope): string {
		$this->assertEnabled();

		$token = $this->envelopeService->mint(
			envelope: $envelope,
			signingKey: $this->config->signingKey()
		);

		return $this->codeStore->issue(envelopeToken: $token, consumer: $envelope->getAudience());

	}//end issueCode()

	/**
	 * Redeem one code for its envelope.
	 *
	 * Every failure below produces the same exception type and reaches the
	 * caller as one undifferentiated refusal, because a consumer that can
	 * tell "unknown code" from "wrong secret" can probe for either.
	 *
	 * @param string $code The one-time code.
	 * @param string $consumer The consumer redeeming it.
	 * @param string $presentedSecret The consumer's shared secret.
	 *
	 * @return string The signed envelope.
	 *
	 * @throws IdpAssertionException On any failure.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function redeemCode(string $code, string $consumer, string $presentedSecret): string {
		$this->assertEnabled();
		$this->assertConsumer(consumer: $consumer, presentedSecret: $presentedSecret);

		$token = $this->codeStore->redeem(code: $code, consumer: $consumer);

		// Verified here as well as signed here. The envelope has been out of
		// our hands for as long as the browser held the code, and verifying
		// it now is what catches an expiry, a `use` claim that is not ours,
		// and a second redemption of the same jti.
		$this->envelopeService->verify(
			token: $token,
			signingKey: $this->config->signingKey(),
			audience: $consumer
		);

		return $token;

	}//end redeemCode()

	/**
	 * Refuse everything while the broker is off or unkeyed.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the broker is not usable.
	 */
	private function assertEnabled(): void {
		if ($this->config->isEnabled() === false) {
			throw new IdpAssertionException(
				message: 'Broker not configured: the idp-broker feature flag is off.'
			);
		}

		if ($this->config->signingKey() === '') {
			throw new IdpAssertionException(
				message: 'Broker not configured: no envelope signing key is set.'
			);
		}

	}//end assertEnabled()

	/**
	 * Refuse a consumer that cannot prove who it is.
	 *
	 * @param string $consumer The consumer id.
	 * @param string $presentedSecret The secret it presented.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the consumer is unknown or the secret does not match.
	 */
	private function assertConsumer(string $consumer, string $presentedSecret): void {
		$expected = $this->config->consumerSecret(consumer: $consumer);

		// An unknown consumer is compared against a random string of the same
		// shape rather than short-circuiting, so the timing of "unknown
		// consumer" and "wrong secret" is the same.
		$comparison = $expected;
		if ($comparison === '') {
			$comparison = bin2hex(random_bytes(32));
		}

		if ($expected === '' || hash_equals($comparison, $presentedSecret) === false) {
			$this->logger->warning(
				'Integriq idp-broker: an envelope exchange was refused.',
				['consumer' => $consumer]
			);

			throw new IdpAssertionException(
				message: 'The exchange request is refused.'
			);
		}

	}//end assertConsumer()

}//end class
