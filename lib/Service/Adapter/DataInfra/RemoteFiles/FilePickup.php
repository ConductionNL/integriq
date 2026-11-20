<?php

/**
 * Picks new files up from a partner's server, once, and archives them after a safe local write.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

use Psr\Log\LoggerInterface;

/**
 * Configuration (a synchronization's `sourceConfig`):
 * - `path`: the folder on the server, relative to the source's root path;
 * - `pattern`: which names to pick up, a shell pattern such as `besluiten-*.csv`;
 * - `after`: `move` (to `archivePath`), `delete` or `keep`;
 * - `archivePath`: the archive folder on the server for `move`;
 * - `filesFolder`: the folder in Nextcloud Files the files are written to
 *   (required for `target` `files`, the default);
 * - `stableSeconds`: skip files modified more recently than this (default 60),
 *   so a file the partner is still writing is not picked up half.
 *
 * A file is picked up once: the run returns the files it has seen, by name with
 * their modification time and size, and the caller keeps that list for the next
 * run (the synchronization contract). A file that comes back changed under the
 * same name is picked up again.
 *
 * The remote move or delete runs only after the local write returned. A failed
 * local write leaves the remote file where it was, and the next run tries again.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
class FilePickup {

	/**
	 * Archive each picked file at once.
	 *
	 * @var string
	 */
	private const ARCHIVE = 'archive';

	/**
	 * Leave each picked file on the server for archivePicked().
	 *
	 * @var string
	 */
	private const KEEP = 'keep';

	/**
	 * What may follow a safe local write.
	 */
	public const AFTER = ['move', 'delete', 'keep'];

	/**
	 * Constructor.
	 *
	 * @param RemoteFileClient $client The remote file client.
	 * @param LoggerInterface  $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RemoteFileClient $client,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run one pickup.
	 *
	 * @param array<string, mixed>  $source  The source object.
	 * @param array<string, mixed>  $config  The pickup configuration (see the class description).
	 * @param PickedFileStore       $store   Where the files go.
	 * @param array<string, string> $seen    The files seen by earlier runs, name to version.
	 * @param int|null              $now     The current time, for the stability window.
	 *
	 * @return array<string, mixed> `picked` (name, path, storedAt, after), `failed` and `skipped` (name, reason), and `seen` (name to version).
	 *
	 * @throws RemoteFileException When the configuration is wrong or the folder cannot be listed.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function run(array $source, array $config, PickedFileStore $store, array $seen=[], ?int $now=null): array {
		return $this->pick(
			source: $source,
			config: $config,
			store: $store,
			seen: $seen,
			now: $now,
			after: self::ARCHIVE
		);
	}//end run()

	/**
	 * Fetch and store the new files like run(), but leave every picked file on the server, `after` `pending`.
	 *
	 * The caller archives them with archivePicked() once their records went
	 * through the mapping, so a run that stops in between archives nothing.
	 *
	 * @param array<string, mixed>  $source The source object.
	 * @param array<string, mixed>  $config The synchronization's `sourceConfig`.
	 * @param PickedFileStore       $store  Where the files are written.
	 * @param array<string, string> $seen   The versions already picked up, by name.
	 * @param int|null              $now    The current time; null for now.
	 *
	 * @return array{picked: list<array<string, mixed>>, failed: list<array<string, mixed>>,
	 *               skipped: list<array<string, mixed>>, seen: array<string, string>}
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function fetch(array $source, array $config, PickedFileStore $store, array $seen=[], ?int $now=null): array {
		return $this->pick(
			source: $source,
			config: $config,
			store: $store,
			seen: $seen,
			now: $now,
			after: self::KEEP
		);
	}//end fetch()

	/**
	 * Pick the new files up; what follows each picked file depends on $after.
	 *
	 * @param array<string, mixed>  $source The source object.
	 * @param array<string, mixed>  $config The synchronization's `sourceConfig`.
	 * @param PickedFileStore       $store  Where the files are written.
	 * @param array<string, string> $seen   The versions already picked up, by name.
	 * @param int|null              $now    The current time; null for now.
	 * @param string                $after  ARCHIVE to archive each picked file now, KEEP to leave it on the server.
	 *
	 * @return array{picked: list<array<string, mixed>>, failed: list<array<string, mixed>>,
	 *               skipped: list<array<string, mixed>>, seen: array<string, string>}
	 */
	private function pick(array $source, array $config, PickedFileStore $store, array $seen, ?int $now, string $after): array {
		$target = self::archiveTargetOf(config: $config);
		$folder = trim((string)($config['filesFolder'] ?? ''));
		if ($folder === '' && ($config['target'] ?? 'files') === 'files') {
			throw new RemoteFileException('sourceConfig.filesFolder is required: the Nextcloud folder the files are written to.');
		}

		$stableSeconds = max(0, (int)($config['stableSeconds'] ?? 60));
		$now         ??= time();
		$result        = ['picked' => [], 'failed' => [], 'skipped' => [], 'seen' => $seen];

		$files = $this->client->listFiles(source: $source, path: (string)($config['path'] ?? ''), pattern: (string)($config['pattern'] ?? '*'));
		foreach ($files as $file) {
			$version = $file->version();
			if (($seen[$file->name] ?? null) === $version) {
				continue;
			}

			if ($file->modifiedAt !== null && $file->modifiedAt > $now - $stableSeconds) {
				$result['skipped'][] = ['name' => $file->name, 'reason' => 'modified less than ' . $stableSeconds . ' seconds ago'];
				continue;
			}

			$fileTarget = $target + ['folder' => $folder, 'archive' => ($after === self::ARCHIVE)];
			$result     = $this->pickOne(source: $source, file: $file, store: $store, target: $fileTarget, result: $result);
		}

		return $result;
	}//end pick()

	/**
	 * Archive the files fetch() picked up, now that the caller has kept what it read.
	 *
	 * @param array<string, mixed>       $source The source object.
	 * @param array<string, mixed>       $config The pickup configuration.
	 * @param list<array<string, mixed>> $picked The run's `picked` entries.
	 *
	 * @return list<array<string, mixed>> The entries, `after` saying what happened on the server.
	 *
	 * @throws RemoteFileException When the configuration is wrong.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function archivePicked(array $source, array $config, array $picked): array {
		$target = self::archiveTargetOf(config: $config);
		foreach ($picked as $index => $entry) {
			if (($entry['after'] ?? null) !== 'pending') {
				continue;
			}

			$file = new RemoteFileEntry(name: (string)$entry['name'], path: (string)($entry['path'] ?? $entry['name']));
			$picked[$index]['after'] = $this->archive(source: $source, file: $file, target: $target);
		}

		return $picked;
	}//end archivePicked()

	/**
	 * What follows a safe local write, checked.
	 *
	 * @param array<string, mixed> $config The pickup configuration.
	 *
	 * @return array{after: string, archivePath: string}
	 *
	 * @throws RemoteFileException When `after` is unknown or `move` has no archive folder.
	 */
	private static function archiveTargetOf(array $config): array {
		$after = (string)($config['after'] ?? 'move');
		if (in_array($after, self::AFTER, true) === false) {
			throw new RemoteFileException('sourceConfig.after must be move, delete or keep.');
		}

		$archivePath = trim((string)($config['archivePath'] ?? ''));
		if ($after === 'move' && $archivePath === '') {
			throw new RemoteFileException('sourceConfig.archivePath is required when after is move.');
		}

		return ['after' => $after, 'archivePath' => $archivePath];
	}//end archiveTargetOf()

	/**
	 * Fetch, store and then archive one file.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param RemoteFileEntry      $file   The file.
	 * @param PickedFileStore      $store  The local store.
	 * @param array{folder: string, after: string, archivePath: string, archive: bool} $target Where it goes, what follows, and whether it follows now.
	 * @param array<string, mixed> $result The run result so far.
	 *
	 * @return array<string, mixed> The run result with this file added.
	 */
	private function pickOne(array $source, RemoteFileEntry $file, PickedFileStore $store, array $target, array $result): array {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			$result['failed'][] = ['name' => $file->name, 'reason' => 'no temporary stream'];
			return $result;
		}

		try {
			$this->client->fetch(source: $source, path: $file->path, target: $stream);
			rewind($stream);
			$storedAt = $store->store(folder: $target['folder'], name: $file->name, stream: $stream);
		} catch (\Throwable $exception) {
			// Nothing was confirmed locally, so the remote file stays where it is.
			$result['failed'][] = ['name' => $file->name, 'reason' => $exception->getMessage()];
			return $result;
		} finally {
			fclose($stream);
		}

		$result['seen'][$file->name] = $file->version();
		$remote = 'pending';
		if ($target['archive'] === true) {
			$remote = $this->archive(source: $source, file: $file, target: $target);
		}

		$result['picked'][] = ['name' => $file->name, 'path' => $file->path, 'storedAt' => $storedAt, 'after' => $remote];

		return $result;
	}//end pickOne()

	/**
	 * Move or delete the remote file after the local write.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param RemoteFileEntry      $file   The file.
	 * @param array{after: string, archivePath: string} $target What follows.
	 *
	 * @return string What happened remotely: moved, deleted, kept, or the failure.
	 */
	private function archive(array $source, RemoteFileEntry $file, array $target): string {
		try {
			if ($target['after'] === 'move') {
				$this->client->move(source: $source, from: $file->path, to: rtrim($target['archivePath'], '/') . '/' . $file->name);
				return 'moved';
			}

			if ($target['after'] === 'delete') {
				$this->client->delete(source: $source, path: $file->path);
				return 'deleted';
			}
		} catch (\Throwable $exception) {
			// The file is safe locally and marked seen, so it is not picked up twice.
			$this->logger->warning(
				'Integriq file pickup: stored "' . $file->name . '" but could not ' . $target['after'] . ' it on the server: ' . $exception->getMessage()
			);
			return $target['after'] . ' failed: ' . $exception->getMessage();
		}

		return 'kept';
	}//end archive()
}//end class
