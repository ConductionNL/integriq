<?php

/**
 * Contract of the ConnectionProbeService.
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

use OCA\Integriq\Exception\ConnectionLinkException;

/**
 * Public operations of {@see ConnectionProbeService} that other components call.
 */
interface ConnectionProbeServiceInterface {
	/**
	 * The most probes one health job run makes (D7).
	 *
	 * @var int
	 */
	public const PROBE_LIMIT = 25;

	/**
	 * Probe the linked connections whose last probe is oldest.
	 *
	 * @param int $limit The most probes to make.
	 *
	 * @return int The number of rows probed.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-at-most-25-probes-per-run
	 */
	public function probeDue(int $limit = self::PROBE_LIMIT): int;

	/**
	 * Probe one row's source, write `lastProbe`, resolve and save.
	 *
	 * An open circuit breaker is recorded as an error without a call.
	 *
	 * @param array{uuid:string,data:array<string,mixed>} $row The stored row.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-an-open-breaker-is-not-called
	 */
	public function probe(array $row): array;

	/**
	 * Link an existing source to a connection that has none, then probe it.
	 *
	 * @param string $connectionId The connection row uuid.
	 * @param string $sourceId The source uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @throws ConnectionLinkException When the row or source is missing, or the row already has a source.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-linking-a-source-probes-it-straight-away
	 */
	public function linkSource(string $connectionId, string $sourceId): array;

	/**
	 * Link a source made from the connection's `sourceTemplate`, then probe it.
	 *
	 * A source that already carries the template's slug is reused rather than
	 * copied. Otherwise the seed payload the catalog uses is saved as a new,
	 * enabled source.
	 *
	 * @param string $connectionId The connection row uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @throws ConnectionLinkException When the row is missing or linked, or the template is absent.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function linkTemplate(string $connectionId): array;
}
