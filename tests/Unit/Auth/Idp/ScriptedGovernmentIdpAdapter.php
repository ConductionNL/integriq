<?php

/**
 * A scripted identity provider for the broker tests.
 *
 * It lives under tests/ on purpose and nowhere under lib/: an adapter that
 * answers a fixed assertion is a login as anybody, and the only safe place
 * for one is a place no production container can reach.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;

/**
 * Answers a scripted assertion for whatever request it last began.
 */
class ScriptedGovernmentIdpAdapter implements GovernmentIdpAdapterInterface {

	/**
	 * The contexts beginAuthentication() was called with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $begun = [];

	/**
	 * The last request id handed out.
	 *
	 * @var string
	 */
	public string $lastRequestId = '';

	/**
	 * Constructor.
	 *
	 * @param string $providerId The provider this adapter stands in for.
	 * @param array<string,mixed> $assertion The assertion to answer, without `inResponseTo`.
	 * @param boolean $configured Whether it claims a live broker.
	 */
	public function __construct(
		private readonly string $providerId,
		public array $assertion = [],
		private readonly bool $configured = true,
	) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string The id.
	 */
	public function getProviderId(): string {
		return $this->providerId;
	}//end getProviderId()

	/**
	 * Whether it claims a live broker.
	 *
	 * @return boolean The flag.
	 */
	public function isConfigured(): bool {
		return $this->configured;
	}//end isConfigured()

	/**
	 * Hand out a request id and an identity provider address.
	 *
	 * @param array<string,mixed> $context The context.
	 *
	 * @return array{requestId: string, redirectUrl: string} The request.
	 */
	public function beginAuthentication(array $context): array {
		$this->begun[] = $context;
		$this->lastRequestId = '_' . bin2hex(random_bytes(16));

		return [
			'requestId' => $this->lastRequestId,
			'redirectUrl' => 'https://idp.example.nl/sso?RelayState=' . rawurlencode((string)$context['relayState']),
		];
	}//end beginAuthentication()

	/**
	 * Answer the scripted assertion, as a response to `inResponseTo` in the callback.
	 *
	 * @param array<string,mixed> $callback The callback parameters.
	 *
	 * @return array<string,mixed> The assertion.
	 */
	public function readAssertion(array $callback): array {
		return array_merge($this->assertion, ['inResponseTo' => (string)($callback['inResponseTo'] ?? '')]);
	}//end readAssertion()

}//end class
