<?php

/**
 * Status Push Run
 *
 * The state of one DSO-LV status push: what is sent, where, and how many
 * attempts were made.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One status push with its retry progress; built and used by DSOStatusService only.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 */
final class StatusPushRun {

	/**
	 * The attempts made so far.
	 *
	 * @var int
	 */
	public int $attempt = 0;

	/**
	 * Constructor.
	 *
	 * @param string              $uri     The DSO-LV status endpoint.
	 * @param array<string,mixed> $payload The status payload: verzoekId, status, timestamp.
	 */
	public function __construct(
		public readonly string $uri,
		public readonly array $payload,
	) {

	}//end __construct()

	/**
	 * The DSO verzoek identifier.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function requestId(): mixed {
		return $this->payload['verzoekId'];

	}//end requestId()

	/**
	 * The DSO status pushed.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function dsoStatus(): mixed {
		return $this->payload['status'];

	}//end dsoStatus()
}//end class
