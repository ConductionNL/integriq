<?php

/**
 * An FTPS folder listing (MLSD) is read into entries.
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

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FtpsSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers FtpsSession::parseMlsd().
 */
#[CoversClass(FtpsSession::class)]
final class FtpsSessionTest extends TestCase {

	/**
	 * Files and folders are read with their size and time; the folder itself and its parent are left out.
	 *
	 * @return void
	 */
	public function testAnMlsdAnswerIsReadIntoEntries(): void {
		$answer = "type=cdir;modify=20261114070000; .\r\n"
			. "type=pdir;modify=20261114070000; ..\r\n"
			. "type=file;size=1204;modify=20261114072500.123;perm=r; besluiten 2026-11-14.csv\r\n"
			. "type=dir;modify=20261101000000; processed\r\n"
			. "garbage-without-space\r\n";

		$this->assertSame(
			[
				['name' => 'besluiten 2026-11-14.csv', 'size' => 1204, 'modifiedAt' => gmmktime(7, 25, 0, 11, 14, 2026), 'isDirectory' => false],
				['name' => 'processed', 'size' => null, 'modifiedAt' => gmmktime(0, 0, 0, 11, 1, 2026), 'isDirectory' => true],
			],
			FtpsSession::parseMlsd(answer: $answer)
		);
	}//end testAnMlsdAnswerIsReadIntoEntries()
}//end class
