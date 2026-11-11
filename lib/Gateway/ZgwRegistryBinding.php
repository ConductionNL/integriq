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
class ZgwRegistryBinding implements ZgwRegistryBindingInterface {
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
			return $this->verdict(binding: $binding, outcome: ['ok' => true, 'message' => 'The binding resolves to OpenRegister on this instance.']);
		}

		$baseUrl = (string)($binding['baseUrl'] ?? '');
		if ($baseUrl === '') {
			return $this->verdict(binding: $binding, outcome: ['ok' => false, 'message' => 'An external ZGW binding needs a base URL.']);
		}

		return $this->probe(binding: $binding, baseUrl: $baseUrl);
	}//end test()

	/**
	 * Ask an external registry for its zaken and judge the answer.
	 *
	 * @param array  $binding The binding.
	 * @param string $baseUrl Its base URL.
	 *
	 * @return array{ok: bool, message: string, binding: array} The verdict.
	 */
	private function probe(array $binding, string $baseUrl): array {
		try {
			$response = $this->clientService->newClient()->get(
				rtrim($baseUrl, '/') . '/zaken',
				['timeout' => 10, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (Throwable $e) {
			return $this->unreachable(binding: $binding, failure: ['baseUrl' => $baseUrl, 'error' => $e->getMessage()]);
		}

		return $this->verdict(binding: $binding, outcome: $this->statusOutcome(baseUrl: $baseUrl, status: $response->getStatusCode()));
	}//end probe()

	/**
	 * Judge an unreachable registry not usable, and log it.
	 *
	 * @param array                              $binding The binding tested.
	 * @param array{baseUrl: string, error: string} $failure Its base URL and why it could not be reached.
	 *
	 * @return array{ok: bool, message: string, binding: array} The verdict.
	 */
	private function unreachable(array $binding, array $failure): array {
		$verdict = $this->verdict(
			binding: $binding,
			outcome: ['ok' => false, 'message' => sprintf('The ZGW registry at "%s" could not be reached: %s.', $failure['baseUrl'], $failure['error'])]
		);
		$this->logger->warning('zgw.binding.unreachable', $failure);

		return $verdict;
	}//end unreachable()

	/**
	 * What a registry's status says.
	 *
	 * A 401 is a reachable registry asking for credentials, which is a
	 * different problem from a base URL pointing at nothing.
	 *
	 * @param string $baseUrl The registry's base URL.
	 * @param int    $status  The status it answered.
	 *
	 * @return array{ok: bool, message: string} Not usable for a 404 or a 5xx, usable otherwise.
	 */
	private function statusOutcome(string $baseUrl, int $status): array {
		if ($status >= 500 || $status === 404) {
			return ['ok' => false, 'message' => sprintf('The ZGW registry at "%s" answered HTTP %d.', $baseUrl, $status)];
		}

		return ['ok' => true, 'message' => sprintf('The ZGW registry at "%s" answered.', $baseUrl)];
	}//end statusOutcome()

	/**
	 * Record whether the binding is usable and answer the verdict.
	 *
	 * @param array                            $binding The binding tested.
	 * @param array{ok: bool, message: string} $outcome Whether it is usable, and why.
	 *
	 * @return array{ok: bool, message: string, binding: array} The verdict.
	 */
	private function verdict(array $binding, array $outcome): array {
		$this->markUsable(usable: $outcome['ok']);

		return ['ok' => $outcome['ok'], 'message' => $outcome['message'], 'binding' => $binding];
	}//end verdict()

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
