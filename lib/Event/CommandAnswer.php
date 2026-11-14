<?php

/**
 * Integriq command answer.
 *
 * The answer half every ADR-041 cross-app command event of integriq carries:
 * whether a listener handled it, the result, or the structured refusal. Each
 * event keeps its own setResult(), which names what its result holds.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * Handled flag, result and refusal of a command event.
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */
trait CommandAnswer {

	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The result, once answered.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * The structured refusal, when the request was refused.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $refusal = null;

	/**
	 * The result, when answered.
	 *
	 * @return array<string,mixed>|null The result.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Record a refusal and mark the request handled.
	 *
	 * @param string $code   A machine-readable code.
	 * @param string $reason A human-readable reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function refuse(string $code, string $reason): void {
		$this->handled = true;
		$this->result  = null;
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end refuse()

	/**
	 * The structured refusal, when there was one.
	 *
	 * @return array<string,string>|null The refusal.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()

	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Record a result and mark the request handled.
	 *
	 * @param array<string,mixed> $result The result.
	 *
	 * @return void
	 */
	private function answer(array $result): void {
		$this->handled = true;
		$this->refusal = null;
		$this->result  = $result;
	}//end answer()
}//end trait
