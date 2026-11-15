<?php

/**
 * Integriq ServiceFetchRequested Event.
 *
 * The ADR-041 cross-app command "run this service fetch with these inputs".
 * An app names a fetch an administrator defined in integriq, by slug, with
 * the input values it has, and receives the fetch's declared outputs or a
 * named refusal. The app never sees the source, its credential, the source
 * location or the raw answer.
 *
 * The listener never throws to the dispatcher. A consumer MUST guard the
 * dispatch with class_exists() and treat an unhandled event as a refusal.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A request to run one service fetch, with its result slot.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchRequestedEvent extends Event {

	/**
	 * The fetch's slug is unknown, or the fetch is disabled.
	 */
	public const UNKNOWN_FETCH = 'unknown-fetch';

	/**
	 * The calling app is not in the fetch's allowed apps.
	 */
	public const NOT_ALLOWED = 'not-allowed';

	/**
	 * A required input is missing or an input has the wrong type.
	 */
	public const INVALID_INPUT = 'invalid-input';

	/**
	 * The source refused, answered 5xx, or its circuit breaker is open.
	 */
	public const SOURCE_UNAVAILABLE = 'source-unavailable';

	/**
	 * The output mapping failed or left a required output out.
	 */
	public const MAPPING_FAILED = 'mapping-failed';

	/**
	 * The source did not answer within the fetch's timeout.
	 */
	public const TIMEOUT = 'timeout';

	/**
	 * Whether a listener answered the request.
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The declared outputs, when the fetch succeeded.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $outputs = null;

	/**
	 * When the outputs were fetched, ISO 8601.
	 *
	 * @var string|null
	 */
	private ?string $fetchedAt = null;

	/**
	 * The refusal code, when the fetch was refused.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * What the refusal is about, such as the name of an invalid input.
	 *
	 * @var string|null
	 */
	private ?string $refusalDetail = null;

	/**
	 * Constructor.
	 *
	 * @param string               $app    The calling app's id.
	 * @param string               $fetch  The service fetch's slug.
	 * @param array<string, mixed> $inputs The input values, by input name.
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $fetch,
		private readonly array $inputs = [],
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The calling app's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getApp(): string {
		return $this->app;
	}//end getApp()

	/**
	 * The service fetch's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getFetch(): string {
		return $this->fetch;
	}//end getFetch()

	/**
	 * The input values, by input name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getInputs(): array {
		return $this->inputs;
	}//end getInputs()

	/**
	 * Answer the request with the fetch's declared outputs.
	 *
	 * @param array<string, mixed> $outputs   The outputs.
	 * @param string               $fetchedAt When they were fetched, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function setOutputs(array $outputs, string $fetchedAt): void {
		$this->outputs = $outputs;
		$this->fetchedAt = $fetchedAt;
		$this->refusal = null;
		$this->refusalDetail = null;
		$this->handled = true;
	}//end setOutputs()

	/**
	 * Answer the request with a refusal.
	 *
	 * @param string      $refusal One of the refusal constants.
	 * @param string|null $detail  What the refusal is about, such as an input name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function refuse(string $refusal, ?string $detail = null): void {
		$this->outputs = null;
		$this->fetchedAt = null;
		$this->refusal = $refusal;
		$this->refusalDetail = $detail;
		$this->handled = true;
	}//end refuse()

	/**
	 * Whether a listener answered the request.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The result as the spec describes it: `{ok: true, outputs, fetchedAt}`
	 * or `{ok: false, refusal, detail}`, or null when nothing answered.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getResult(): ?array {
		if ($this->handled === false) {
			return null;
		}

		if ($this->refusal !== null) {
			return ['ok' => false, 'refusal' => $this->refusal, 'detail' => $this->refusalDetail];
		}

		return ['ok' => true, 'outputs' => $this->outputs, 'fetchedAt' => $this->fetchedAt];
	}//end getResult()

	/**
	 * The refusal code, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getRefusal(): ?string {
		return $this->refusal;
	}//end getRefusal()

	/**
	 * What the refusal is about, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getRefusalDetail(): ?string {
		return $this->refusalDetail;
	}//end getRefusalDetail()

	/**
	 * The outputs, or null when the fetch was refused or not answered.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function getOutputs(): ?array {
		return $this->outputs;
	}//end getOutputs()
}//end class
