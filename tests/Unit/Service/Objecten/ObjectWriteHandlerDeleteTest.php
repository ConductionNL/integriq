<?php

/**
 * Unit tests for ObjectWriteHandler::delete.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every answer of a delete: unknown type, no announcer, no delete path, a refused delete and a done one.
 */
#[CoversClass(ObjectWriteHandler::class)]
#[UsesClass(ObjecttypeRegistry::class)]
#[UsesClass(ObjectRecordTranslator::class)]
class ObjectWriteHandlerDeleteTest extends TestCase {

	/** @var array<int, array<int, string>> Calls to the delete path. */
	private array $deleted = [];

	/** @var array<int, array<string, mixed>> Announced notifications. */
	private array $announced = [];

	/**
	 * The handler with the given wiring.
	 *
	 * @param bool            $deletePath Whether the delete path is wired.
	 * @param bool            $announcer  Whether the announcer is wired.
	 * @param \Throwable|null $failure    What the delete path throws, if anything.
	 *
	 * @return ObjectWriteHandler
	 */
	private function handler(bool $deletePath = true, bool $announcer = true, ?\Throwable $failure = null): ObjectWriteHandler {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);

		return new ObjectWriteHandler(
			objecttypes: $registry,
			translator: new ObjectRecordTranslator(),
			objectDelete: ($deletePath === false ? null : function (string ...$arguments) use ($failure): void {
				$this->deleted[] = $arguments;
				if ($failure !== null) {
					throw $failure;
				}
			}),
			announce: ($announcer === false ? null : function (array $notification): void {
				$this->announced[] = $notification;
			})
		);
	}//end handler()

	/**
	 * An unknown objecttype is a 404 and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsNotFound(): void {
		$this->assertSame(
			['status' => 404, 'body' => ['type' => 'about:blank', 'title' => 'Not found', 'status' => 404, 'detail' => 'No objecttype "zzz" is published here.']],
			$this->handler()->delete(type: 'zzz', uuid: 'u1', principal: 'p')
		);
		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->announced);
	}//end testAnUnknownTypeIsNotFound()

	/**
	 * Without an announcer the delete is refused before the delete path runs.
	 *
	 * @return void
	 */
	public function testNoAnnouncerRefusesTheDelete(): void {
		$answer = $this->handler(announcer: false)->delete(type: 'aaa', uuid: 'u1', principal: 'p');

		$this->assertSame(503, $answer['status']);
		$this->assertStringStartsWith('Writes are refused while the objecten kanaal is not wired', $answer['body']['detail']);
		$this->assertSame([], $this->deleted);
	}//end testNoAnnouncerRefusesTheDelete()

	/**
	 * Without a delete path the answer is 503 and nothing is announced.
	 *
	 * @return void
	 */
	public function testNoDeletePathIsUnavailable(): void {
		$this->assertSame(
			['status' => 503, 'body' => ['type' => 'about:blank', 'title' => 'Unavailable', 'status' => 503, 'detail' => 'No write path is wired.']],
			$this->handler(deletePath: false)->delete(type: 'aaa', uuid: 'u1', principal: 'p')
		);
		$this->assertSame([], $this->announced);
	}//end testNoDeletePathIsUnavailable()

	/**
	 * A delete the object service refuses is a 422 with its message, and is not announced.
	 *
	 * @return void
	 */
	public function testARefusedDeleteIsUnprocessableAndNotAnnounced(): void {
		$answer = $this->handler(failure: new RuntimeException('object is locked'))->delete(type: 'aaa', uuid: 'u1', principal: 'p');

		$this->assertSame(
			['status' => 422, 'body' => ['type' => 'about:blank', 'title' => 'Refused', 'status' => 422, 'detail' => 'object is locked']],
			$answer
		);
		$this->assertSame([['meldingen', 'melding', 'u1', 'p']], $this->deleted);
		$this->assertSame([], $this->announced);
	}//end testARefusedDeleteIsUnprocessableAndNotAnnounced()

	/**
	 * A delete runs on the declared register and schema as the principal, answers 204 and announces destroy.
	 *
	 * @return void
	 */
	public function testADeleteIsDoneAndAnnounced(): void {
		$answer = $this->handler()->delete(type: 'aaa', uuid: 'u1', principal: 'lev');

		$this->assertSame(['status' => 204, 'body' => []], $answer);
		$this->assertSame([['meldingen', 'melding', 'u1', 'lev']], $this->deleted);
		$this->assertCount(1, $this->announced);
		$this->assertSame('destroy', $this->announced[0]['actie']);
		$this->assertSame('u1', $this->announced[0]['hoofdObject']);
		$this->assertSame(['objectType' => 'aaa'], $this->announced[0]['kenmerken']);
	}//end testADeleteIsDoneAndAnnounced()

	/**
	 * An announcer that throws does not turn a done delete into a failure.
	 *
	 * @return void
	 */
	public function testAFailingAnnouncerStillAnswersNoContent(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);
		$handler = new ObjectWriteHandler(
			objecttypes: $registry,
			translator: new ObjectRecordTranslator(),
			objectDelete: static function (): void {
			},
			announce: static function (): void {
				throw new RuntimeException('kanaal down');
			}
		);

		$this->assertSame(['status' => 204, 'body' => []], $handler->delete(type: 'aaa', uuid: 'u1', principal: 'p'));
	}//end testAFailingAnnouncerStillAnswersNoContent()
}//end class
