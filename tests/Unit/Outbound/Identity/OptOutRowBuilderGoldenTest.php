<?php

/**
 * Golden recording of the opt-out row builder: every field (and which fields
 * were set) of the row each request builds, or the refusal it throws, and
 * every state move applyChange() makes. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Outbound\Identity\OptOutRowBuilder;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins every allowed and refused opt-out request and every state move.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */
class OptOutRowBuilderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/golden/opt-out-row-builder.json';

	private const FIELDS = [
		'address', 'scope', 'channel', 'caseRef', 'listRef', 'state', 'purpose', 'contactRef', 'lawfulBasis',
		'evidence', 'source', 'sourceApp', 'legacyUuid', 'createdAt', 'updatedAt', 'withdrawnAt', 'dedupeKey',
	];

	/**
	 * The requests.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function requests(): array {
		$email = ['address' => ' Piet@Example.org ', 'state' => 'opted-out'];
		return [
			'instance opt-out, defaults'        => $email,
			'opt-in with everything'            => [
				'address' => 'piet@example.org', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => ' EMAIL ',
				'purpose' => ' Marketing ', 'contactRef' => 'c-1', 'lawfulBasis' => 'consent', 'evidence' => ['form' => 'f-1'],
				'source' => 'portal', 'sourceApp' => 'pipelinq', 'legacyRef' => ' old-1 ', 'ref' => 'ignored',
			],
			'case scope'                        => $email + ['scope' => 'case', 'ref' => ' ZAAK-1 '],
			'list scope'                        => $email + ['scope' => 'list', 'ref' => 'L-1'],
			'channel scope on sms'              => ['address' => '06 12345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms'],
			'empty evidence'                    => $email + ['evidence' => []],
			'evidence not a list'               => $email + ['evidence' => 'x'],
			'blank legacy ref'                  => $email + ['legacyRef' => '   '],
			'unknown state'                     => ['address' => 'a@b.nl', 'state' => 'erase-contact'],
			'no state'                          => ['address' => 'a@b.nl'],
			'unknown scope'                     => $email + ['scope' => 'world'],
			'channel scope without channel'     => $email + ['scope' => 'channel'],
			'case scope without ref'            => $email + ['scope' => 'case', 'ref' => '  '],
			'list scope without ref'            => $email + ['scope' => 'list'],
			'unusable address'                  => ['address' => 'not an address', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email'],
		];

	}//end requests()

	/**
	 * The state moves: the stored row's state, the request's fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function changes(): array {
		return [
			'opt-in over an opt-out'         => ['previous' => 'opted-out', 'state' => 'opted-in', 'contactRef' => 'c-2', 'lawfulBasis' => 'consent', 'evidence' => '{"a":1}'],
			'opt-out over an opt-in'         => ['previous' => 'opted-in', 'state' => 'opted-out', 'contactRef' => '', 'lawfulBasis' => '', 'evidence' => null],
			'opt-out over an opt-out'        => ['previous' => 'opted-out', 'state' => 'opted-out', 'contactRef' => 'c-3', 'lawfulBasis' => 'x', 'evidence' => null],
			'opt-in over an opt-in'          => ['previous' => 'opted-in', 'state' => 'opted-in', 'contactRef' => '', 'lawfulBasis' => '', 'evidence' => null],
		];

	}//end changes()

	/**
	 * Every request and move matches the recording.
	 *
	 * @return void
	 */
	public function testEveryRowMatchesTheRecording(): void {
		$fixture = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$builder = new OptOutRowBuilder($fixture->recipientKey(), $fixture->clock(), $fixture->categories());
		$found = [];
		foreach (self::requests() as $name => $request) {
			try {
				$found['row ' . $name] = $this->fieldsOf(row: $builder->rowFor(request: $request));
			} catch (Throwable $exception) {
				$found['row ' . $name] = get_class($exception) . ': ' . $exception->getMessage();
			}
		}

		foreach (self::changes() as $name => $change) {
			$stored = new OptOut();
			$stored->setState($change['previous']);
			$stored->setContactRef('stored-contact');
			$stored->setLawfulBasis('stored-basis');
			$stored->setEvidence('{"stored":true}');
			$stored->setWithdrawnAt(5);
			$stored->resetUpdatedFields();
			$row = new OptOut();
			$row->setState($change['state']);
			$row->setSource('src');
			$row->setSourceApp('app');
			$row->setUpdatedAt(1000);
			$row->setContactRef($change['contactRef']);
			$row->setLawfulBasis($change['lawfulBasis']);
			$row->setEvidence($change['evidence']);
			$builder->applyChange(stored: $stored, row: $row, previous: $change['previous']);
			$found['change ' . $name] = $this->fieldsOf(row: $stored);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}

	}//end testEveryRowMatchesTheRecording()

	/**
	 * Every field of a row, and the fields set on it in order.
	 *
	 * @param OptOut $row The row.
	 *
	 * @return array<string,mixed> The fields.
	 */
	private function fieldsOf(OptOut $row): array {
		$fields = [];
		foreach (self::FIELDS as $field) {
			$fields[$field] = $row->{'get' . ucfirst($field)}();
		}

		$fields['updatedFields'] = array_keys($row->getUpdatedFields());
		return $fields;

	}//end fieldsOf()
}//end class
