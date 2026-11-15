<?php

/**
 * Unit tests for SyncDeadLetterController's filters, detail view and discard actions.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SyncDeadLetterController;
use OCA\Integriq\Service\SyncItemDeadLetterServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The dead-letter list's status, synchronization and time filters, the detail with its synchronization, and discarding.
 *
 * @covers \OCA\Integriq\Controller\SyncDeadLetterController
 * @uses   \OCA\Integriq\Controller\TimeWindow
 * @uses   \OCA\Integriq\Controller\DeadLetterActions
 * @uses   \OCA\Integriq\Controller\DeadLetterWording
 */
final class SyncDeadLetterControllerDetailTest extends TestCase {

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var SyncItemDeadLetterServiceInterface&MockObject */
	private SyncItemDeadLetterServiceInterface $deadLetters;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects     = $this->createMock(OrObjectService::class);
		$this->deadLetters = $this->createMock(SyncItemDeadLetterServiceInterface::class);
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return SyncDeadLetterController
	 */
	private function controller(array $params = []): SyncDeadLetterController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SyncDeadLetterController('integriq', $request, $this->objects, $this->deadLetters, $l, $session);
	}//end controller()

	/**
	 * The list filters on the given statuses and synchronization, pages, and keeps only matching entries.
	 *
	 * @return void
	 */
	public function testIndexFiltersOnStatusesAndSynchronization(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with([
				'filters' => ['register' => 'integriq', 'schema' => 'sync_item_dead_letter', 'status' => ['failed', 'replayed'], 'synchronization' => '42'],
				'limit'   => 10,
				'offset'  => 5,
			])
			->willReturn([
				ObjectServiceMockBuilder::objectEntity($this, ['id' => 'a', 'status' => 'replayed', 'error' => 'short']),
				ObjectServiceMockBuilder::objectEntity($this, ['id' => 'b', 'status' => 'discarded']),
				ObjectServiceMockBuilder::objectEntity($this, ['id' => 'c', 'status' => 'failed', 'error' => str_repeat('x', 250)]),
			]);

		$data = $this->controller(['status' => ' failed, ,replayed ', 'synchronizationId' => 42, 'limit' => '10', 'offset' => '5'])->index()->getData();

		$this->assertSame(2, $data['total']);
		$this->assertSame(['a', 'c'], array_column($data['results'], 'id'));
		$this->assertSame('short', $data['results'][0]['errorPreview']);
		$this->assertSame(str_repeat('x', 200).'…', $data['results'][1]['errorPreview']);
	}//end testIndexFiltersOnStatusesAndSynchronization()

	/**
	 * Within a time window only entries created inside it, with a readable time, are listed.
	 *
	 * @return void
	 */
	public function testIndexKeepsOnlyEntriesInsideTheTimeWindow(): void {
		$this->objects->method('findAll')->willReturn(['results' => [
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'early', 'status' => 'failed', 'created' => '2026-01-01T00:00:00Z']),
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'inside', 'status' => 'failed', 'created' => '2026-01-15T00:00:00Z']),
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'late', 'status' => 'failed', 'created' => '2026-02-15T00:00:00Z']),
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'undated', 'status' => 'failed']),
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'garbled', 'status' => 'failed', 'created' => 'not a date']),
		]]);

		$data = $this->controller(['from' => '2026-01-10T00:00:00Z', 'to' => '2026-01-31T00:00:00Z'])->index()->getData();

		$this->assertSame(['inside'], array_column($data['results'], 'id'));
		$this->assertSame('', $data['results'][0]['errorPreview']);
	}//end testIndexKeepsOnlyEntriesInsideTheTimeWindow()

	/**
	 * The detail names the entry's synchronization by name, source and target.
	 *
	 * @return void
	 */
	public function testShowIncludesTheSynchronization(): void {
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			if ($args[0] === 'dl-1') {
				return ObjectServiceMockBuilder::objectEntity($this, ['id' => 'dl-1', 'synchronization' => 's-1'], 'dl-1');
			}
			$this->assertSame('s-1', $args[0]);
			return ObjectServiceMockBuilder::objectEntity($this, ['name' => 'KVK', 'sourceId' => 'src', 'targetId' => 'tgt', 'other' => 'x'], 's-1');
		});

		$data = $this->controller()->show('dl-1')->getData();

		$this->assertSame(['id' => 'dl-1', 'synchronization' => 's-1'], $data['entry']);
		$this->assertSame(['name' => 'KVK', 'sourceId' => 'src', 'targetId' => 'tgt'], $data['synchronization']);
	}//end testShowIncludesTheSynchronization()

	/**
	 * Without a synchronization, or with one that is gone, the detail has none.
	 *
	 * @return void
	 */
	public function testShowWithoutAKnownSynchronization(): void {
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			return match ($args[0]) {
				'dl-1' => ObjectServiceMockBuilder::objectEntity($this, ['synchronization' => ''], 'dl-1'),
				'dl-2' => ObjectServiceMockBuilder::objectEntity($this, ['synchronization' => 's-gone'], 'dl-2'),
				default => throw new DoesNotExistException('gone'),
			};
		});
		$controller = $this->controller();

		$this->assertNull($controller->show('dl-1')->getData()['synchronization']);
		$this->assertNull($controller->show('dl-2')->getData()['synchronization']);
	}//end testShowWithoutAKnownSynchronization()

	/**
	 * The detail of an unknown entry answers 404.
	 *
	 * @return void
	 */
	public function testShowOfAnUnknownEntryIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->show('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Entry not found'], $response->getData());
	}//end testShowOfAnUnknownEntryIs404()

	/**
	 * Discarding one entry discards it as the signed-in user and answers it.
	 *
	 * @return void
	 */
	public function testDiscardDiscardsAsTheUser(): void {
		$this->deadLetters->expects($this->once())->method('discardMessage')->with('dl-1', 'admin')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], 'dl-1'));

		$this->assertSame(['status' => 'discarded'], $this->controller()->discard('dl-1')->getData());
	}//end testDiscardDiscardsAsTheUser()

	/**
	 * A bulk discard needs a list of ids.
	 *
	 * @return void
	 */
	public function testBulkDiscardNeedsAList(): void {
		$this->deadLetters->expects($this->never())->method('discardMessage');

		$response = $this->controller(['ids' => 'dl-1'])->bulkDiscard();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'ids must be an array of entry UUIDs'], $response->getData());
	}//end testBulkDiscardNeedsAList()

	/**
	 * A bulk discard discards each listed entry.
	 *
	 * @return void
	 */
	public function testBulkDiscardDiscardsEachEntry(): void {
		$this->deadLetters->expects($this->exactly(2))->method('discardMessage')
			->willReturnCallback(fn (string $id) => ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], $id));

		$response = $this->controller(['ids' => ['dl-1', 'dl-2']])->bulkDiscard();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => ['dl-1' => 'ok', 'dl-2' => 'ok']], $response->getData());
	}//end testBulkDiscardDiscardsEachEntry()
}//end class
