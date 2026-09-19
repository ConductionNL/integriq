<?php

/**
 * The on-premise bridges that reach systems behind a firewall.
 *
 * @category Service
 * @package  OCA\Integriq\Bridge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Bridge;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * A bridge opens the connection from inside the customer's network, so
 * nothing has to be opened inbound. It authenticates itself with a token it
 * was issued, and an administrator can revoke it, after which it stops
 * answering and every call over it fails naming the revocation.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-on-premise-bridge-reaches-a-system-behind-the-firewall-req-sg-007
 */
class BridgeRegistry {
	/**
	 * App id for app-config reads and writes.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key holding the registered bridges.
	 */
	public const BRIDGES_KEY = 'bridge.registered';

	/**
	 * A bridge that is connected and may carry traffic.
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * A bridge an administrator revoked.
	 */
	public const STATE_REVOKED = 'revoked';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration store.
	 * @param ISecureRandom $random Secure random, for the bridge token.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every registered bridge, keyed by id.
	 *
	 * @return array<string,array<string,mixed>> The bridges.
	 */
	public function all(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::BRIDGES_KEY, '{}');
		$decoded = json_decode($raw, true);

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end all()

	/**
	 * Register a bridge and issue it a token.
	 *
	 * @param string $bridgeId The bridge id.
	 * @param string $label What it is called.
	 *
	 * @return array<string,mixed> The bridge, with its token. The token is
	 *                             returned once, here, and stored hashed.
	 */
	public function register(string $bridgeId, string $label = ''): array {
		$token = $this->random->generate(64);
		$bridges = $this->all();

		$displayLabel = $bridgeId;
		if ($label !== '') {
			$displayLabel = $label;
		}

		$bridges[$bridgeId] = [
			'id' => $bridgeId,
			'label' => $displayLabel,
			'state' => self::STATE_ACTIVE,
			'tokenHash' => hash('sha256', $token),
			'registeredAt' => gmdate('c'),
			'revokedAt' => null,
		];

		$this->store(bridges: $bridges);
		$this->logger->info('bridge.registered', ['bridge' => $bridgeId]);

		return ($bridges[$bridgeId] + ['token' => $token]);
	}//end register()

	/**
	 * Revoke a bridge.
	 *
	 * @param string $bridgeId The bridge id.
	 *
	 * @return bool True when a bridge was revoked.
	 */
	public function revoke(string $bridgeId): bool {
		$bridges = $this->all();
		if (isset($bridges[$bridgeId]) === false) {
			return false;
		}

		$bridges[$bridgeId]['state'] = self::STATE_REVOKED;
		$bridges[$bridgeId]['revokedAt'] = gmdate('c');
		$this->store(bridges: $bridges);
		$this->logger->warning('bridge.revoked', ['bridge' => $bridgeId]);

		return true;
	}//end revoke()

	/**
	 * Whether a bridge may carry traffic right now.
	 *
	 * @param string $bridgeId The bridge id.
	 *
	 * @return bool True when it is registered and not revoked.
	 */
	public function isActive(string $bridgeId): bool {
		$bridge = ($this->all()[$bridgeId] ?? null);

		return ($bridge !== null && (string)($bridge['state'] ?? '') === self::STATE_ACTIVE);
	}//end isActive()

	/**
	 * Whether a token belongs to an active bridge.
	 *
	 * @param string $bridgeId The bridge id.
	 * @param string $token The token the bridge presented.
	 *
	 * @return bool True when the bridge authenticates.
	 */
	public function authenticates(string $bridgeId, string $token): bool {
		$bridge = ($this->all()[$bridgeId] ?? null);
		if ($bridge === null || (string)($bridge['state'] ?? '') !== self::STATE_ACTIVE) {
			return false;
		}

		return hash_equals((string)($bridge['tokenHash'] ?? ''), hash('sha256', $token));
	}//end authenticates()

	/**
	 * Persist the bridges.
	 *
	 * @param array<string,array<string,mixed>> $bridges The bridges.
	 *
	 * @return void
	 */
	private function store(array $bridges): void {
		$encoded = json_encode($bridges);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->appConfig->setValueString(self::APP_ID, self::BRIDGES_KEY, $encoded);
	}//end store()
}//end class
