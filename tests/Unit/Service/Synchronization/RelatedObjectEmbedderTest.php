<?php

/**
 * A push carries the objects that point at the pushed object, and never a partial or widened list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-carry-the-objects-that-point-at-the-pushed-object-req-hrx-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\RelatedObjectEmbedder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers lib/Service/Synchronization/RelatedObjectEmbedder.php over a fake OpenRegister.
 */
final class RelatedObjectEmbedderTest extends TestCase {

	private const HANDOFF = ['id' => 'handoff-1', 'administrationId' => 'adm-1', 'period' => '2026-09', 'status' => 'klaargezet'];

	private const DECLARATION = [
		'mutations' => ['schema' => 'humaniq/PayrollHandoffMutation', 'filters' => ['handoffId' => 'id']],
		'administration' => ['schema' => 'humaniq/hrAdministration', 'filters' => ['administrationId' => 'administrationId'], 'single' => true],
	];

	/**
	 * Every findAll() config the embedder sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The embedder over a register holding the given rows per schema.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return RelatedObjectEmbedder
	 */
	private function embedder(array $rows): RelatedObjectEmbedder {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->queries[] = $config + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$results = [];
				foreach (($rows[$schema] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) === $filters) {
						$entity = new ObjectEntity();
						$entity->setObject($row);
						$results[] = $entity;
					}
				}

				return ['results' => array_slice($results, 0, $config['limit'])];
			}
		);

		return new RelatedObjectEmbedder(objectService: $objects);
	}//end embedder()

	/**
	 * The mutations pointing at the handoff become a list, the administration one object.
	 *
	 * @return void
	 */
	public function testTheRelatedObjectsAreEmbedded(): void {
		$embedded = $this->embedder(
			rows: [
				'PayrollHandoffMutation' => [
					['handoffId' => 'handoff-1', 'kind' => 'salary'],
					['handoffId' => 'other', 'kind' => 'hours'],
					['handoffId' => 'handoff-1', 'kind' => 'bank-account'],
				],
				'hrAdministration' => [['administrationId' => 'adm-1', 'loonheffingennummer' => '812345671L01']],
			]
		)->embed(declaration: self::DECLARATION, object: self::HANDOFF);

		$this->assertSame(['salary', 'bank-account'], array_column($embedded['mutations'], 'kind'));
		$this->assertSame('812345671L01', $embedded['administration']['loonheffingennummer']);
		$this->assertSame('klaargezet', $embedded['status'], 'The pushed object keeps its own fields.');
		$this->assertSame('humaniq', $this->queries[0]['filters']['register']);
		$this->assertFalse($this->queries[0]['_rbac'], 'An administrator wrote the synchronization; the read is a system read.');
	}//end testTheRelatedObjectsAreEmbedded()

	/**
	 * A filter that reads nothing embeds nothing and never asks for the whole schema.
	 *
	 * @return void
	 */
	public function testAFilterThatReadsNothingNeverWidensTheQuery(): void {
		$embedded = $this->embedder(rows: ['PayrollHandoffMutation' => [['handoffId' => 'handoff-1']]])->embed(
			declaration: self::DECLARATION,
			object: ['status' => 'klaargezet', 'administrationId' => '']
		);

		$this->assertSame([], $embedded['mutations']);
		$this->assertNull($embedded['administration']);
		$this->assertSame([], $this->queries, 'No query without every filter value.');
	}//end testAFilterThatReadsNothingNeverWidensTheQuery()

	/**
	 * `id` falls back to the object's own uuid when the serialized object carries it under @self.
	 *
	 * @return void
	 */
	public function testTheIdFallsBackToTheObjectsOwnUuid(): void {
		$embedded = $this->embedder(rows: ['PayrollHandoffMutation' => [['handoffId' => 'handoff-9', 'kind' => 'start']]])->embed(
			declaration: ['mutations' => self::DECLARATION['mutations']],
			object: ['@self' => ['id' => 'handoff-9']]
		);

		$this->assertSame('start', $embedded['mutations'][0]['kind']);
	}//end testTheIdFallsBackToTheObjectsOwnUuid()

	/**
	 * A single relation with several matches is ambiguous and embeds null.
	 *
	 * @return void
	 */
	public function testAnAmbiguousSingleRelationEmbedsNull(): void {
		$embedded = $this->embedder(
			rows: ['hrAdministration' => [['administrationId' => 'adm-1'], ['administrationId' => 'adm-1']]]
		)->embed(declaration: ['administration' => self::DECLARATION['administration']], object: self::HANDOFF);

		$this->assertNull($embedded['administration']);
	}//end testAnAmbiguousSingleRelationEmbedsNull()

	/**
	 * More matches than the cap refuse the push instead of sending a partial list.
	 *
	 * @return void
	 */
	public function testMoreMatchesThanTheCapRefuseThePush(): void {
		$rows = array_fill(0, (RelatedObjectEmbedder::MAX_RELATED + 1), ['handoffId' => 'handoff-1']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('partial list');
		$this->embedder(rows: ['PayrollHandoffMutation' => $rows])->embed(declaration: ['mutations' => self::DECLARATION['mutations']], object: self::HANDOFF);
	}//end testMoreMatchesThanTheCapRefuseThePush()

	/**
	 * A relation without a register/schema or without filters is refused, naming the property.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('embedRelated "mutations"');
		$this->embedder(rows: [])->embed(declaration: ['mutations' => ['schema' => 'PayrollHandoffMutation', 'filters' => []]], object: self::HANDOFF);
	}//end testAMalformedDeclarationIsRefused()

	/**
	 * No declaration leaves the object as it is.
	 *
	 * @return void
	 */
	public function testNoDeclarationLeavesTheObjectAlone(): void {
		$embedder = $this->embedder(rows: []);

		$this->assertSame(self::HANDOFF, $embedder->embed(declaration: null, object: self::HANDOFF));
		$this->assertSame(self::HANDOFF, $embedder->embed(declaration: [], object: self::HANDOFF));
		$this->assertSame([], $this->queries);
	}//end testNoDeclarationLeavesTheObjectAlone()
}//end class
