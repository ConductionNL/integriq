<?php

/**
 * In-memory file server for the SFTP/FTPS adapter tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer;

use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemoteFileClient;
use RuntimeException;

/**
 * Holds files by absolute path and records every call in order.
 */
class FakeRemoteFileClient implements RemoteFileClient {

	/**
	 * Calls in order: `open`, `fingerprint`, `login:<user>:<secret>:<mode>`, `rename:<from>:<to>` ...
	 *
	 * @var array<int,string>
	 */
	public array $calls = [];

	/**
	 * Files by absolute path: content and mtime.
	 *
	 * @param array<string,array{content:string,mtime:int}> $files       The files.
	 * @param string                                        $fingerprint What the server presents.
	 */
	public function __construct(
		public array $files = [],
		public string $fingerprint = 'SHA256:' . 'A' . 'bcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ',
	) {
	}//end __construct()

	/**
	 * Open.
	 *
	 * @param string $host The host.
	 * @param int    $port The port.
	 *
	 * @return void
	 */
	public function open(string $host, int $port): void {
		$this->calls[] = 'open:' . $host . ':' . $port;
	}//end open()

	/**
	 * Fingerprint.
	 *
	 * @return string The fingerprint.
	 */
	public function serverFingerprint(): string {
		$this->calls[] = 'fingerprint';
		return $this->fingerprint;
	}//end serverFingerprint()

	/**
	 * Login.
	 *
	 * @param string $username The user.
	 * @param string $secret   The secret.
	 * @param string $authMode The mode.
	 *
	 * @return void
	 */
	public function login(string $username, string $secret, string $authMode): void {
		$this->calls[] = 'login:' . $username . ':' . $secret . ':' . $authMode;
	}//end login()

	/**
	 * List.
	 *
	 * @param string $path The folder.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int}> The files.
	 */
	public function listFiles(string $path): array {
		$this->calls[] = 'list:' . $path;
		$files = [];
		foreach ($this->files as $filePath => $file) {
			if (dirname($filePath) === $path) {
				$files[] = ['name' => basename($filePath), 'size' => strlen($file['content']), 'mtime' => $file['mtime']];
			}
		}

		return $files;
	}//end listFiles()

	/**
	 * Download.
	 *
	 * @param string $remotePath The remote file.
	 * @param string $localPath  The local file.
	 *
	 * @return void
	 */
	public function download(string $remotePath, string $localPath): void {
		$this->calls[] = 'download:' . $remotePath;
		if (isset($this->files[$remotePath]) === false) {
			throw new RuntimeException('No such file ' . $remotePath);
		}

		file_put_contents($localPath, $this->files[$remotePath]['content']);
	}//end download()

	/**
	 * Upload.
	 *
	 * @param string $remotePath The remote file.
	 * @param string $localPath  The local file.
	 *
	 * @return void
	 */
	public function upload(string $remotePath, string $localPath): void {
		$this->calls[] = 'upload:' . $remotePath;
		$this->files[$remotePath] = ['content' => (string)file_get_contents($localPath), 'mtime' => 0];
	}//end upload()

	/**
	 * Rename.
	 *
	 * @param string $from The from path.
	 * @param string $to   The to path.
	 *
	 * @return void
	 */
	public function rename(string $from, string $to): void {
		$this->calls[] = 'rename:' . $from . ':' . $to;
		$this->files[$to] = $this->files[$from];
		unset($this->files[$from]);
	}//end rename()

	/**
	 * Delete.
	 *
	 * @param string $path The file.
	 *
	 * @return void
	 */
	public function delete(string $path): void {
		$this->calls[] = 'delete:' . $path;
		unset($this->files[$path]);
	}//end delete()

	/**
	 * Close.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->calls[] = 'close';
	}//end close()
}//end class
