<?php

/**
 * Unit tests for VerdictService: what a verdict must name before it is
 * stored, how it is normalised and where it goes, and how the verdicts of
 * one object are read back.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Outbound\Call\VerdictService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Recording and reading verdicts.
 *
 * @covers \OCA\Integriq\Outbound\Call\VerdictService
 */
final class VerdictServiceTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $objects;

	/**
	 * A fresh object service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ORObjectService::class);
	}//end setUp()

	/**
	 * A stored verdict as the object service returns it.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The verdict.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A verdict is stored trimmed and lower-cased in the integriq register, stamped with its receipt time.
	 *
	 * @return void
	 */
	public function testAVerdictIsNormalisedAndStoredInTheRegister(): void {
		$saved = self::entity('v-1', []);
		$stored = null;
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ?string $register, ?string $schema) use (&$stored, $saved): ObjectEntity {
				$stored = [$object, $register, $schema];
				return $saved;
			}
		);

		$before = time();
		$this->assertSame($saved, (new VerdictService($this->objects))->record(' zaak/1 ', ' PASS ', ' keten ', 'ok', ['n' => 1]));
		$after = time();

		[$object, $register, $schema] = $stored;
		$this->assertSame([MessageRecorder::REGISTER, VerdictService::SCHEMA], [$register, $schema]);
		$this->assertSame(
			['objectRef' => 'zaak/1', 'state' => 'pass', 'source' => 'keten', 'reason' => 'ok', 'payload' => ['n' => 1]],
			array_diff_key($object, ['receivedAt' => true])
		);
		$received = (new DateTimeImmutable($object['receivedAt']))->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $received);
		$this->assertLessThanOrEqual($after, $received);
	}//end testAVerdictIsNormalisedAndStoredInTheRegister()

	/**
	 * A verdict without an object, or without a checker, is refused before anything is stored.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function incompleteVerdicts(): array {
		return [
			'no object'    => ['  ', 'pass', 'keten', 'A verdict must name the object it judges.'],
			'no checker'   => ['zaak/1', 'fail', ' ', 'A verdict must name the checker that gave it.'],
			'unknown state' => ['zaak/1', 'maybe', 'keten', 'Unknown verdict state "maybe": a verdict is pass, fail or pending.'],
		];
	}//end incompleteVerdicts()

	/**
	 * Incomplete or unknown verdicts throw and store nothing.
	 *
	 * @param string $objectRef The object.
	 * @param string $state     The state.
	 * @param string $source    The checker.
	 * @param string $message   The refusal.
	 *
	 * @dataProvider incompleteVerdicts
	 *
	 * @return void
	 */
	public function testAnIncompleteVerdictIsRefused(string $objectRef, string $state, string $source, string $message): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new VerdictService($this->objects))->record($objectRef, $state, $source);
	}//end testAnIncompleteVerdictIsRefused()

	/**
	 * Reading filters on register, schema and the trimmed object; only entities of that object count; newest first, with their uuid as id.
	 *
	 * @return void
	 */
	public function testTheVerdictsOfAnObjectAreReadNewestFirst(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => MessageRecorder::REGISTER, 'schema' => VerdictService::SCHEMA, 'objectRef' => 'zaak/1']])
			->willReturn(
				[
					'results' => [
						self::entity('old', ['objectRef' => 'zaak/1', 'state' => 'fail', 'receivedAt' => '2026-01-01T00:00:00+00:00']),
						['objectRef' => 'zaak/1', 'state' => 'pass'],
						self::entity('other', ['objectRef' => 'zaak/2', 'state' => 'pass', 'receivedAt' => '2026-03-01T00:00:00+00:00']),
						self::entity('new', ['objectRef' => 'zaak/1', 'state' => 'pass', 'receivedAt' => '2026-02-01T00:00:00+00:00']),
					],
				]
			);

		$verdicts = (new VerdictService($this->objects))->forObject(' zaak/1 ');

		$this->assertSame(['new', 'old'], array_column($verdicts, 'id'));
		$this->assertSame(['pass', 'fail'], array_column($verdicts, 'state'));
	}//end testTheVerdictsOfAnObjectAreReadNewestFirst()

	/**
	 * A plain list answer is read too; an answer that is no list gives no verdicts.
	 *
	 * @return void
	 */
	public function testPlainAndUnusableAnswers(): void {
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(
			[self::entity('a', ['objectRef' => 'zaak/1', 'state' => 'pending'])],
			['results' => 'none'],
		);
		$service = new VerdictService($this->objects);

		$this->assertSame([['objectRef' => 'zaak/1', 'state' => 'pending', 'id' => 'a']], $service->forObject('zaak/1'));
		$this->assertSame([], $service->forObject('zaak/1'));
	}//end testPlainAndUnusableAnswers()
}//end class
