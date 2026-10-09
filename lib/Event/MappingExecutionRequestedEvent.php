<?php

/**
 * Integriq Mapping Execution Requested Event.
 *
 * The typed command a sibling app dispatches to run an integriq mapping by slug.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Run this mapping on this input", with a slot for the answer.
 *
 * ADR-041: a typed command with a result slot. The dispatch is synchronous,
 * so the requester reads the output, or the refusal, off the same instance.
 * Refusal codes: `not-found` (no mapping has this slug), `not-allowed` (the
 * mapping's callableBy does not list the requesting app) and `failed` (the
 * mapping ran and threw).
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 */
class MappingExecutionRequestedEvent extends Event {

	/**
	 * The mapped output, set by integriq when it ran the mapping.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $output = null;

	/**
	 * Why integriq did not run it, when it did not.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $mappingSlug The slug of the mapping to run.
	 * @param array<string,mixed> $input The input the mapping reads.
	 * @param string $sourceApp The app id asking, checked against callableBy.
	 * @param string $correlationId The requester's id for this run, echoed in logs.
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function __construct(
		private readonly string $mappingSlug,
		private readonly array $input,
		private readonly string $sourceApp,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The slug of the mapping to run.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getMappingSlug(): string {
		return $this->mappingSlug;
	}//end getMappingSlug()

	/**
	 * The input the mapping reads.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getInput(): array {
		return $this->input;
	}//end getInput()

	/**
	 * The app id asking.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The requester's id for this run.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Record the mapped output.
	 *
	 * @param array<string,mixed> $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function setOutput(array $output): void {
		$this->output = $output;
	}//end setOutput()

	/**
	 * The mapped output, or null when the mapping did not run.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getOutput(): ?array {
		return $this->output;
	}//end getOutput()

	/**
	 * Whether integriq ran the mapping and set an output.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function isHandled(): bool {
		return $this->output !== null;
	}//end isHandled()

	/**
	 * Refuse the request.
	 *
	 * @param string $reason What the requester is told.
	 * @param string $code   not-found, not-allowed or failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
	 */
	public function refuse(string $reason, string $code): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end refuse()

	/**
	 * The refusal, or null when there was none.
	 *
	 * @return array{code: string, reason: string}|null
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
