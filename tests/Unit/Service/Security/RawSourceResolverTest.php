<?php

/**
 * The {@see RawSourceResolver} contract (ocon#242).
 *
 * The resolver is the shared form of {@see \OCA\OpenConnector\Service\CallService::resolveSourceForDispatch()}
 * (ocon#236) for the six clients that never reach CallService. Its whole job is one
 * argument — `_render: false` — so these tests assert the ARGUMENTS it passes, not a
 * stub's return value.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Security;

use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenConnector\Tests\Helpers\NestedWriteOnlyRenderBoundaryObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RawSourceResolverTest extends TestCase {

	/**
	 * The paths the fake strips on a rendered read.
	 *
	 * @var array<int, string>
	 */
	private const PATHS = ['configuration.authentication.encryptedToken'];

	/**
	 * A rendered (stripped) entity, as a findAll() row arrives.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The rendered entity.
	 */
	private function renderedEntity(string $uuid = 'source-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['name' => 'Source', 'configuration' => ['authentication' => ['mode' => 'token']]]);
		return $entity;
	}//end renderedEntity()

	/**
	 * A fake holding one raw source.
	 *
	 * @return NestedWriteOnlyRenderBoundaryObjectService The fake.
	 */
	private function fake(): NestedWriteOnlyRenderBoundaryObjectService {
		$fake = new NestedWriteOnlyRenderBoundaryObjectService(self::PATHS);
		$fake->stored['source-1'] = [
			'name' => 'Source',
			'configuration' => ['authentication' => ['mode' => 'token', 'encryptedToken' => 'CIPHERTEXT']],
		];
		return $fake;
	}//end fake()

	/**
	 * The resolver returns the raw source and reads it with `_render: false`.
	 *
	 * @return void
	 */
	public function testReturnsRawSourceAndReadsWithRenderFalse(): void {
		$fake = $this->fake();
		$resolver = new RawSourceResolver($fake, $this->createMock(LoggerInterface::class));

		$raw = $resolver->resolveRaw(source: $this->renderedEntity());

		$this->assertSame(
			'CIPHERTEXT',
			($raw->getObject()['configuration']['authentication']['encryptedToken'] ?? null),
			'The resolver must return the secret-bearing raw source.'
		);

		$this->assertCount(1, $fake->reads);
		$this->assertFalse($fake->reads[0]['_render'], '_render: false is the load-bearing argument.');
		$this->assertTrue($fake->reads[0]['_rbac'], 'The resolver must not widen rbac.');
		$this->assertTrue($fake->reads[0]['_multitenancy'], 'The resolver must not widen multitenancy.');
		$this->assertSame('openconnector', $fake->reads[0]['register']);
		$this->assertSame('source', $fake->reads[0]['schema']);
	}//end testReturnsRawSourceAndReadsWithRenderFalse()

	/**
	 * An unpersisted source (no uuid) is returned as passed, with no read attempted.
	 *
	 * @return void
	 */
	public function testUnpersistedSourceIsReturnedAsPassed(): void {
		$fake = $this->fake();
		$resolver = new RawSourceResolver($fake, $this->createMock(LoggerInterface::class));

		$entity = new ObjectEntity();
		$entity->setObject(['name' => 'In-memory source']);

		$this->assertSame($entity, $resolver->resolveRaw(source: $entity));
		$this->assertSame([], $fake->reads, 'A source with no uuid has nothing to re-read.');
	}//end testUnpersistedSourceIsReturnedAsPassed()

	/**
	 * A raw read that misses falls back to the passed entity rather than throwing.
	 *
	 * @return void
	 */
	public function testMissingRawReadFallsBackToPassedEntity(): void {
		$fake = new NestedWriteOnlyRenderBoundaryObjectService(self::PATHS);
		$resolver = new RawSourceResolver($fake, $this->createMock(LoggerInterface::class));

		$passed = $this->renderedEntity();

		$this->assertSame(
			$passed,
			$resolver->resolveRaw(source: $passed),
			'An unreadable source must never break a dispatch that worked before this hardening.'
		);
	}//end testMissingRawReadFallsBackToPassedEntity()

	/**
	 * A throwing raw read falls back to the passed entity and logs without the secret.
	 *
	 * @return void
	 */
	public function testThrowingRawReadFallsBackAndLogsWithoutSecret(): void {
		$objectService = $this->getMockBuilder(OrObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('find')->willThrowException(new RuntimeException('boom'));

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = ['message' => $message, 'context' => $context];
			}
		);

		$resolver = new RawSourceResolver($objectService, $logger);
		$passed = $this->renderedEntity();

		$this->assertSame($passed, $resolver->resolveRaw(source: $passed));
		$this->assertCount(1, $logged);
		$this->assertArrayNotHasKey(
			'exception',
			$logged[0]['context'],
			'The warning must not carry the exception object — source data can ride along in it.'
		);
		$this->assertSame('source-1', $logged[0]['context']['sourceUuid']);
	}//end testThrowingRawReadFallsBackAndLogsWithoutSecret()
}//end class
