<?php

/**
 * Contract of the OptOutLogMapper.
 *
 * @category Contract
 * @package  OCA\Integriq\Db
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

namespace OCA\Integriq\Db;

/**
 * Public operations of {@see OptOutLogMapper} that other components call.
 */
interface OptOutLogMapperInterface {
	/**
	 * Append one row.
	 *
	 * @param OptOutLogEntry $entry The row.
	 *
	 * @return OptOutLogEntry The stored row.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function append(OptOutLogEntry $entry): OptOutLogEntry;

	/**
	 * One page of the log, newest first, optionally for one correlation id.
	 *
	 * @param int    $limit         At most this many rows.
	 * @param int    $offset        Skip this many.
	 * @param string $correlationId Only this correlation id, when not empty.
	 *
	 * @return list<OptOutLogEntry> The rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function findPage(int $limit, int $offset, string $correlationId = ''): array;

	/**
	 * Delete every row written before a moment.
	 *
	 * @param int $before The unix time; rows strictly older go.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function deleteOlderThan(int $before): int;

	/**
	 * Every entry for one of these addresses, oldest first.
	 *
	 * @param list<string> $addresses The recipient keys.
	 *
	 * @return list<OptOutLogEntry> The entries.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	public function findForAddresses(array $addresses): array;

	/**
	 * Overwrite a redacted entry. The only change an entry ever gets after it is written.
	 *
	 * @param OptOutLogEntry $entry The redacted entry.
	 *
	 * @return OptOutLogEntry The entry.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013
	 */
	public function redact(OptOutLogEntry $entry): OptOutLogEntry;
}
