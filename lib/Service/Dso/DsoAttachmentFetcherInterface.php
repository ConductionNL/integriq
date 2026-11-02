<?php

/**
 * Contract of the DsoAttachmentFetcher.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Dso
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

namespace OCA\Integriq\Service\Dso;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;

/**
 * Public operations of {@see DsoAttachmentFetcher} that other components call.
 */
interface DsoAttachmentFetcherInterface {
	/**
	 * Download every entry of one request that is still `pending`, or
	 * `failed` with attempts left, and record each outcome on the request.
	 *
	 * @param string $requestUuid The `dso_verzoek` uuid.
	 *
	 * @return array<int, array<string, mixed>>|null The request's attachments afterwards,
	 *                                               or null when the request does not exist.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 */
	public function fetchPending(string $requestUuid): ?array;

	/**
	 * Download one entry, with up to {@see self::MAX_ATTEMPTS} attempts in all,
	 * and attach it to the request as a file tagged {@see self::TAG}.
	 *
	 * @param ObjectEntity $request The dso_verzoek the file belongs to.
	 * @param array<string, mixed> $entry The attachment entry.
	 * @param array<string, mixed> $sourceConfiguration The active DSO source's `configuration`.
	 * @param FileService $fileService OpenRegister's FileService.
	 *
	 * @return array<string, mixed> The entry with its new status, attempts, fileId or error.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 */
	public function fetchOne(
		ObjectEntity $request,
		array $entry,
		array $sourceConfiguration,
		FileService $fileService
	): array;

	/**
	 * Whether an entry still needs a download: `pending`, or `failed` with attempts left.
	 *
	 * @param mixed $entry The attachment entry.
	 *
	 * @return boolean True when the entry should be fetched.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 */
	public function needsDownload(mixed $entry): bool;

	/**
	 * Whether the behandelaar must handle a bijlage by hand: true while any
	 * entry is `failed` or `too-large` (REQ-DSO-005, "bijlage ontbreekt").
	 *
	 * @param array<int, array<string, mixed>> $attachments The attachments list.
	 *
	 * @return boolean True when a bijlage is missing.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 */
	public function isAttachmentMissing(array $attachments): bool;
}
