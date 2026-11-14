<?php

/**
 * Every remote path stays inside the source's root path.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers RemotePath.
 */
#[CoversClass(RemotePath::class)]
#[CoversClass(RemoteFileException::class)]
final class RemotePathTest extends TestCase {

	/**
	 * Paths joined onto the root.
	 *
	 * @return void
	 */
	public function testAPathIsJoinedOntoTheRoot(): void {
		$this->assertSame('/outgoing/besluiten/a.csv', RemotePath::resolve(rootPath: '/outgoing/', path: 'besluiten/a.csv'));
		$this->assertSame('/outgoing/a.csv', RemotePath::resolve(rootPath: 'outgoing', path: '/a.csv'), 'a leading slash is relative to the root');
		$this->assertSame('/outgoing/a.csv', RemotePath::resolve(rootPath: '/outgoing', path: './/a.csv'));
		$this->assertSame('/outgoing', RemotePath::resolve(rootPath: '/outgoing', path: ''));
		$this->assertSame('/a.csv', RemotePath::resolve(rootPath: '/', path: 'a.csv'));
		$this->assertSame('/', RemotePath::normaliseRoot(rootPath: ''));
	}//end testAPathIsJoinedOntoTheRoot()

	/**
	 * Paths that could leave the root.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function escapes(): array {
		return [
			'parent'          => ['../etc/passwd'],
			'nested parent'   => ['besluiten/../../x'],
			'trailing parent' => ['besluiten/..'],
			'backslash'       => ['..\\x'],
			'nul byte'        => ["a.csv\0.txt"],
		];
	}//end escapes()

	/**
	 * A path that could leave the root is refused.
	 *
	 * @param string $path The path.
	 *
	 * @return void
	 */
	#[DataProvider('escapes')]
	public function testAPathThatCouldLeaveTheRootIsRefused(string $path): void {
		$this->expectException(RemoteFileException::class);
		RemotePath::resolve(rootPath: '/outgoing', path: $path);
	}//end testAPathThatCouldLeaveTheRootIsRefused()

	/**
	 * A root path with .. is refused.
	 *
	 * @return void
	 */
	public function testARootWithParentSegmentsIsRefused(): void {
		$this->expectExceptionMessage('The root path of the source may not contain "..".');
		RemotePath::normaliseRoot(rootPath: '/outgoing/../etc');
	}//end testARootWithParentSegmentsIsRefused()
}//end class
