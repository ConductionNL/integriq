<?php

/**
 * Unit tests for StufZdsMessageLog's source and row reads and its saves.
 *
 * A source that is missing, unreadable or of another type is refused; a
 * message row that cannot be read is null; a concluded row without an id is
 * saved as a new row rather than under an empty uuid. Only OpenRegister's
 * ObjectService is a double.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsMessageLog
 */
class StufZdsMessageLogRowsTest extends TestCase {

	/**
	 * The arguments of each saveObject call.
	 *
	 * @var array<int, array>
	 */
	private array $saves = [];

	/**
	 * A log whose ObjectService::find answers with this, or throws it.
	 *
	 * @param mixed $answer What find returns, or a Throwable it throws.
	 *
	 * @return StufZdsMessageLog
	 */
	private function log(mixed $answer): StufZdsMessageLog {
		$objects = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('find')->willReturnCallback(
			static function () use ($answer) {
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'msg-new');
			}
		);

		return new StufZdsMessageLog($objects);
	}//end log()

	public function testAStufZknSourceIsReadWithItsUuidAsId(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'stuf-zkn', 'configuration' => ['provider' => 'rest']], 'zsh');

		$this->assertSame(['type' => 'stuf-zkn', 'configuration' => ['provider' => 'rest'], 'id' => 'zsh'], $this->log($source)->source('zsh'));
	}//end testAStufZknSourceIsReadWithItsUuidAsId()

	public function testASourceThatCannotBeReadIsRefusedAsMissing(): void {
		$this->expectException(StufZknProviderException::class);
		$this->expectExceptionMessage('No StUF-ZKN source "zsh" exists in integriq.');

		$this->log(new RuntimeException('database gone'))->source('zsh');
	}//end testASourceThatCannotBeReadIsRefusedAsMissing()

	public function testAnEmptySourceIdIsRefusedWithoutAsking(): void {
		$this->expectException(StufZknProviderException::class);
		$this->expectExceptionMessage('No StUF-ZKN source "" exists in integriq.');

		$this->log(new RuntimeException('find must not be called'))->source('');
	}//end testAnEmptySourceIdIsRefusedWithoutAsking()

	public function testASourceOfAnotherTypeIsRefused(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'rest'], 'api');

		$this->expectException(StufZknProviderException::class);
		$this->expectExceptionMessage('Source "api" is not a stuf-zkn source.');

		$this->log($source)->source('api');
	}//end testASourceOfAnotherTypeIsRefused()

	public function testAMessageRowIsReadWithItsUuidAsId(): void {
		$row = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'sent', 'id' => 'stale'], 'msg-7');

		$this->assertSame(['status' => 'sent', 'id' => 'msg-7'], $this->log($row)->find('msg-7'));
	}//end testAMessageRowIsReadWithItsUuidAsId()

	public function testAMessageRowThatCannotBeReadOrDoesNotExistIsNull(): void {
		$this->assertNull($this->log(new RuntimeException('database gone'))->find('msg-7'));
		$this->assertNull($this->log(null)->find('msg-7'));
	}//end testAMessageRowThatCannotBeReadOrDoesNotExistIsNull()

	public function testAConcludedRowWithoutAnIdIsSavedAsANewRow(): void {
		$row = $this->log(null)->conclude(
			row: ['id' => '', 'direction' => 'outbound', 'status' => 'retrying', 'envelope' => '<x/>'],
			sent: false,
			attempt: 2,
			transport: ['httpStatus' => 503, 'durationMs' => 40],
			fout: ['code' => 'HTTP_503', 'omschrijving' => 'Unavailable', 'details' => '']
		);

		$this->assertNull($this->saves[0]['uuid']);
		$this->assertSame(['integriq', 'stuf_message'], [$this->saves[0]['register'], $this->saves[0]['schema']]);
		$this->assertArrayNotHasKey('id', $this->saves[0]['object']);
		$this->assertArrayNotHasKey('envelope', $this->saves[0]['object']);
		$this->assertSame(['msg-new', 'failed', 'HTTP_503', 'Unavailable', 2, 503], [$row['id'], $row['status'], $row['faultCode'], $row['error'], $row['attempts'], $row['httpStatus']]);
	}//end testAConcludedRowWithoutAnIdIsSavedAsANewRow()
}//end class
