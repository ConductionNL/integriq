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
 * - `filesFolder`: the folder in Nextcloud Files the files are written to;
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
	 * @param array<string, mixed>  $source The source object.
	 * @param array<string, mixed>  $config The pickup configuration (see the class description).
	 * @param PickedFileStore       $store  Where the files go.
	 * @param array<string, string> $seen   The files seen by earlier runs, name to version.
	 * @param int|null              $now    The current time, for the stability window.
	 *
	 * @return array<string, mixed> `picked` (name, storedAt, after), `failed` and `skipped` (name, reason), and `seen` (name to version).
	 *
	 * @throws RemoteFileException When the configuration is wrong or the folder cannot be listed.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function run(array $source, array $config, PickedFileStore $store, array $seen=[], ?int $now=null): array {
		$after = (string)($config['after'] ?? 'move');
		if (in_array($after, self::AFTER, true) === false) {
			throw new RemoteFileException('sourceConfig.after must be move, delete or keep.');
		}

		$archivePath = trim((string)($config['archivePath'] ?? ''));
		if ($after === 'move' && $archivePath === '') {
			throw new RemoteFileException('sourceConfig.archivePath is required when after is move.');
		}

		$folder = trim((string)($config['filesFolder'] ?? ''));
		if ($folder === '') {
			throw new RemoteFileException('sourceConfig.filesFolder is required: the Nextcloud folder the files are written to.');
		}

		$stableSeconds = max(0, (int)($config['stableSeconds'] ?? 60));
		$now         ??= time();
		$result        = ['picked' => [], 'failed' => [], 'skipped' => [], 'seen' => $seen];

		$files = $this->client->listFiles(source: $source, path: (string)($config['path'] ?? ''), pattern: (string)($config['pattern'] ?? '*'));
		foreach ($files as $file) {
			$version = self::versionOf(file: $file);
			if (($seen[$file->name] ?? null) === $version) {
				continue;
			}

			if ($file->modifiedAt !== null && $file->modifiedAt > $now - $stableSeconds) {
				$result['skipped'][] = ['name' => $file->name, 'reason' => 'modified less than ' . $stableSeconds . ' seconds ago'];
				continue;
			}

			$target = ['folder' => $folder, 'after' => $after, 'archivePath' => $archivePath];
			$result = $this->pickOne(source: $source, file: $file, store: $store, target: $target, result: $result);
		}

		return $result;
	}//end run()

	/**
	 * The version of a file a later run compares against.
	 *
	 * @param RemoteFileEntry $file The file.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public static function versionOf(RemoteFileEntry $file): string {
		return (string)($file->modifiedAt ?? '') . ':' . (string)($file->size ?? '');
	}//end versionOf()

	/**
	 * Fetch, store and then archive one file.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param RemoteFileEntry      $file   The file.
	 * @param PickedFileStore      $store  The local store.
	 * @param array{folder: string, after: string, archivePath: string} $target Where it goes and what follows.
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

		$result['seen'][$file->name] = self::versionOf(file: $file);
		$remote = $this->archive(source: $source, file: $file, target: $target);
		$result['picked'][] = ['name' => $file->name, 'storedAt' => $storedAt, 'after' => $remote];

		return $result;
	}//end pickOne()

	/**
	 * Move or delete the remote file after the local write.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param RemoteFileEntry      $file   The file.
	 * @param array{folder: string, after: string, archivePath: string} $target What follows.
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
