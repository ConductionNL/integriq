<?php

/**
 * Integriq IdpLoginService.
 *
 * The two browser steps of a government login. The start checks who asked and
 * where the browser may come back to, keeps that in a signed single-use
 * state, and hands the browser to the identity provider. The callback reads
 * the assertion, finds the state it answers, runs every guard, mints the
 * envelope and sends the browser back to the consumer with a one-time code.
 *
 * The return address is only ever read from the stored state, never from the
 * callback request. That is what makes the callback impossible to turn into
 * an open redirect: nobody who can reach it can name where it sends the
 * browser.
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
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use Psr\Log\LoggerInterface;

/**
 * Starts a government login and finishes it.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The callback composes every broker guard by design.
 */
class IdpLoginService {

	/**
	 * The one error a consumer is told about, whatever went wrong.
	 *
	 * @var string
	 */
	public const ERROR_LOGIN_FAILED = 'login_failed';

	/**
	 * The longest relay state a consumer may hand in.
	 *
	 * @var integer
	 */
	public const MAX_RELAY_STATE = 1024;

	/**
	 * The subject types each provider may deliver.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const SUBJECT_TYPES = [
		TrustLevelMapper::PROVIDER_DIGID => ['bsn', SubjectPseudonymService::SUBTYPE_BSN_PSEUDONYM],
		TrustLevelMapper::PROVIDER_EHERKENNING => ['kvk', 'rsin'],
		TrustLevelMapper::PROVIDER_EIDAS => ['eidas-person-identifier'],
	];

	/**
	 * Constructor.
	 *
	 * @param IdpBrokerConfig $config The broker's settings.
	 * @param IdpAdapterRegistry $adapters The adapter per provider.
	 * @param IdpLoginStateStore $states The signed single-use states.
	 * @param AssertionGuard $assertionGuard Solicited, audience, window and single use.
	 * @param TrustLevelMapper $trustMapper Maps the assurance level, fail-closed.
	 * @param SubjectPseudonymService $pseudonyms Turns a BSN into a pseudonym.
	 * @param EnvelopeExchangeService $exchange Mints the envelope and issues the code.
	 * @param LoggerInterface $logger Records the real reason for a refusal.
	 */
	public function __construct(
		private readonly IdpBrokerConfig $config,
		private readonly IdpAdapterRegistry $adapters,
		private readonly IdpLoginStateStore $states,
		private readonly AssertionGuard $assertionGuard,
		private readonly TrustLevelMapper $trustMapper,
		private readonly SubjectPseudonymService $pseudonyms,
		private readonly EnvelopeExchangeService $exchange,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Start a login and answer where to send the browser.
	 *
	 * Every refusal throws, and the caller shows integriq's own error page:
	 * before the checks pass there is no address it is safe to send the
	 * browser to.
	 *
	 * @param string $provider The provider id from the route.
	 * @param array<string,mixed> $params `{organisation, consumer, trust, returnUrl, relayState}`.
	 *
	 * @return string The identity provider's address.
	 *
	 * @throws IdpAssertionException When anything about the request or the broker is not in order.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function start(string $provider, array $params): string {
		$organisation = strtolower(trim((string)($params['organisation'] ?? '')));
		$consumerId = trim((string)($params['consumer'] ?? ''));
		$trust = strtolower(trim((string)($params['trust'] ?? '')));
		$returnUrl = (string)($params['returnUrl'] ?? '');
		$relayState = (string)($params['relayState'] ?? '');

		$adapter = $this->adapters->forProvider(provider: $provider);
		$this->assertBrokerUsable();

		$consumer = $this->config->consumer(consumer: $consumerId);
		if ($consumer === null || $consumer->isEnabled() === false) {
			throw new IdpAssertionException(message: 'The consumer is unknown or disabled, so no login starts.');
		}

		if ($consumer->mayReturnTo(returnUrl: $returnUrl) === false) {
			throw new IdpAssertionException(
				message: 'The return address is not registered for this consumer, so no login starts.'
			);
		}

		if ($organisation === '' || in_array($trust, [TrustLevelMapper::TRUST_LOW, TrustLevelMapper::TRUST_SUBSTANTIAL, TrustLevelMapper::TRUST_HIGH], true) === false) {
			throw new IdpAssertionException(message: 'The login names no organisation or no known trust level.');
		}

		if (strlen($relayState) > self::MAX_RELAY_STATE) {
			throw new IdpAssertionException(message: 'The relay state is too long, so no login starts.');
		}

		if ($adapter->isConfigured() === false) {
			throw new IdpAssertionException(
				message: 'No live identity provider is configured for ' . $provider . ', so no login starts.'
			);
		}

		$nonce = $this->states->newNonce();
		$begun = $adapter->beginAuthentication(
			[
				'organisation' => $organisation,
				'consumer' => $consumer->getId(),
				'trust' => $trust,
				'relayState' => $nonce,
			]
		);

		$requestId = trim((string)($begun['requestId'] ?? ''));
		$redirectUrl = trim((string)($begun['redirectUrl'] ?? ''));
		if ($requestId === '' || $redirectUrl === '') {
			throw new IdpAssertionException(message: 'The identity provider adapter gave no request to follow.');
		}

		$this->states->store(
			requestId: $requestId,
			state: [
				'nonce' => $nonce,
				'organisation' => $organisation,
				'consumer' => $consumer->getId(),
				'provider' => $provider,
				'trust' => $trust,
				'returnUrl' => $returnUrl,
				'relayState' => $relayState,
			],
			signingKey: $this->config->signingKey()
		);

		return $redirectUrl;

	}//end start()

	/**
	 * Finish a login and answer where to send the browser.
	 *
	 * Once the state is found every failure still goes back to the consumer,
	 * as one generic error with the relay state, and the real reason is
	 * logged. Before that there is nowhere safe to go, so it throws.
	 *
	 * @param string $provider The provider id from the route.
	 * @param array<string,mixed> $callback What arrived on the callback.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return string The consumer's return address with `code` and `relayState`, or with `error` and `relayState`.
	 *
	 * @throws IdpAssertionException When the response answers no stored state (an IdP-initiated flow included).
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function callback(string $provider, array $callback, ?int $now = null): string {
		$adapter = $this->adapters->forProvider(provider: $provider);
		$assertion = $adapter->readAssertion($callback);
		$requestId = trim((string)($assertion['inResponseTo'] ?? ''));

		// No state, no return address, no code. This is also where an
		// identity-provider-initiated response ends: it answers nothing we
		// asked, so it finds no state.
		$state = $this->states->consume(requestId: $requestId, signingKey: $this->config->signingKey(), now: $now);

		try {
			$code = $this->finish(provider: $provider, assertion: $assertion, requestId: $requestId, state: $state, now: $now);
		} catch (IdpAssertionException $exception) {
			$this->logger->warning(
				'Integriq idp-broker: a login was refused at the callback: ' . $exception->getMessage(),
				['provider' => $provider, 'consumer' => $state['consumer']]
			);

			return $this->withQuery(
				url: $state['returnUrl'],
				query: ['error' => self::ERROR_LOGIN_FAILED, 'relayState' => $state['relayState']]
			);
		}

		return $this->withQuery(url: $state['returnUrl'], query: ['code' => $code, 'relayState' => $state['relayState']]);

	}//end callback()

	/**
	 * Run the guards, mint the envelope and issue the code.
	 *
	 * @param string $provider The provider id from the route.
	 * @param array<string,mixed> $assertion The normalised assertion.
	 * @param string $requestId The request the assertion answers.
	 * @param array<string,string> $state The consumed state.
	 * @param integer|null $now The clock.
	 *
	 * @return string The one-time code.
	 *
	 * @throws IdpAssertionException On any failure.
	 */
	private function finish(string $provider, array $assertion, string $requestId, array $state, ?int $now): string {
		if ($state['provider'] !== $provider) {
			throw new IdpAssertionException(message: 'The response came back on another provider than it started on.');
		}

		// The consumer is read again: an administrator who disabled it, or
		// removed the address, between start and callback is obeyed.
		$consumer = $this->config->consumer(consumer: $state['consumer']);
		if ($consumer === null || $consumer->mayReturnTo(returnUrl: $state['returnUrl']) === false) {
			throw new IdpAssertionException(message: 'The consumer was disabled or its return address removed during the login.');
		}

		$this->assertionGuard->assertAcceptable(
			assertion: $assertion,
			outstandingRequestId: $requestId,
			expectedAudience: $this->config->entityId(provider: $provider),
			now: $now
		);

		$assertedOrganisation = strtolower(trim((string)($assertion['organisation'] ?? '')));
		if ($assertedOrganisation !== '' && $assertedOrganisation !== $state['organisation']) {
			throw new IdpAssertionException(message: 'The assertion names another organisation than the login started for.');
		}

		$trust = $this->trustMapper->map(
			provider: $provider,
			level: (string)($assertion['assuranceLevel'] ?? ''),
			aliases: $this->config->trustAliases(provider: $provider)
		);
		if ($this->trustMapper->satisfies(have: $trust, need: $state['trust']) === false) {
			throw new IdpAssertionException(message: 'The assurance level is below the trust the login asked for.');
		}

		[$subject, $subType] = $this->subjectOf(provider: $provider, assertion: $assertion, organisation: $state['organisation']);

		$envelope = new SubjectEnvelope(
			subject: $subject,
			subType: $subType,
			provider: $provider,
			audience: $consumer->getId(),
			organisation: $state['organisation'],
			trust: $trust,
			branch: $this->branchOf(provider: $provider, assertion: $assertion)
		);

		return $this->exchange->issueCode(envelope: $envelope);

	}//end finish()

	/**
	 * The subject and its type, with a BSN turned into a pseudonym here.
	 *
	 * @param string $provider The provider.
	 * @param array<string,mixed> $assertion The assertion.
	 * @param string $organisation The organisation the login is for.
	 *
	 * @return array{0: string, 1: string} The subject and the subject type.
	 *
	 * @throws IdpAssertionException When the subject type does not belong to the provider, or the subject is empty.
	 */
	private function subjectOf(string $provider, array $assertion, string $organisation): array {
		$subType = trim((string)($assertion['subType'] ?? ''));
		$subject = trim((string)($assertion['subject'] ?? ''));

		if (in_array($subType, (self::SUBJECT_TYPES[$provider] ?? []), true) === false || $subject === '') {
			throw new IdpAssertionException(message: 'The assertion carries no subject this provider may deliver.');
		}

		if ($subType === 'bsn') {
			$pseudonym = $this->pseudonyms->pseudonymFor(
				bsn: $subject,
				organisation: $organisation,
				salt: $this->config->organisationSalt(organisation: $organisation)
			);
			return [$pseudonym, SubjectPseudonymService::SUBTYPE_BSN_PSEUDONYM];
		}

		if ($subType === SubjectPseudonymService::SUBTYPE_BSN_PSEUDONYM) {
			return [$this->pseudonyms->fromPolymorphic(polymorphicPseudonym: $subject), $subType];
		}

		return [$subject, $subType];

	}//end subjectOf()

	/**
	 * The branch an eHerkenning login was restricted to.
	 *
	 * @param string $provider The provider.
	 * @param array<string,mixed> $assertion The assertion.
	 *
	 * @return string The twelve-digit vestigingsnummer, or empty.
	 *
	 * @throws IdpAssertionException When an eHerkenning assertion carries a branch that is not a vestigingsnummer.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004
	 */
	private function branchOf(string $provider, array $assertion): string {
		if ($provider !== TrustLevelMapper::PROVIDER_EHERKENNING) {
			return '';
		}

		$branch = trim((string)($assertion['branch'] ?? ''));
		if ($branch !== '' && preg_match('/^\d{12}$/', $branch) !== 1) {
			// Refused rather than dropped: dropping it would widen a login
			// restricted to one branch into one for the whole company.
			throw new IdpAssertionException(message: 'The assertion restricts the login to a branch that is not a vestigingsnummer.');
		}

		return $branch;

	}//end branchOf()

	/**
	 * Refuse while the broker is off or cannot sign.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the broker is not usable.
	 */
	private function assertBrokerUsable(): void {
		if ($this->config->isEnabled() === false) {
			throw new IdpAssertionException(message: 'Broker not configured: the idp-broker feature flag is off.');
		}

		if (strlen($this->config->signingKey()) < SubjectEnvelopeService::MINIMUM_KEY_BYTES) {
			throw new IdpAssertionException(message: 'Broker not configured: no usable envelope signing key is set.');
		}

	}//end assertBrokerUsable()

	/**
	 * Add query parameters to a registered return address.
	 *
	 * @param string $url The address.
	 * @param array<string,string> $query The parameters.
	 *
	 * @return string The address with the parameters.
	 */
	private function withQuery(string $url, array $query): string {
		$separator = '?';
		if (str_contains($url, '?') === true) {
			$separator = '&';
		}

		return $url . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

	}//end withQuery()

}//end class
