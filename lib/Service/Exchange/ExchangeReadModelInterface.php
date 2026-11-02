<?php

/**
 * Contract of the ExchangeReadModel.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Exchange
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

namespace OCA\Integriq\Service\Exchange;

/**
 * Public operations of {@see ExchangeReadModel} that other components call.
 */
interface ExchangeReadModelInterface {
	/**
	 * List an app's exchange jobs.
	 *
	 * @param string               $ownerApp The owning app.
	 * @param array<string,string> $filters  Optional `target`, `status`, `ownerRef`.
	 * @param int                  $limit    Page size, capped at 200.
	 * @param int                  $offset   Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The page.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function listJobs(string $ownerApp, array $filters = [], int $limit = 50, int $offset = 0): array;

	/**
	 * One exchange job with its rejections and latest log line.
	 *
	 * @param string $ownerApp The owning app; a job of another app is not returned.
	 * @param string $jobId    The job's uuid.
	 *
	 * @return array<string, mixed>|null The row, or null when absent or foreign.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function getJob(string $ownerApp, string $jobId): ?array;

	/**
	 * List an app's exchange rejections.
	 *
	 * @param string               $ownerApp The owning app.
	 * @param array<string,string> $filters  Optional `status`, `target`, `jobId`.
	 * @param int                  $limit    Page size, capped at 200.
	 * @param int                  $offset   Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The page.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function listRejections(string $ownerApp, array $filters = [], int $limit = 50, int $offset = 0): array;

	/**
	 * The target catalogue with the directions integriq handles.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function targets(): array;
}
