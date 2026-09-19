<?php

/**
 * Integriq Document Render Requested Event.
 *
 * The typed command filinq dispatches to have a vendor render a document.
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Render this template with this data", with a slot for the answer.
 *
 * ADR-041: a typed command with a result slot. The dispatch is synchronous,
 * so the requester reads the job id, or the refusal, off the same instance.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */
class DocumentRenderRequestedEvent extends Event {

	/**
	 * The created job's id, set by integriq when it took the render.
	 *
	 * @var string|null
	 */
	private ?string $jobId = null;

	/**
	 * Why the render was not taken, when it was not.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceId The document generation source to render through.
	 * @param string $templateId The vendor's own template id.
	 * @param array $data The merge data. Integriq hashes it and does not keep it.
	 * @param string $requestedBy The user the render is made for.
	 * @param string $requestedByApp The app asking, normally filinq.
	 */
	public function __construct(
		private readonly string $sourceId,
		private readonly string $templateId,
		private readonly array $data,
		private readonly string $requestedBy = '',
		private readonly string $requestedByApp = 'filinq',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The source to render through.
	 *
	 * @return string The source id.
	 */
	public function getSourceId(): string {
		return $this->sourceId;

	}//end getSourceId()

	/**
	 * The vendor's template id.
	 *
	 * @return string The template id.
	 */
	public function getTemplateId(): string {
		return $this->templateId;

	}//end getTemplateId()

	/**
	 * The merge data.
	 *
	 * @return array<string, mixed> The data.
	 */
	public function getData(): array {
		return $this->data;

	}//end getData()

	/**
	 * Who the render is for.
	 *
	 * @return string The user id.
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;

	}//end getRequestedBy()

	/**
	 * Which app asked.
	 *
	 * @return string The app id.
	 */
	public function getRequestedByApp(): string {
		return $this->requestedByApp;

	}//end getRequestedByApp()

	/**
	 * The job integriq created, or null when it created none.
	 *
	 * @return string|null The job id.
	 */
	public function getJobId(): ?string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * Record the job integriq created.
	 *
	 * @param string $jobId The job id.
	 *
	 * @return void
	 */
	public function setJobId(string $jobId): void {
		$this->jobId = $jobId;

	}//end setJobId()

	/**
	 * The structured refusal, or null when the render was taken.
	 *
	 * @return array<string, mixed>|null The refusal.
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Refuse the render, saying why.
	 *
	 * @param string $reason What an operator can act on.
	 * @param string $code A machine-readable code (`source-unknown`, `provider-unknown`, `not-configured`).
	 *
	 * @return void
	 */
	public function refuse(string $reason, string $code): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end refuse()
}//end class
