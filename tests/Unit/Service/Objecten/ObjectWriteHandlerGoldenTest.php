<?php

/**
 * Golden test pinning every response of ObjectWriteHandler's shared write path.
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

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use PHPUnit\Framework\TestCase;

/**
 * Pins the refusal order and exact problem bodies of create/replace/update, what reaches the
 * object service, and the announced notification, so the write path can be split safely.
 */
class ObjectWriteHandlerGoldenTest extends TestCase {

	/** @var array<int, array<int, mixed>> Calls to the object service: register, schema, uuid, data, principal. */
	private array $written = [];

	/** @var array<int, array<string, mixed>> Announced notifications, with the date masked. */
	private array $announced = [];

	/**
	 * The handler with the given wiring.
	 *
	 * @param bool  $writePath Whether the object service write path is wired.
	 * @param bool  $announcer Whether the announcer is wired.
	 * @param mixed $stored    What the write path returns, or a Throwable it throws.
	 *
	 * @return ObjectWriteHandler
	 */
	private function handler(bool $writePath = true, bool $announcer = true, mixed $stored = 'echo'): ObjectWriteHandler {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);

		return new ObjectWriteHandler(
			objecttypes: $registry,
			translator: new ObjectRecordTranslator(),
			objectWrite: ($writePath === false ? null : function (...$arguments) use ($stored) {
				$this->written[] = $arguments;
				if ($stored instanceof \Throwable) {
					throw $stored;
				}

				if ($stored === 'echo') {
					return array_merge(['@self' => ['id' => ($arguments[2] ?? 'new-1')]], $arguments[3]);
				}

				return $stored;
			}),
			announce: ($announcer === false ? null : function (array $notification): void {
				$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $notification['aanmaakdatum']);
				$notification['aanmaakdatum'] = 'TS';
				$this->announced[] = $notification;
			})
		);

	}//end handler()

	/**
	 * A problem response.
	 *
	 * @param int    $status The status.
	 * @param string $title  The title.
	 * @param string $detail The detail.
	 *
	 * @return array The response.
	 */
	private function problem(int $status, string $title, string $detail): array {
		return ['status' => $status, 'body' => ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail]];

	}//end problem()

	/**
	 * Every refusal, in the order the checks run, and that a refused write writes and announces nothing.
	 *
	 * @return void
	 */
	public function testRefusalsInOrder(): void {
		$noData = ['record' => []];
		$noAnnouncer = 'Writes are refused while the objecten kanaal is not wired: a change nobody is told about is a '
			. 'change the rest of the landscape does not have.';

		$this->assertSame(
			$this->problem(404, 'Not found', 'No objecttype "zzz" is published here.'),
			$this->handler(false, false)->create(type: 'zzz', body: $noData, principal: ' ')
		);
		$this->assertSame(
			$this->problem(403, 'Refused', 'This token names no principal, so a write could not be attributed to anyone.'),
			$this->handler(false, false)->replace(type: 'aaa', uuid: 'u', body: $noData, principal: " \t")
		);
		$this->assertSame(
			$this->problem(503, 'Unavailable', $noAnnouncer),
			$this->handler(false, false)->create(type: 'aaa', body: $noData, principal: 'p')
		);
		$this->assertSame(
			$this->problem(503, 'Unavailable', 'No write path is wired.'),
			$this->handler(false, true)->create(type: 'aaa', body: $noData, principal: 'p')
		);
		$this->assertSame(
			$this->problem(400, 'Bad request', 'A write needs "record.data".'),
			$this->handler()->create(type: 'aaa', body: ['record' => ['data' => [], 'geometry' => ['type' => 'Point']]], principal: 'p')
		);
		$this->assertSame(
			$this->problem(400, 'Validation refused', 'straatnaam is required'),
			$this->handler(stored: new \RuntimeException('straatnaam is required'))->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: 'p')
		);
		$this->assertSame([], $this->announced);
		$this->assertCount(1, $this->written);

	}//end testRefusalsInOrder()

	/**
	 * A create, a replace and a partial update: what is written, what is returned, what is announced.
	 *
	 * @return void
	 */
	public function testWrites(): void {
		$created = $this->handler()->create(
			type: 'aaa',
			body: ['record' => ['data' => ['a' => 1], 'geometry' => ['type' => 'Point', 'coordinates' => [5, 52]]]],
			principal: 'lev',
			baseUrl: 'https://x.nl/api/v2'
		);
		$this->assertSame(201, $created['status']);
		$this->assertSame('new-1', $created['body']['uuid']);
		$this->assertSame(['meldingen', 'melding', null, ['a' => 1, 'geometry' => ['type' => 'Point', 'coordinates' => [5, 52]]], 'lev'], $this->written[0]);
		$this->assertSame(
			[['kanaal' => 'objecten', 'hoofdObject' => 'new-1', 'resource' => 'object', 'resourceUrl' => 'new-1', 'actie' => 'create', 'aanmaakdatum' => 'TS', 'kenmerken' => ['objectType' => 'aaa']]],
			$this->announced
		);

		$this->written = [];
		$this->announced = [];
		$replaced = $this->handler()->replace(type: 'aaa', uuid: 'u-1', body: ['record' => ['data' => ['b' => 2]]], principal: 'lev');
		$this->assertSame(200, $replaced['status']);
		$this->assertSame(['meldingen', 'melding', 'u-1', ['b' => 2], 'lev'], $this->written[0]);
		$this->assertSame('update', $this->announced[0]['actie']);
		$this->assertSame('u-1', $this->announced[0]['hoofdObject']);

		$this->written = [];
		$fromData = $this->handler(stored: null)->replace(type: 'aaa', uuid: 'u-2', body: ['record' => ['data' => ['@self' => ['id' => 'u-2'], 'c' => 3]]], principal: 'lev');
		$this->assertSame(
			(new ObjectRecordTranslator())->toRecord(object: ['@self' => ['id' => 'u-2'], 'c' => 3], objecttype: 'aaa', baseUrl: ''),
			$fromData['body']
		);

		$this->written = [];
		$current = ['@self' => ['id' => 'u-3'], 'a' => 1, 'b' => 1, 'geometry' => ['type' => 'Point', 'coordinates' => [1, 1]]];
		$this->handler()->update(type: 'aaa', uuid: 'u-3', body: ['record' => ['data' => ['b' => 2]]], current: $current, principal: 'lev');
		$this->assertSame('u-3', $this->written[0][2]);
		$this->assertSame(
			array_merge((new ObjectRecordTranslator())->dataOf(object: $current), ['b' => 2], ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]]]),
			$this->written[0][3]
		);

		$this->written = [];
		$this->handler()->update(type: 'aaa', uuid: 'u-3', body: ['record' => ['geometry' => ['type' => 'Line']]], current: $current, principal: 'lev');
		$this->assertSame(['type' => 'Line'], $this->written[0][3]['geometry']);
		$this->assertSame(
			$this->problem(403, 'Refused', 'This token names no principal, so a write could not be attributed to anyone.'),
			$this->handler()->update(type: 'aaa', uuid: 'u-3', body: [], current: $current, principal: '')
		);

	}//end testWrites()
}//end class
