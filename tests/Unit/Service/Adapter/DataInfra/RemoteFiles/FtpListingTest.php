<?php

/**
 * A FTP LIST answer, the fallback for servers without MLSD, is read into entries.
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

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FtpListing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers (new FtpListing())->parse().
 */
#[CoversClass(FtpListing::class)]
final class FtpListingTest extends TestCase {

	/**
	 * A Unix-layout LIST answer (vsftpd, ProFTPD) is read into entries: a time without a year is
	 * this year, a date after tomorrow is last year, and links and the folder itself are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAUnixListAnswerIsReadIntoEntries(): void {
		$now    = gmmktime(1, 0, 0, 11, 26, 2026);
		$answer = "drwxr-xr-x    2 1000     1000         4096 Nov 25 22:10 .\r\n"
			. "-rw-r--r--    1 1000     1000         1204 Nov 14 07:25 besluiten 2026-11-14.csv\r\n"
			. "-rw-r--r--    1 ftp      ftp            88 Dec 30 23:59 last-year.csv\r\n"
			. "-rw-r--r--    1 ftp      ftp            12 Mar  3  2024 old.csv\r\n"
			. "drwxr-xr-x    2 ftp      ftp          4096 Nov 01 00:00 processed\r\n"
			. "lrwxrwxrwx    1 ftp      ftp             9 Nov 01 00:00 link -> processed\r\n"
			. "total 12\r\n";

		$this->assertSame(
			expected: [
				['name' => 'besluiten 2026-11-14.csv', 'size' => 1204, 'modifiedAt' => gmmktime(7, 25, 0, 11, 14, 2026), 'isDirectory' => false],
				['name' => 'last-year.csv', 'size' => 88, 'modifiedAt' => gmmktime(23, 59, 0, 12, 30, 2025), 'isDirectory' => false],
				['name' => 'old.csv', 'size' => 12, 'modifiedAt' => gmmktime(0, 0, 0, 3, 3, 2024), 'isDirectory' => false],
				['name' => 'processed', 'size' => 4096, 'modifiedAt' => gmmktime(0, 0, 0, 11, 1, 2026), 'isDirectory' => true],
			],
			actual: (new FtpListing())->parse(answer: $answer, now: $now)
		);
	}//end testAUnixListAnswerIsReadIntoEntries()

	/**
	 * An MS-DOS-layout LIST answer (IIS) is read into entries, AM/PM and 24-hour times alike.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testADosListAnswerIsReadIntoEntries(): void {
		$answer = "11-14-26  07:25PM                 1204 besluiten.csv\r\n"
			. "11-14-26  12:05AM       <DIR>          processed\r\n"
			. "11-14-2026  16:40                   7 late.csv\r\n";

		$this->assertSame(
			expected: [
				['name' => 'besluiten.csv', 'size' => 1204, 'modifiedAt' => gmmktime(19, 25, 0, 11, 14, 2026), 'isDirectory' => false],
				['name' => 'processed', 'size' => null, 'modifiedAt' => gmmktime(0, 5, 0, 11, 14, 2026), 'isDirectory' => true],
				['name' => 'late.csv', 'size' => 7, 'modifiedAt' => gmmktime(16, 40, 0, 11, 14, 2026), 'isDirectory' => false],
			],
			actual: (new FtpListing())->parse(answer: $answer, now: gmmktime(1, 0, 0, 11, 26, 2026))
		);
	}//end testADosListAnswerIsReadIntoEntries()
}//end class
