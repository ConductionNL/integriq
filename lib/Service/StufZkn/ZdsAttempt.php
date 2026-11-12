<?php

/**
 * Integriq StUF-ZDS attempt.
 *
 * One attempt at sending a StUF-ZDS message, as StufZdsOutbound carries it
 * from the transmission to its classification. Never leaves that class.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * One attempt at a StUF-ZDS message: its row, its source, what is sent and what came back.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
final class ZdsAttempt {

	/**
	 * The source, `id` set.
	 *
	 * @var array
	 */
	public array $source = [];

	/**
	 * The operation.
	 *
	 * @var string
	 */
	public string $operation = '';

	/**
	 * The envelope, without credentials.
	 *
	 * @var string
	 */
	public string $envelope = '';

	/**
	 * The transport result, empty until transmitted.
	 *
	 * @var array
	 */
	public array $response = [];

	/**
	 * The classified fout of a failed attempt, null otherwise.
	 *
	 * @var array|null
	 */
	public ?array $fout = null;

	/**
	 * Constructor.
	 *
	 * @param array $row    The stuf_message row; replaced when the row is saved.
	 * @param int   $number The attempt (1-indexed).
	 */
	public function __construct(
		public array $row,
		public readonly int $number,
	) {

	}//end __construct()
}//end class
