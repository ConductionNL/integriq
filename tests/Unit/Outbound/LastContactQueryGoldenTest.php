<?php

/**
 * Golden tests for LastContactQuery::lastContact(): which recipient rows count
 * as told, which date they carry, and which message wins.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-log-answers-when-a-recipient-was-last-told-anything-req-ocl-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\LastContactQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the answer over a log with every kind of row.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-log-answers-when-a-recipient-was-last-told-anything-req-ocl-006
 */
class LastContactQueryGoldenTest extends TestCase {

	private const SUBJECT = 'zaak/1';

	private const ME = 'jan@example.org';

	/**
	 * A logged message.
	 *
	 * @param string              $uuid   The message id.
	 * @param array<string,mixed> $record The message record.
	 *
	 * @return ObjectEntity
	 */
	private function message(string $uuid, array $record): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($record + ['subjectRef' => self::SUBJECT]);

		return $entity;

	}//end message()

	/**
	 * The answer over these messages.
	 *
	 * @param list<mixed> $results What the objects endpoint returns.
	 *
	 * @return array<string,mixed>
	 */
	private function answer(array $results): array {
		$objectService = $this->createMock(ORObjectService::class);
		$objectService->method('findAll')->willReturn(['results' => $results]);

		return (new LastContactQuery($objectService))->lastContact(self::SUBJECT, self::ME);

	}//end answer()

	/**
	 * A sent row in a recipient list.
	 *
	 * @param string $address      The address.
	 * @param string $handedOverAt The hand-over time, or ''.
	 * @param string $status       The row status.
	 *
	 * @return array<string,string>
	 */
	private static function row(string $address, string $handedOverAt, string $status = 'sent'): array {
		return ['address' => $address, 'status' => $status, 'handedOverAt' => $handedOverAt];

	}//end row()

	/**
	 * Only a sent row to the recipient counts; the latest one wins, the first of equals.
	 *
	 * @return void
	 */
	public function testTheLatestSentRowWins(): void {
		$answer = $this->answer(
			[
				'not an entity',
				$this->message('other-subject', ['subjectRef' => 'zaak/2', 'recipients' => [self::row(self::ME, '2030-01-01T00:00:00Z')]]),
				$this->message('no-list', ['recipients' => 'jan']),
				$this->message(
					'm1',
					[
						'createdAt' => '2026-01-05T00:00:00Z',
						'recipients' => [
							'not a row',
							self::row('piet@example.org', '2029-01-01T00:00:00Z'),
							self::row(self::ME, '2029-06-01T00:00:00Z', 'failed'),
							self::row(self::ME, ''),
							self::row(self::ME, '2026-03-01T00:00:00Z'),
						],
					]
				),
				$this->message('m2', ['recipients' => [self::row(self::ME, '2026-04-01T02:00:00+02:00'), self::row(self::ME, '')]]),
				$this->message('m3', ['recipients' => [self::row(self::ME, '2026-04-01T00:00:00Z')]]),
				$this->message('m4', ['recipients' => [self::row(self::ME, '2026-02-01T00:00:00Z')]]),
			]
		);

		$this->assertSame(['contacted' => true, 'at' => '2026-04-01T02:00:00+02:00', 'message' => 'm2'], $answer);

	}//end testTheLatestSentRowWins()

	/**
	 * Without a hand-over time the message's own creation time counts; without both, nothing.
	 *
	 * @return void
	 */
	public function testCreatedAtIsTheFallback(): void {
		$answer = $this->answer(
			[
				$this->message('m1', ['createdAt' => '2026-05-01T00:00:00Z', 'recipients' => [self::row(self::ME, '')]]),
				$this->message('m2', ['recipients' => [self::row(self::ME, '')]]),
			]
		);

		$this->assertSame(['contacted' => true, 'at' => '2026-05-01T00:00:00Z', 'message' => 'm1'], $answer);

	}//end testCreatedAtIsTheFallback()

	/**
	 * Rows that never left answer never.
	 *
	 * @return void
	 */
	public function testNoSentRowIsNever(): void {
		$answer = $this->answer(
			[$this->message('m1', ['createdAt' => '2026-05-01T00:00:00Z', 'recipients' => [self::row(self::ME, '2026-05-01T00:00:00Z', 'queued')]])]
		);

		$this->assertSame(['contacted' => false, 'at' => null, 'message' => null], $answer);

	}//end testNoSentRowIsNever()

	/**
	 * An unparsable time loses to a real one but wins as the only candidate.
	 *
	 * @return void
	 */
	public function testUnparsableTimes(): void {
		$this->assertSame(
			['contacted' => true, 'at' => '2026-01-01T00:00:00Z', 'message' => 'm2'],
			$this->answer(
				[
					$this->message('m1', ['recipients' => [self::row(self::ME, 'gisteren')]]),
					$this->message('m2', ['recipients' => [self::row(self::ME, '2026-01-01T00:00:00Z')]]),
					$this->message('m3', ['recipients' => [self::row(self::ME, 'morgen')]]),
				]
			)
		);
		$this->assertSame(
			['contacted' => true, 'at' => 'gisteren', 'message' => 'm1'],
			$this->answer([$this->message('m1', ['recipients' => [self::row(self::ME, 'gisteren'), self::row(self::ME, 'morgen')]])])
		);

	}//end testUnparsableTimes()
}//end class
