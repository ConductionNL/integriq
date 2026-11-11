<?php

/**
 * Characterisation tests for InlineSecretMigrationPlanner::listUuids().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the listing query, which rows become uuid => name entries, and the
 * secret-free log of a failed listing.
 */
class InlineSecretMigrationListingTest extends TestCase {

	/**
	 * Every findAll() config.
	 *
	 * @var list<array>
	 */
	private array $configs = [];

	/**
	 * Every error logged, as [message, context].
	 *
	 * @var list<array>
	 */
	private array $errors = [];

	/**
	 * A planner whose object service answers $answer (or throws it).
	 *
	 * @param mixed $answer The findAll() answer, or a Throwable.
	 *
	 * @return InlineSecretMigrationPlanner
	 */
	private function planner(mixed $answer): InlineSecretMigrationPlanner {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($answer) {
				$this->configs[] = $config;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new InlineSecretMigrationPlanner($objects, $logger);

	}//end planner()

	/**
	 * Entities with a uuid list as uuid => name (the uuid without a name);
	 * other rows are skipped; a bare list works like a results envelope.
	 *
	 * @return void
	 */
	public function testEntitiesWithAUuidAreListedByName(): void {
		$rows = [
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'BRP'], 'u-1'),
			'not-an-entity',
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'nameless uuid'], ''),
			ObjectServiceMockBuilder::objectEntity($this, [], 'u-2'),
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 7], 'u-3'),
		];

		$this->assertSame(['u-1' => 'BRP', 'u-2' => 'u-2', 'u-3' => '7'], $this->planner(['results' => $rows])->listUuids(schema: 'sender_identity', limit: 25));
		$this->assertSame(['u-1' => 'BRP', 'u-2' => 'u-2', 'u-3' => '7'], $this->planner($rows)->listUuids(schema: 'source', limit: 3));
		$this->assertSame(
			[
				['limit' => 25, 'filters' => ['register' => 'integriq', 'schema' => 'sender_identity']],
				['limit' => 3, 'filters' => ['register' => 'integriq', 'schema' => 'source']],
			],
			$this->configs
		);
		$this->assertSame([], $this->errors);

	}//end testEntitiesWithAUuidAreListedByName()

	/**
	 * A failed listing is logged by class only and lists nothing; an answer
	 * that is no list lists nothing.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyListingListsNothing(): void {
		$this->assertSame([], $this->planner(new RuntimeException('secret-ish detail'))->listUuids(schema: 'source', limit: 5));
		$this->assertSame([], $this->planner(['results' => 'broken'])->listUuids(schema: 'source', limit: 5));
		$this->assertSame([['[integriq] inline-secret planner: source listing failed', ['errorClass' => RuntimeException::class]]], $this->errors);

	}//end testAFailedOrEmptyListingListsNothing()

}//end class
