<?php

/**
 * Golden tests for the part upload of ZgwDocumentDelivery.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use PHPUnit\Framework\TestCase;

/**
 * Pins the requests of the part upload: the parts in volgnummer order with
 * their slice of the file, the default file name, the lock, the unlock, and
 * each refusal with the clean-up it triggers.
 */
class ZgwDocumentPartsGoldenTest extends TestCase {

	private const DOCUMENT = 'https://drc.example/api/v1/enkelvoudiginformatieobjecten/d-1';

	/**
	 * Requests sent, in order.
	 *
	 * @var array<int, array>
	 */
	private array $sent = [];

	/**
	 * Deliver over a transport whose create answers with the given body.
	 *
	 * @param array  $created  The create answer's body.
	 * @param string $content  The file.
	 * @param array  $document The delivery's metadata.
	 *
	 * @return array|string The delivery result, or the refusal as "status: message".
	 */
	private function deliver(array $created, string $content, array $document=[]): array|string {
		$this->sent = [];
		$transport  = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(
			function (string $sourceId, string $method, string $address, array $options=[]) use ($created): array {
				$this->sent[] = [$sourceId, $method, $address, $options];
				if ($method === 'POST' && $address === '/enkelvoudiginformatieobjecten') {
					return ['status' => 201, 'data' => $created, 'raw' => ''];
				}

				return ['status' => 200, 'data' => ['ok' => true], 'raw' => ''];
			}
		);

		try {
			return (new ZgwDocumentDelivery($transport))->deliver($document, $content, ['documentenSource' => 'drc', 'inline' => false]);
		} catch (CaseSystemRefusal $refusal) {
			return $refusal->getStatus().': '.$refusal->getMessage();
		}

	}//end deliver()

	/**
	 * The PUTs, unlock and other requests after the create.
	 *
	 * @return array<int, array> The requests.
	 */
	private function afterCreate(): array {
		return array_slice($this->sent, 1);

	}//end afterCreate()

	/**
	 * Parts are sent in volgnummer order with their slice, then the document is unlocked.
	 *
	 * @return void
	 */
	public function testPartsInOrderThenUnlock(): void {
		$created = [
			'url' => self::DOCUMENT.'/',
			'lock' => 'L-1',
			'bestandsdelen' => [
				['url' => 'https://drc.example/delen/3', 'volgnummer' => '3', 'omvang' => 1],
				'not-a-part',
				['url' => 'https://drc.example/delen/1', 'volgnummer' => 1, 'omvang' => 2],
				['volgnummer' => 2, 'omvang' => '3'],
			],
		];
		$result = $this->deliver($created, 'abcdef');

		$this->assertSame(['url' => self::DOCUMENT.'/', 'zaakinformatieobject' => null, 'document' => $created], $result);
		$put = static fn (string $address, string $slice): array => [
			'drc',
			'PUT',
			$address,
			['multipart' => [['name' => 'inhoud', 'contents' => $slice, 'filename' => 'document'], ['name' => 'lock', 'contents' => 'L-1']]],
		];
		$this->assertSame(
			[
				$put('https://drc.example/delen/1', 'ab'),
				$put('', 'cde'),
				$put('https://drc.example/delen/3', 'f'),
				['drc', 'POST', self::DOCUMENT.'/unlock', ['json' => ['lock' => 'L-1']]],
			],
			$this->afterCreate()
		);

	}//end testPartsInOrderThenUnlock()

	/**
	 * No parts: nothing is uploaded or unlocked; the file name comes from the delivery.
	 *
	 * @return void
	 */
	public function testNoParts(): void {
		$this->assertSame(['url' => self::DOCUMENT, 'zaakinformatieobject' => null, 'document' => ['url' => self::DOCUMENT, 'bestandsdelen' => ['x']]], $this->deliver(['url' => self::DOCUMENT, 'bestandsdelen' => ['x']], 'abc', ['bestandsnaam' => 'brief.pdf']));
		$this->assertSame([], $this->afterCreate());

		$this->deliver(['url' => self::DOCUMENT, 'lock' => 'L', 'bestandsdelen' => [['url' => 'u', 'omvang' => 3]]], 'abc', ['bestandsnaam' => 'brief.pdf']);
		$this->assertSame('brief.pdf', $this->afterCreate()[0][3]['multipart'][0]['filename']);

	}//end testNoParts()

	/**
	 * A missing lock and parts that do not add up are refused and the document removed.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(
			'502: The Documenten API asked for the file in parts but sent no lock.',
			$this->deliver(['url' => self::DOCUMENT, 'lock' => '', 'bestandsdelen' => [['url' => 'u', 'omvang' => 3]]], 'abc')
		);
		$this->assertSame([['drc', 'DELETE', self::DOCUMENT, []]], $this->afterCreate());

		$this->assertSame(
			'502: The Documenten API parts add up to 2 bytes, the file has 3.',
			$this->deliver(['url' => self::DOCUMENT, 'lock' => 'L', 'bestandsdelen' => [['url' => 'u', 'omvang' => 2]]], 'abc')
		);
		$this->assertSame(['PUT', 'DELETE'], array_column($this->afterCreate(), 1));

	}//end testRefusals()
}//end class
