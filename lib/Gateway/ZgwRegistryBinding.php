<?php

/**
 * Which ZGW registry this deployment reads and writes against.
 *
 * @category Service
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister or another vendor's Zaken API. Whichever it is, the shape a
 * consumer sees is the same, and a binding that points at nothing fails when
 * the administrator tests it rather than the first time somebody opens a case.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-the-zgw-registry-is-a-deployment-binding-req-sg-006
 */
class ZgwRegistryBinding {
	/**
	 * App id for app-config reads and writes.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key holding the binding.
	 */
	public const BINDING_KEY = 'zgw.registry_binding';

	/**
	 * App-config key recording whether the binding has passed its test.
	 */
	public const USABLE_KEY = 'zgw.registry_binding_usable';

	/**
	 * The binding that resolves to OpenRegister on this instance.
	 */
	public const KIND_OPENREGISTER = 'openregister';

	/**
	 * The binding that resolves to somebody else's Zaken API.
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration store.
	 * @param IClientService $clientService HTTP client service, for the reachability test.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured binding.
	 *
	 * @return array<string,mixed> The binding, defaulting to OpenRegister.
	 */
	public function current(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::BINDING_KEY, '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || ($decoded['kind'] ?? '') === '') {
			return ['kind' => self::KIND_OPENREGISTER, 'name' => 'OpenRegister', 'baseUrl' => ''];
		}

		return $decoded;
	}//end current()

	/**
	 * Whether the binding has passed its reachability test.
	 *
	 * @return bool True when it is marked usable.
	 */
	public function isUsable(): bool {
		return ($this->appConfig->getValueString(self::APP_ID, self::USABLE_KEY, '0') === '1');
	}//end isUsable()

	/**
	 * Test a binding's reachability, and mark it usable only when it answers.
	 *
	 * @param array<string,mixed>|null $binding The binding to test, or null for the configured one.
	 *
	 * @return array{ok:bool,message:string,binding:array<string,mixed>} The verdict.
	 */
	public function test(?array $binding = null): array {
		$binding = ($binding ?? $this->current());
		$kind = (string)($binding['kind'] ?? self::KIND_OPENREGISTER);

		if ($kind === self::KIND_OPENREGISTER) {
			$this->markUsable(true);

			return ['ok' => true, 'message' => 'The binding resolves to OpenRegister on this instance.', 'binding' => $binding];
		}

		$baseUrl = (string)($binding['baseUrl'] ?? '');
		if ($baseUrl === '') {
			$this->markUsable(false);

			return ['ok' => false, 'message' => 'An external ZGW binding needs a base URL.', 'binding' => $binding];
		}

		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/zaken',
				['timeout' => 10, 'headers' => ['Accept' => 'application/json']]
			);
			$status = $response->getStatusCode();
		} catch (Throwable $e) {
			$this->markUsable(false);
			$this->logger->warning('zgw.binding.unreachable', ['baseUrl' => $baseUrl, 'error' => $e->getMessage()]);

			return [
				'ok' => false,
				'message' => sprintf('The ZGW registry at "%s" could not be reached: %s.', $baseUrl, $e->getMessage()),
				'binding' => $binding,
			];
		}//end try

		// A 401 is a reachable registry asking for credentials, which is a
		// different problem from a base URL pointing at nothing.
		if ($status >= 500 || $status === 404) {
			$this->markUsable(false);

			return [
				'ok' => false,
				'message' => sprintf('The ZGW registry at "%s" answered HTTP %d.', $baseUrl, $status),
				'binding' => $binding,
			];
		}

		$this->markUsable(true);

		return ['ok' => true, 'message' => sprintf('The ZGW registry at "%s" answered.', $baseUrl), 'binding' => $binding];
	}//end test()

	/**
	 * Record whether the binding may be used.
	 *
	 * @param bool $usable Whether it passed.
	 *
	 * @return void
	 */
	private function markUsable(bool $usable): void {
		$flag = '0';
		if ($usable === true) {
			$flag = '1';
		}

		$this->appConfig->setValueString(self::APP_ID, self::USABLE_KEY, $flag);
	}//end markUsable()
}//end class
