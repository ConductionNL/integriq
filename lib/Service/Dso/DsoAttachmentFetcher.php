<?php

/**
 * Integriq DSO Attachment Fetcher.
 *
 * Downloads the bijlagen a `dso_verzoek` references and attaches each one as
 * a file to that request object, through OpenRegister's FileService, tagged
 * `dso-bijlage`. OpenRegister stores the file in the object's own folder in
 * Nextcloud Files and applies the object's access rights, so no integriq code
 * picks a user, a group folder or a path. Nothing is written outside Files.
 *
 * The download goes through {@see DsoClient::download()}, so the active DSO
 * source's own authentication applies: a token for pre-production, the client
 * certificate in production. Each entry gets up to three attempts with
 * exponential backoff. The outcome of each entry is written back to the
 * request's `attachments` list straight after that entry, so a crash leaves
 * a request the next run finishes, not a duplicate file.
 *
 * FileService is not a published OpenRegister contract. It is resolved
 * lazily from the container, and when it is absent the fetcher logs and
 * leaves every entry `pending`. Mirrors the attachment handling in
 * {@see \OCA\Integriq\Service\OpenFormulierenIntakeService}.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use Exception;
use OCA\Integriq\Exception\DsoAttachmentTooLargeException;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Service\DsoIngestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches the pending bijlagen of one dso_verzoek and stores them as object files.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */
class DsoAttachmentFetcher {

	/**
	 * Downloads tried per entry before it is `failed` for good (REQ-DSO-005).
	 *
	 * @var integer
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Default maximum bijlage size in bytes: 100 MB (REQ-DSO-005).
	 *
	 * @var integer
	 */
	public const DEFAULT_MAX_FILE_SIZE = 104857600;

	/**
	 * The tag every stored bijlage carries, so a case system can find them.
	 *
	 * @var string
	 */
	public const TAG = 'dso-bijlage';

	/**
	 * The OpenRegister FileService container id (not a published contract).
	 *
	 * @var string
	 */
	private const FILE_SERVICE_ID = 'OCA\OpenRegister\Service\FileService';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads and saves the dso_verzoek.
	 * @param DsoIngestService $ingestService Resolves the active DSO source.
	 * @param DsoClient $client Downloads a bijlage with the source's authentication.
	 * @param ContainerInterface $container Resolves OpenRegister's FileService lazily.
	 * @param LoggerInterface $logger Logger for secret-free diagnostics.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly DsoIngestService $ingestService,
		private readonly DsoClient $client,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
	public function fetchPending(string $requestUuid): ?array {
		$request = $this->objectService->find(
			id: $requestUuid,
			register: DsoIngestService::REGISTER,
			schema: DsoIngestService::SCHEMA_VERZOEK
		);
		if ($request instanceof ObjectEntity === false) {
			$this->logger->warning('[DsoAttachmentFetcher] no dso_verzoek ' . $requestUuid . '; nothing fetched');
			return null;
		}

		$attachments = array_values((array)($request->getObject()['attachments'] ?? []));
		$todo = array_keys(array_filter($attachments, [$this, 'needsDownload']));
		if ($todo === []) {
			return $attachments;
		}

		$fileService = $this->resolveFileService();
		if ($fileService === null) {
			return $attachments;
		}

		$sourceConfiguration = null;
		$sourceError = '';
		try {
			$sourceConfiguration = (array)($this->ingestService->resolveActiveSourceAsEngine()->getObject()['configuration'] ?? []);
		} catch (DsoProviderException $exception) {
			$sourceError = $exception->getMessage();
		}

		foreach ($todo as $index) {
			$entry = $this->failed(entry: (array)$attachments[$index], error: $sourceError, terminal: false);
			if ($sourceConfiguration !== null) {
				$entry = $this->fetchOne(
					request: $request,
					entry: (array)$attachments[$index],
					sourceConfiguration: $sourceConfiguration,
					fileService: $fileService
				);
			}

			$attachments[$index] = $entry;
			$request = $this->saveAttachments(request: $request, attachments: $attachments);
		}

		return $attachments;
	}//end fetchPending()

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
	): array {
		$url = (string)($entry['url'] ?? '');
		if ($url === '') {
			return $this->failed(entry: $entry, error: 'The bijlage has no URL.', terminal: true);
		}

		// The URL comes from the inbound payload and the request carries the
		// source's credential, so only https is fetched (no file://, no plain http).
		if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
			return $this->failed(entry: $entry, error: 'Only https bijlage URLs are downloaded.', terminal: true);
		}

		$maxBytes = (int)($sourceConfiguration['maxFileSize'] ?? self::DEFAULT_MAX_FILE_SIZE);
		if ($maxBytes <= 0) {
			$maxBytes = self::DEFAULT_MAX_FILE_SIZE;
		}

		$attempts = (int)($entry['attempts'] ?? 0);
		$lastError = '';
		while ($attempts < self::MAX_ATTEMPTS) {
			$attempts++;
			$entry['attempts'] = $attempts;
			try {
				$stream = $this->client->download(
					sourceConfiguration: $sourceConfiguration,
					url: $url,
					maxBytes: $maxBytes
				);
				try {
					$file = $fileService->addFile(
						objectEntity: $request,
						fileName: (string)($entry['name'] ?? ''),
						content: $stream,
						tags: [self::TAG]
					);
				} finally {
					if (is_resource($stream) === true) {
						fclose($stream);
					}
				}

				unset($entry['error']);
				$entry['status'] = 'stored';
				$entry['fileId'] = (int)$file->getId();

				return $entry;
			} catch (DsoAttachmentTooLargeException $exception) {
				$entry['status'] = 'too-large';
				$entry['error'] = $exception->getMessage();

				return $entry;
			} catch (Exception $exception) {
				// Exception, not Throwable: an Error is a defect, not a flaky
				// download, and retrying it would only hide it.
				$lastError = $exception->getMessage();
				$this->logger->warning(
					'[DsoAttachmentFetcher] bijlage download attempt ' . $attempts . ' failed',
					['name' => ($entry['name'] ?? ''), 'exception' => $lastError]
				);
			}//end try

			if ($attempts < self::MAX_ATTEMPTS) {
				$this->pause(seconds: (2 ** ($attempts - 1)));
			}
		}//end while

		return $this->failed(entry: $entry, error: $lastError, terminal: false);
	}//end fetchOne()

	/**
	 * Whether an entry still needs a download: `pending`, or `failed` with attempts left.
	 *
	 * @param mixed $entry The attachment entry.
	 *
	 * @return boolean True when the entry should be fetched.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 */
	public function needsDownload(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		$status = ($entry['status'] ?? 'pending');
		if ($status === 'pending') {
			return true;
		}

		return ($status === 'failed' && (int)($entry['attempts'] ?? 0) < self::MAX_ATTEMPTS);
	}//end needsDownload()

	/**
	 * Wait between attempts. A seam so tests do not sleep.
	 *
	 * @param integer $seconds The backoff in seconds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 */
	protected function pause(int $seconds): void {
		sleep($seconds);
	}//end pause()

	/**
	 * Mark an entry failed with its last error.
	 *
	 * @param array<string, mixed> $entry The attachment entry.
	 * @param string $error The last error.
	 * @param boolean $terminal True when retrying cannot help, so no attempts are left.
	 *
	 * @return array<string, mixed> The failed entry.
	 */
	private function failed(array $entry, string $error, bool $terminal): array {
		$entry['status'] = 'failed';
		$entry['error'] = $error;
		$entry['attempts'] = (int)($entry['attempts'] ?? 0);
		if ($terminal === true) {
			$entry['attempts'] = self::MAX_ATTEMPTS;
		}

		return $entry;
	}//end failed()

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
	public function isAttachmentMissing(array $attachments): bool {
		foreach ($attachments as $entry) {
			if (in_array(($entry['status'] ?? null), ['failed', 'too-large'], true) === true) {
				return true;
			}
		}

		return false;
	}//end isAttachmentMissing()

	/**
	 * Save the attachments list, and the attachmentMissing flag, onto the request.
	 *
	 * @param ObjectEntity $request The request as last saved.
	 * @param array<int, array<string, mixed>> $attachments The attachments list.
	 *
	 * @return ObjectEntity The saved request.
	 */
	private function saveAttachments(ObjectEntity $request, array $attachments): ObjectEntity {
		$data = $request->getObject();
		$data['attachments'] = $attachments;
		$data['attachmentMissing'] = $this->isAttachmentMissing(attachments: $attachments);

		return $this->objectService->saveObject(
			object: $data,
			register: DsoIngestService::REGISTER,
			schema: DsoIngestService::SCHEMA_VERZOEK,
			uuid: $request->getUuid()
		);
	}//end saveAttachments()

	/**
	 * Resolve OpenRegister's FileService, or null when OpenRegister cannot provide it.
	 *
	 * @return FileService|null The FileService.
	 */
	private function resolveFileService(): ?FileService {
		try {
			$fileService = $this->container->get(self::FILE_SERVICE_ID);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DsoAttachmentFetcher] OpenRegister FileService unavailable; bijlagen stay pending',
				['exception' => $exception->getMessage()]
			);
			return null;
		}

		if ($fileService instanceof FileService === false) {
			$this->logger->warning('[DsoAttachmentFetcher] OpenRegister FileService has an unexpected type; bijlagen stay pending');
			return null;
		}

		return $fileService;
	}//end resolveFileService()
}//end class
