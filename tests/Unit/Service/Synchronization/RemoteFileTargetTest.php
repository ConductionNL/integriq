<?php

/**
 * A push delivers its mapped rows as one CSV file through the remote file client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-deliver-a-file-to-an-sftp-or-ftps-server-req-hrx-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Synchronization\RemoteFileTarget;
use PHPUnit\Framework\TestCase;

/**
 * Covers lib/Service/Synchronization/RemoteFileTarget.php over a recording client.
 */
final class RemoteFileTargetTest extends TestCase {

	/**
	 * Each delivery: path and the text written.
	 *
	 * @var list<array{path: string, text: string}>
	 */
	private array $delivered = [];

	/**
	 * The writer over a client that records what it was given.
	 *
	 * @return RemoteFileTarget
	 */
	private function target(): RemoteFileTarget {
		$client = $this->createMock(originalClassName: RemoteFileClient::class);
		$client->method('deliver')->willReturnCallback(
			function (array $source, string $path, $stream): void {
				$this->delivered[] = ['path' => $path, 'text' => (string)stream_get_contents($stream)];
			}
		);

		return new RemoteFileTarget(client: $client);
	}//end target()

	/**
	 * The rows become CSV with a header, in the configured folder, under the mapped name.
	 *
	 * @return void
	 */
	public function testTheRowsAreDeliveredAsOneCsvFile(): void {
		$result = $this->target()->deliver(
			source: ['type' => 'sftp'],
			targetConfig: ['path' => 'incoming/'],
			targetObject: [
				'fileName' => '812345671L01-2026-09.csv',
				'rows' => [
					['medewerker' => 'e1', 'veld' => 'hoursPerWeek', 'nieuw' => 36],
					['medewerker' => 'e2', 'veld' => 'iban', 'nieuw' => 'NL20INGB0001234567'],
				],
			]
		);

		$this->assertSame(
			expected: ['path' => 'incoming/812345671L01-2026-09.csv', 'fileName' => '812345671L01-2026-09.csv', 'rows' => 2],
			actual: $result
		);
		$this->assertSame(expected: 'incoming/812345671L01-2026-09.csv', actual: $this->delivered[0]['path']);
		$this->assertSame(expected: "medewerker,veld,nieuw\ne1,hoursPerWeek,36\ne2,iban,NL20INGB0001234567\n", actual: $this->delivered[0]['text']);
	}//end testTheRowsAreDeliveredAsOneCsvFile()

	/**
	 * Other field names and the delimiter come from the target configuration.
	 *
	 * @return void
	 */
	public function testTheFieldsAndDelimiterAreConfigurable(): void {
		$this->target()->deliver(
			source: [],
			targetConfig: ['fileNameField' => 'name', 'rowsField' => 'lines', 'delimiter' => ';'],
			targetObject: ['name' => 'out.csv', 'lines' => [['a' => 1, 'b' => 2]]]
		);

		$this->assertSame(expected: 'out.csv', actual: $this->delivered[0]['path']);
		$this->assertSame(expected: "a;b\n1;2\n", actual: $this->delivered[0]['text']);
	}//end testTheFieldsAndDelimiterAreConfigurable()

	/**
	 * A missing name, or a name with a path in it, is refused before the server is reached.
	 *
	 * @return void
	 */
	public function testAFileNameWithAPathIsRefused(): void {
		foreach (['', '../etc/passwd', 'sub/file.csv', '..'] as $name) {
			try {
				$this->target()->deliver(source: [], targetConfig: [], targetObject: ['fileName' => $name, 'rows' => []]);
				$this->fail(message: 'A file name "' . $name . '" must be refused.');
			} catch (RemoteFileException $e) {
				$this->assertStringContainsString(needle: 'file', haystack: $e->getMessage());
			}
		}

		$this->assertSame(expected: [], actual: $this->delivered);
	}//end testAFileNameWithAPathIsRefused()

	/**
	 * Rows that are not a list are refused.
	 *
	 * @return void
	 */
	public function testRowsThatAreNotAListAreRefused(): void {
		$this->expectException(exception: RemoteFileException::class);
		$this->target()->deliver(source: [], targetConfig: [], targetObject: ['fileName' => 'a.csv', 'rows' => ['x' => ['a' => 1]]]);
	}//end testRowsThatAreNotAListAreRefused()
}//end class
