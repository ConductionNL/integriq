<?php

/**
 * Contract of the RunSummaryService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Public operations of {@see RunSummaryService} that other components call.
 */
interface RunSummaryServiceInterface {
	/**
	 * The longest window one request may ask for, in days.
	 *
	 * @var int
	 */
	public const MAX_WINDOW_DAYS = 31;

	/**
	 * Exception code: a window over 31 days, or one that runs backwards.
	 *
	 * @var int
	 */
	public const WINDOW_TOO_LONG = 2;

	/**
	 * The source's pulls per day in the window, and its latest runs.
	 *
	 * @param string $sourceId The source.
	 * @param DateTimeImmutable $from The first day of the window.
	 * @param DateTimeImmutable $to The last day of the window.
	 *
	 * @return array{days: array<int, array<string, int|string>>, runs: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the window runs backwards or is longer than 31 days.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
	 */
	public function summarise(string $sourceId, DateTimeImmutable $from, DateTimeImmutable $to): array;

	/**
	 * The window a request asks for: `from` and `to` as Y-m-d, or the last
	 * seven days when they are absent.
	 *
	 * @param string|null $from The first day, Y-m-d.
	 * @param string|null $to The last day, Y-m-d.
	 * @param DateTimeImmutable $today Today.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} The first and the last day.
	 *
	 * @throws InvalidArgumentException With code WINDOW_NOT_A_DATE or WINDOW_TOO_LONG.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
	 */
	public function window(?string $from, ?string $to, DateTimeImmutable $today): array;
}
