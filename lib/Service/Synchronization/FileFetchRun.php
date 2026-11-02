<?php

/**
 * One concurrent fetch of an object's files.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
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

namespace OCA\Integriq\Service\Synchronization;

/**
 * The request, the concurrency limits and the shared run state of one
 * object's concurrent file fetches: the tracking filenames, the in-flight
 * byte tally, the per-slot sizes and temp files, and whether the admission
 * gate held requests back.
 *
 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
 */
final class FileFetchRun {

	/**
	 * The tracking filenames recorded for cleanup.
	 *
	 * @var list<string>
	 */
	public array $fileNames = [];

	/**
	 * The declared bytes of the downloads currently in flight.
	 *
	 * @var int
	 */
	public int $inFlightBytes = 0;

	/**
	 * The declared size per in-flight slot.
	 *
	 * @var array<int, int>
	 */
	public array $inFlightSize = [];

	/**
	 * Whether the admission gate held a request back.
	 *
	 * @var bool
	 */
	public bool $throttled = false;

	/**
	 * The prepared fetch descriptor per slot whose temp file is not released yet.
	 *
	 * @var array<int, array>
	 */
	public array $released = [];

	/**
	 * The next free slot index.
	 *
	 * @var int
	 */
	public int $nextSlot = 0;

	/**
	 * Starts a run.
	 *
	 * @param FileFetchRequest                                           $request The request whose files are fetched.
	 * @param array{concurrency: int, byteBudget: int, maxFileSize: int} $limits  The resolved concurrency limits.
	 */
	public function __construct(
		public readonly FileFetchRequest $request,
		public readonly array $limits,
	) {
	}//end __construct()
}//end class
