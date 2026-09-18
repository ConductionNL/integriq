<?php

/**
 * Integriq Document Rendered Event.
 *
 * What became of a render, announced once it is settled.
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
 * The outcome of one render: the document, or why there is none.
 *
 * Dispatched for a terminal state only. An unreachable vendor is not a
 * terminal state: nobody knows yet what happened, and announcing a failure
 * there would tell filinq the vendor said no when nobody said anything.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */
class DocumentRenderedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $jobId The integriq job this is the outcome of.
	 * @param string $status `rendered` or `failed`.
	 * @param string $requestedBy The user the render was made for.
	 * @param string $fileReference The reference to the produced document, '' when there is none.
	 * @param string $error Why there is no document, '' when there is one.
	 */
	public function __construct(
		private readonly string $jobId,
		private readonly string $status,
		private readonly string $requestedBy = '',
		private readonly string $fileReference = '',
		private readonly string $error = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The job this is the outcome of.
	 *
	 * @return string The job id.
	 */
	public function getJobId(): string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * The terminal status.
	 *
	 * @return string `rendered` or `failed`.
	 */
	public function getStatus(): string {
		return $this->status;

	}//end getStatus()

	/**
	 * Who the render was for.
	 *
	 * @return string The user id.
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;

	}//end getRequestedBy()

	/**
	 * The produced document.
	 *
	 * @return string The file reference, '' when there is none.
	 */
	public function getFileReference(): string {
		return $this->fileReference;

	}//end getFileReference()

	/**
	 * Why there is no document.
	 *
	 * @return string The error, '' when there is a document.
	 */
	public function getError(): string {
		return $this->error;

	}//end getError()
}//end class
