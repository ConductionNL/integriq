<?php

/**
 * Characterisation test for ObjectEndpointHandler::index and ::show.
 *
 * Pins the allowed and refused lists and reads: the refusals (no type, an
 * unknown type), every data_attrs operator and a malformed clause, the date
 * filters, ordering, paging, and the one 404 for an absent object and an
 * object of another type.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
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

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the list and read routes.
 */
class ObjectEndpointListGoldenTest extends TestCase {

	/**
	 * Every read and answer, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The objects a principal may read.
	 *
	 * @param string $schema    The schema read.
	 * @param string $principal The principal.
	 *
	 * @return array<int, mixed> The objects.
	 */
	private function objectsFor(string $schema, string $principal): array {
		if ($principal !== 'alice') {
			return [];
		}

		$meta = static fn (string $id, string $created): array => ['id' => $id, 'created' => $created, 'version' => 2];
		if ($schema === 'boom') {
			return [['@self' => $meta('tree-1', '2026-03-01T10:00:00+00:00'), 'naam' => 'Eik']];
		}

		return [
			['@self' => $meta('m-1', '2026-01-02T03:04:05+00:00'), 'naam' => 'Kuil', 'prio' => '2', 'wijk' => 'Noord'],
			['@self' => $meta('m-2', '2026-01-03T03:04:05+00:00'), 'naam' => 'kuil groot', 'prio' => '5', 'wijk' => 'Zuid'],
			['@self' => $meta('m-3', '2026-01-02T23:59:59+00:00'), 'naam' => 'Lamp', 'prio' => '3'],
			['@self' => $meta('m-4', '2026-01-04T00:00:00+00:00'), 'naam' => 'Bank', 'prio' => null, 'wijk' => 'Noord'],
		];
	}//end objectsFor()

	/**
	 * GOLDEN: every list and read case, against the fixture recorded before the
	 * handler's paging and problem answers were reshaped.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding'],
				['uuid' => 'bbb', 'name' => 'boom', 'register' => 'groen', 'schema' => 'boom'],
			]
		);
		$handler = new ObjectEndpointHandler(
			objecttypes: $registry,
			translator: new ObjectRecordTranslator(),
			objectRead: function (string $register, string $schema, string $principal): array {
				$this->log[] = ['read' => [$register, $schema, $principal]];
				return $this->objectsFor(schema: $schema, principal: $principal);
			}
		);

		$lists = [
			'no type' => [[], 'alice'],
			'blank type' => [['type' => '  '], 'alice'],
			'unknown type' => [['type' => 'zzz'], 'alice'],
			'all' => [['type' => 'aaa'], 'alice'],
			'nothing readable' => [['type' => 'aaa'], 'bob'],
			'exact' => [['type' => 'aaa', 'data_attrs' => 'wijk__exact__Noord'], 'alice'],
			'icontains' => [['type' => 'aaa', 'data_attrs' => 'naam__icontains__KUIL'], 'alice'],
			'gt and lte' => [['type' => 'aaa', 'data_attrs' => 'prio__gt__2, prio__lte__5'], 'alice'],
			'gte' => [['type' => 'aaa', 'data_attrs' => 'prio__gte__3'], 'alice'],
			'lt on null' => [['type' => 'aaa', 'data_attrs' => 'prio__lt__9'], 'alice'],
			'unknown operator' => [['type' => 'aaa', 'data_attrs' => 'prio__in__2'], 'alice'],
			'malformed' => [['type' => 'aaa', 'data_attrs' => 'wijk__Noord'], 'alice'],
			'blank data_attrs' => [['type' => 'aaa', 'data_attrs' => ' '], 'alice'],
			'date' => [['type' => 'aaa', 'date' => '2026-01-02'], 'alice'],
			'registration date' => [['type' => 'aaa', 'registrationDate' => '2026-01-03'], 'alice'],
			'ordered' => [['type' => 'aaa', 'ordering' => 'naam'], 'alice'],
			'ordered descending' => [['type' => 'aaa', 'ordering' => '-prio'], 'alice'],
			'page two of one each' => [['type' => 'aaa', 'pageSize' => 1, 'page' => 2], 'alice'],
			'last page' => [['type' => 'aaa', 'pageSize' => '3', 'page' => '2'], 'alice'],
			'page size zero and page zero' => [['type' => 'aaa', 'pageSize' => 0, 'page' => 0], 'alice'],
			'page size too large' => [['type' => 'aaa', 'pageSize' => 9999], 'alice'],
			'past the end' => [['type' => 'aaa', 'pageSize' => 2, 'page' => 7], 'alice'],
			'ends at the total' => [['type' => 'aaa', 'pageSize' => 2, 'page' => 2], 'alice'],
			'one short of the total' => [['type' => 'aaa', 'pageSize' => 3, 'page' => 1], 'alice'],
			'by url' => [['type' => 'https://example.test/api/v2/objecttypes/bbb'], 'alice'],
		];
		foreach ($lists as $name => [$query, $principal]) {
			$this->log[] = ['index ' . $name => $handler->index($query, 'https://example.test', $principal)];
		}

		$reads = [
			'found' => ['aaa', 'm-2', 'alice'],
			'absent' => ['aaa', 'm-9', 'alice'],
			'other type' => ['aaa', 'tree-1', 'alice'],
			'not readable' => ['aaa', 'm-2', 'bob'],
			'unknown type' => ['zzz', 'm-2', 'alice'],
		];
		foreach ($reads as $name => [$type, $uuid, $principal]) {
			$this->log[] = ['show ' . $name => $handler->show($type, $uuid, 'https://example.test', $principal)];
		}

		$path = __DIR__ . '/../../../fixtures/objecten/object-list.json';
		$actual = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
