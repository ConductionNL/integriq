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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpAssuranceBelowFloorException;
use Psr\Log\LoggerInterface;

/**
 * Starts a government login and finishes it.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The callback composes every broker guard by design.
 */
class IdpLoginService implements IdpLoginServiceInterface {

	/**
	 * The one error a consumer is told about, whatever went wrong.
	 *
	 * @var string
	 */
	public const ERROR_LOGIN_FAILED = 'login_failed';

	/**
	 * The error a consumer is told about when the login was below the floor.
	 *
	 * The one refusal that is not generic: the person has already been told
	 * why on integriq's own page, and the consumer may resume from it.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public const ERROR_ASSURANCE_BELOW_FLOOR = 'assurance_below_floor';

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
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function start(string $provider, array $params): string {
		$login = new IdpLoginStart(provider: $provider, organisation: strtolower(trim((string)($params['organisation'] ?? ''))));
		$login->consumer = trim((string)($params['consumer'] ?? ''));
		$login->trust = strtolower(trim((string)($params['trust'] ?? '')));
		$login->returnUrl = (string)($params['returnUrl'] ?? '');
		$login->relayState = (string)($params['relayState'] ?? '');

		$adapter = $this->adapters->forProvider(provider: $provider);
		$this->assertBrokerUsable();

		$login->consumer = $this->startableConsumer(consumerId: $login->consumer, returnUrl: $login->returnUrl)->getId();
		$this->assertStartParameters(login: $login);

		if ($adapter->isConfigured() === false) {
			throw new IdpAssertionException(
				message: 'No live identity provider is configured for ' . $provider . ', so no login starts.'
			);
		}

		$login->nonce = $this->states->newNonce();
		[$requestId, $redirectUrl] = $this->beginAt(adapter: $adapter, login: $login);
		$this->storeState(login: $login, requestId: $requestId);

		return $redirectUrl;

	}//end start()

	/**
	 * Send the login to the identity provider adapter and read what to follow.
	 *
	 * @param GovernmentIdpAdapterInterface $adapter The provider's adapter.
	 * @param IdpLoginStart                 $login   The login being started.
	 *
	 * @return array{0: string, 1: string} The request id and the identity provider's address.
	 *
	 * @throws IdpAssertionException When the adapter gave no request to follow.
	 */
	private function beginAt(GovernmentIdpAdapterInterface $adapter, IdpLoginStart $login): array {
		$begun = $adapter->beginAuthentication(
			[
				'organisation' => $login->organisation,
				'consumer' => $login->consumer,
				'trust' => $login->trust,
				'relayState' => $login->nonce,
			]
		);

		$requestId = trim((string)($begun['requestId'] ?? ''));
		$redirectUrl = trim((string)($begun['redirectUrl'] ?? ''));
		if ($requestId === '' || $redirectUrl === '') {
			throw new IdpAssertionException(message: 'The identity provider adapter gave no request to follow.');
		}

		return [$requestId, $redirectUrl];

	}//end beginAt()

	/**
	 * Store the signed, single-use state the callback will consume.
	 *
	 * @param IdpLoginStart $login     The login being started.
	 * @param string        $requestId The request the identity provider will answer.
	 *
	 * @return void
	 */
	private function storeState(IdpLoginStart $login, string $requestId): void {
		$this->states->store(
			requestId: $requestId,
			state: [
				'nonce' => $login->nonce,
				'organisation' => $login->organisation,
				'consumer' => $login->consumer,
				'provider' => $login->provider,
				'trust' => $login->trust,
				'returnUrl' => $login->returnUrl,
				'relayState' => $login->relayState,
			],
			signingKey: $this->config->signingKey()
		);

	}//end storeState()

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
	 * @throws IdpAssuranceBelowFloorException When the assertion is below the floor; it carries the consumer's
	 *                                         return address with `error=assurance_below_floor` and the relay state.
	 * @throws IdpAssertionException When the response answers no stored state (an IdP-initiated flow included).
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function callback(string $provider, array $callback, ?int $now = null): string {
		$adapter = $this->adapters->forProvider(provider: $provider);
		$assertion = $adapter->readAssertion($callback);
		$requestId = trim((string)($assertion['inResponseTo'] ?? ''));

		// No state, no return address, no code. This is also where an
		// identity-provider-initiated response ends: it answers nothing we
		// asked, so it finds no state.
		$state = $this->states->consume(requestId: $requestId, signingKey: $this->config->signingKey(), now: $now);

		$login = new IdpLoginCallback(provider: $provider, assertion: $assertion);
		$login->requestId = $requestId;
		$login->state = $state;
		$login->now = $now;

		try {
			$code = $this->finish(login: $login);
		} catch (IdpAssuranceBelowFloorException $exception) {
			$this->logger->warning(
				'Integriq idp-broker: a login was refused at the callback: ' . $exception->getMessage(),
				['provider' => $provider, 'consumer' => $state['consumer'], 'level' => $exception->getLevelUsed()]
			);

			throw $exception->withReturnUrl(
				returnUrl: $this->withQuery(
					url: $state['returnUrl'],
					query: ['error' => self::ERROR_ASSURANCE_BELOW_FLOOR, 'relayState' => $state['relayState']]
				)
			);
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
	 * @param IdpLoginCallback $login The login being finished.
	 *
	 * @return string The one-time code.
	 *
	 * @throws IdpAssertionException On any failure.
	 */
	private function finish(IdpLoginCallback $login): string {
		if ($login->state['provider'] !== $login->provider) {
			throw new IdpAssertionException(message: 'The response came back on another provider than it started on.');
		}

		$consumer = $this->returningConsumer(login: $login);

		$this->assertionGuard->assertAcceptable(
			assertion: $login->assertion,
			outstandingRequestId: $login->requestId,
			expectedAudience: $this->config->entityId(provider: $login->provider),
			now: $login->now
		);

		$this->assertSameOrganisation(login: $login);
		$login->trust = $this->sufficientTrust(login: $login);

		return $this->exchange->issueCode(envelope: $this->envelopeFor(login: $login, consumer: $consumer));

	}//end finish()

	/**
	 * The consumer, read again: an administrator who disabled it, or removed
	 * the address, between start and callback is obeyed.
	 *
	 * @param IdpLoginCallback $login The login being finished.
	 *
	 * @return IdpConsumer The consumer.
	 *
	 * @throws IdpAssertionException When the consumer was disabled or its return address removed.
	 */
	private function returningConsumer(IdpLoginCallback $login): IdpConsumer {
		$consumer = $this->config->consumer(consumer: $login->state['consumer']);
		if ($consumer === null || $consumer->mayReturnTo(returnUrl: $login->state['returnUrl']) === false) {
			throw new IdpAssertionException(message: 'The consumer was disabled or its return address removed during the login.');
		}

		return $consumer;

	}//end returningConsumer()

	/**
	 * Refuse an assertion that names another organisation than the login started for.
	 *
	 * @param IdpLoginCallback $login The login being finished.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the organisations differ.
	 */
	private function assertSameOrganisation(IdpLoginCallback $login): void {
		$assertedOrganisation = strtolower(trim((string)($login->assertion['organisation'] ?? '')));
		if ($assertedOrganisation !== '' && $assertedOrganisation !== $login->state['organisation']) {
			throw new IdpAssertionException(message: 'The assertion names another organisation than the login started for.');
		}

	}//end assertSameOrganisation()

	/**
	 * The trust the assertion carries, when it meets the trust the login asked for.
	 *
	 * @param IdpLoginCallback $login The login being finished.
	 *
	 * @return string The mapped trust.
	 *
	 * @throws IdpAssuranceBelowFloorException When the assurance level is below the floor, whatever the start asked.
	 * @throws IdpAssertionException When the assurance level is below the trust asked for.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	private function sufficientTrust(IdpLoginCallback $login): string {
		$level = (string)($login->assertion['assuranceLevel'] ?? '');
		$aliases = $this->config->trustAliases(provider: $login->provider);
		$trust = $this->trustMapper->map(provider: $login->provider, level: $level, aliases: $aliases);
		if ($this->trustMapper->meetsFloor(trust: $trust) === false) {
			throw new IdpAssuranceBelowFloorException(
				provider: $login->provider,
				levelUsed: $this->trustMapper->canonicalLevel(level: $level, aliases: $aliases)
			);
		}

		if ($this->trustMapper->satisfies(have: $trust, need: $login->state['trust']) === false) {
			throw new IdpAssertionException(message: 'The assurance level is below the trust the login asked for.');
		}

		return $trust;

	}//end sufficientTrust()

	/**
	 * The subject envelope the code is issued for.
	 *
	 * @param IdpLoginCallback $login    The login being finished, with its trust mapped.
	 * @param IdpConsumer      $consumer The consumer the browser returns to.
	 *
	 * @return SubjectEnvelope The envelope.
	 *
	 * @throws IdpAssertionException When the subject or the branch is unusable.
	 */
	private function envelopeFor(IdpLoginCallback $login, IdpConsumer $consumer): SubjectEnvelope {
		[$subject, $subType] = $this->subjectOf(login: $login);

		return new SubjectEnvelope(
			subject: $subject,
			subType: $subType,
			provider: $login->provider,
			audience: $consumer->getId(),
			organisation: $login->state['organisation'],
			trust: $login->trust,
			branch: $this->branchOf(provider: $login->provider, assertion: $login->assertion)
		);

	}//end envelopeFor()

	/**
	 * The subject and its type, with a BSN turned into a pseudonym here.
	 *
	 * @param IdpLoginCallback $login The login being finished.
	 *
	 * @return array{0: string, 1: string} The subject and the subject type.
	 *
	 * @throws IdpAssertionException When the subject type does not belong to the provider, or the subject is empty.
	 */
	private function subjectOf(IdpLoginCallback $login): array {
		$organisation = $login->state['organisation'];
		$subType = trim((string)($login->assertion['subType'] ?? ''));
		$subject = trim((string)($login->assertion['subject'] ?? ''));

		if (in_array($subType, (self::SUBJECT_TYPES[$login->provider] ?? []), true) === false || $subject === '') {
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
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004
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
	 * The consumer, when it may start a login that returns to this address.
	 *
	 * @param string $consumerId The consumer id.
	 * @param string $returnUrl The return address asked for.
	 *
	 * @return IdpConsumer The consumer.
	 *
	 * @throws IdpAssertionException When the consumer is unknown or disabled, or the address is not registered.
	 */
	private function startableConsumer(string $consumerId, string $returnUrl): IdpConsumer {
		$consumer = $this->config->consumer(consumer: $consumerId);
		if ($consumer === null || $consumer->isEnabled() === false) {
			throw new IdpAssertionException(message: 'The consumer is unknown or disabled, so no login starts.');
		}

		if ($consumer->mayReturnTo(returnUrl: $returnUrl) === false) {
			throw new IdpAssertionException(
				message: 'The return address is not registered for this consumer, so no login starts.'
			);
		}

		return $consumer;

	}//end startableConsumer()

	/**
	 * Refuse a start without an organisation, with an unknown trust level, one below the floor, or an
	 * oversized relay state.
	 *
	 * @param IdpLoginStart $login The login being started.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When a parameter is unusable.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	private function assertStartParameters(IdpLoginStart $login): void {
		$knownTrust = [TrustLevelMapper::TRUST_LOW, TrustLevelMapper::TRUST_SUBSTANTIAL, TrustLevelMapper::TRUST_HIGH];
		if ($login->organisation === '' || in_array($login->trust, $knownTrust, true) === false) {
			throw new IdpAssertionException(message: 'The login names no organisation or no known trust level.');
		}

		if ($this->trustMapper->meetsFloor(trust: $login->trust) === false) {
			throw new IdpAssertionException(message: 'The login asks for a trust level below the floor of substantial, so no login starts.');
		}

		if (strlen($login->relayState) > self::MAX_RELAY_STATE) {
			throw new IdpAssertionException(message: 'The relay state is too long, so no login starts.');
		}

	}//end assertStartParameters()

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
