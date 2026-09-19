<?php

/**
 * The write path: what lands, what is refused, and what is announced.
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
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-OAF-004.
 */
class ObjectenWriteTest extends TestCase {

	/**
	 * What was written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * What was announced.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $announced = [];

	/**
	 * One objecttype.
	 *
	 * @return ObjecttypeRegistry The registry.
	 */
	private function registry(): ObjecttypeRegistry {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);

		return $registry;
	}//end registry()

	/**
	 * The handler.
	 *
	 * @param bool $announcer Whether an announcer is wired.
	 * @param bool $validates Whether the schema accepts the write.
	 *
	 * @return ObjectWriteHandler The handler.
	 */
	private function handler(bool $announcer = true, bool $validates = true): ObjectWriteHandler {
		return new ObjectWriteHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectWrite: function (string $r, string $s, ?string $uuid, array $data, string $principal) use ($validates): array {
				if ($validates === false) {
					throw new \RuntimeException('straatnaam is required');
				}

				$this->written[] = ['register' => $r, 'uuid' => $uuid, 'data' => $data, 'principal' => $principal];

				return array_merge(['@self' => ['id' => ($uuid ?? 'new-1'), 'created' => '2026-03-01T09:00:00+01:00']], $data);
			},
			objectDelete: function (string $r, string $s, string $uuid, string $principal): void {
				$this->written[] = ['deleted' => $uuid, 'principal' => $principal];
			},
			announce: ($announcer === false ? null : function (array $notification): void {
				$this->announced[] = $notification;
			})
		);
	}//end handler()

	/**
	 * A create lands and comes back in the standard's shape.
	 *
	 * @return void
	 */
	public function testACreateLandsAndComesBackInTheStandardsShape(): void {
		$response = $this->handler()->create(
			type: 'aaa',
			body: ['record' => ['data' => ['straatnaam' => 'Kerkstraat']]],
			principal: 'leverancier',
			baseUrl: 'https://example.nl/api/v2'
		);

		$this->assertSame(201, $response['status']);
		$this->assertSame('new-1', $response['body']['uuid']);
		$this->assertSame(['straatnaam' => 'Kerkstraat'], $response['body']['record']['data']);
		$this->assertSame(
			[],
			(new ObjectRecordTranslator())->foreignFieldsIn(rendered: $response['body']),
			'the write response is the same shape as the read one'
		);
	}//end testACreateLandsAndComesBackInTheStandardsShape()

	/**
	 * 🔴 The audit trail names the token's principal, not an anonymous caller.
	 *
	 * @return void
	 */
	public function testTheWriteIsAttributedToTheTokensPrincipal(): void {
		$this->handler()->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: 'leverancier');

		$this->assertSame('leverancier', $this->written[0]['principal']);
	}//end testTheWriteIsAttributedToTheTokensPrincipal()

	/**
	 * A write with no principal is refused rather than attributed to nobody.
	 *
	 * @return void
	 */
	public function testAWriteWithNoPrincipalIsRefused(): void {
		$response = $this->handler()->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: '  ');

		$this->assertSame(403, $response['status']);
		$this->assertSame([], $this->written, 'and nothing is written');
	}//end testAWriteWithNoPrincipalIsRefused()

	/**
	 * A create the schema refuses is a refusal, and nothing is created.
	 *
	 * @return void
	 */
	public function testACreateTheSchemaRefusesCreatesNothing(): void {
		$response = $this->handler(validates: false)->create(
			type: 'aaa',
			body: ['record' => ['data' => ['toelichting' => 'x']]],
			principal: 'leverancier'
		);

		$this->assertSame(400, $response['status']);
		$this->assertStringContainsString(
			'straatnaam',
			$response['body']['detail'],
			'the schema\'s answer is passed through: the consumer needs to know which field'
		);
		$this->assertSame([], $this->announced, 'and nothing is announced for a write that did not happen');
	}//end testACreateTheSchemaRefusesCreatesNothing()

	/**
	 * A write is announced on the objecten kanaal.
	 *
	 * @return void
	 */
	public function testAWriteIsAnnouncedOnTheObjectenKanaal(): void {
		$this->handler()->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: 'leverancier');

		$this->assertCount(1, $this->announced);
		$this->assertSame(ObjectWriteHandler::KANAAL, $this->announced[0]['kanaal']);
		$this->assertSame('create', $this->announced[0]['actie']);
		$this->assertSame('aaa', $this->announced[0]['kenmerken']['objectType']);
	}//end testAWriteIsAnnouncedOnTheObjectenKanaal()

	/**
	 * 🔴 A write that cannot announce is REFUSED, not performed.
	 *
	 * An object that changed without an announcement is an object that, as far
	 * as the landscape is concerned, did not change.
	 *
	 * @return void
	 */
	public function testAWriteThatCannotAnnounceIsRefusedNotPerformed(): void {
		$handler = $this->handler(announcer: false);

		foreach (
			[
				$handler->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: 'p'),
				$handler->replace(type: 'aaa', uuid: 'u', body: ['record' => ['data' => ['a' => 1]]], principal: 'p'),
				$handler->delete(type: 'aaa', uuid: 'u', principal: 'p'),
			] as $response
		) {
			$this->assertSame(503, $response['status']);
		}

		$this->assertSame([], $this->written, 'nothing was written while nothing could be announced');
	}//end testAWriteThatCannotAnnounceIsRefusedNotPerformed()

	/**
	 * 🔴 A PATCH merges; it does not replace.
	 *
	 * Sending a PATCH through the replace path drops every field the caller did
	 * not mention, and the response looks exactly like a successful update.
	 *
	 * @return void
	 */
	public function testAPatchMergesRatherThanReplacing(): void {
		$response = $this->handler()->update(
			type: 'aaa',
			uuid: 'm-1',
			body: ['record' => ['data' => ['status' => 'gesloten']]],
			current: ['@self' => ['id' => 'm-1'], 'straatnaam' => 'Kerkstraat', 'status' => 'open'],
			principal: 'leverancier'
		);

		$this->assertSame(200, $response['status']);
		$this->assertSame(
			['straatnaam' => 'Kerkstraat', 'status' => 'gesloten'],
			$response['body']['record']['data'],
			'the field the caller did not mention survives'
		);
	}//end testAPatchMergesRatherThanReplacing()

	/**
	 * The control: a PUT replaces, so an unmentioned field does NOT survive.
	 *
	 * Without it, the merge test could be passing on a handler that merges
	 * everything, which would make PUT impossible.
	 *
	 * @return void
	 */
	public function testAPutReplacesSoAnUnmentionedFieldDoesNotSurvive(): void {
		$response = $this->handler()->replace(
			type: 'aaa',
			uuid: 'm-1',
			body: ['record' => ['data' => ['status' => 'gesloten']]],
			principal: 'leverancier'
		);

		$this->assertSame(['status' => 'gesloten'], $response['body']['record']['data'], 'the control: a replace replaces');
	}//end testAPutReplacesSoAnUnmentionedFieldDoesNotSurvive()

	/**
	 * A delete removes and announces `destroy`, answering 204.
	 *
	 * @return void
	 */
	public function testADeleteRemovesAndAnnouncesDestroy(): void {
		$response = $this->handler()->delete(type: 'aaa', uuid: 'm-1', principal: 'leverancier');

		$this->assertSame(204, $response['status']);
		$this->assertSame('m-1', $this->written[0]['deleted']);
		$this->assertSame('destroy', $this->announced[0]['actie']);
	}//end testADeleteRemovesAndAnnouncesDestroy()

	/**
	 * A write to an unpublished objecttype is a 404 and writes nothing.
	 *
	 * @return void
	 */
	public function testAWriteToAnUnpublishedObjecttypeIs404(): void {
		$response = $this->handler()->create(type: 'zzz', body: ['record' => ['data' => ['a' => 1]]], principal: 'p');

		$this->assertSame(404, $response['status']);
		$this->assertSame([], $this->written);
	}//end testAWriteToAnUnpublishedObjecttypeIs404()

	/**
	 * A write with no `record.data` is refused rather than creating an empty
	 * object.
	 *
	 * @return void
	 */
	public function testAWriteWithNoDataIsRefused(): void {
		$response = $this->handler()->create(type: 'aaa', body: [], principal: 'p');

		$this->assertSame(400, $response['status']);
		$this->assertSame([], $this->written);
	}//end testAWriteWithNoDataIsRefused()

	/**
	 * A failing announcement does not fail a write that already landed.
	 *
	 * @return void
	 */
	public function testAFailingAnnouncementDoesNotFailAWriteThatLanded(): void {
		$handler = new ObjectWriteHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectWrite: function (string $r, string $s, ?string $uuid, array $data, string $principal): array {
				$this->written[] = ['data' => $data];
				return array_merge(['@self' => ['id' => 'new-1']], $data);
			},
			announce: static function (array $notification): void {
				throw new \RuntimeException('the kanaal is unreachable');
			}
		);

		$response = $handler->create(type: 'aaa', body: ['record' => ['data' => ['a' => 1]]], principal: 'p');

		$this->assertSame(201, $response['status'], 'the object is written, and the caller is told so');
		$this->assertCount(1, $this->written);
	}//end testAFailingAnnouncementDoesNotFailAWriteThatLanded()
}//end class
