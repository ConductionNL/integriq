<?php

/**
 * How DsoConnection lists and re-reads its consumers, and what it hands the
 * signature check when the stored trust is unusable.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * DsoConnection::findConsumers() and its raw re-read.
 */
#[CoversClass(DsoConnection::class)]
class DsoConnectionConsumerReadTest extends TestCase {

	private ORObjectService&MockObject $objectService;

	private DSOSignatureVerifierService&MockObject $verifier;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every find() call, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$this->verifier = $this->createMock(DSOSignatureVerifierService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The connection over the doubles.
	 *
	 * @return DsoConnection
	 */
	private function connection(): DsoConnection {
		return new DsoConnection(
			objectService: $this->objectService,
			signatureVerifier: $this->verifier,
			userManager: $this->createMock(IUserManager::class),
			rights: $this->createMock(DsoAccountRights::class),
			logger: $this->logger
		);

	}//end connection()

	/**
	 * A consumer entity.
	 *
	 * @param string               $uuid The uuid, possibly empty.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function consumer(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end consumer()

	/**
	 * Let findAll() list these, and record the find() calls answered by $answer.
	 *
	 * @param array<int, mixed> $listed The listed results.
	 * @param callable          $answer Gives the find() result for a uuid.
	 * @param bool              $wrap   Whether findAll() wraps them in `results`.
	 *
	 * @return void
	 */
	private function listing(array $listed, callable $answer, bool $wrap = true): void {
		$this->objectService->method('findAll')->willReturn($wrap === true ? ['results' => $listed] : $listed);
		$this->objectService->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) use ($answer): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy, '_render' => $_render];
				return $answer((string)$id);
			}
		);

	}//end listing()

	/**
	 * Only entities of the asked type are kept, matched without regard to case, each re-read raw as an engine read.
	 *
	 * @return void
	 */
	public function testKeepsOnlyEntitiesOfTheTypeAndReReadsEachRaw(): void {
		$listed = [
			new stdClass(),
			$this->consumer('of-1', ['authorizationType' => 'open-formulieren']),
			$this->consumer('dso-1', ['authorizationType' => 'DSO-STAM']),
			$this->consumer('none', []),
		];
		$raw = $this->consumer('dso-1', ['authorizationType' => 'dso-stam', 'authorizationConfiguration' => ['hmacSecret' => 's']]);
		$this->listing($listed, fn (string $uuid): ObjectEntity => $raw);

		$consumers = $this->connection()->findConsumers();

		$this->assertSame([$raw], $consumers);
		$this->assertCount(1, $this->finds);
		$this->assertSame('dso-1', $this->finds[0]['id']);
		$this->assertFalse($this->finds[0]['_rbac']);
		$this->assertFalse($this->finds[0]['_multitenancy']);
		$this->assertFalse($this->finds[0]['_render']);

	}//end testKeepsOnlyEntitiesOfTheTypeAndReReadsEachRaw()

	/**
	 * Another authorization type is asked for by name, and an unwrapped listing is read too.
	 *
	 * @return void
	 */
	public function testFindsAnotherTypeFromAnUnwrappedListing(): void {
		$of = $this->consumer('of-1', ['authorizationType' => 'open-formulieren']);
		$this->listing([$of, $this->consumer('dso-1', ['authorizationType' => 'dso-stam'])], fn (string $uuid): ObjectEntity => $of, false);

		$this->assertSame([$of], $this->connection()->findConsumers(authorizationType: 'Open-Formulieren'));

	}//end testFindsAnotherTypeFromAnUnwrappedListing()

	/**
	 * A listed consumer without a uuid is used as listed, without a re-read.
	 *
	 * @return void
	 */
	public function testAConsumerWithoutUuidIsUsedAsListed(): void {
		$listed = $this->consumer('', ['authorizationType' => 'dso-stam']);
		$this->listing([$listed], fn (string $uuid): ObjectEntity => $this->fail('no re-read expected'));

		$this->assertSame([$listed], $this->connection()->findConsumers());
		$this->assertSame([], $this->finds);

	}//end testAConsumerWithoutUuidIsUsedAsListed()

	/**
	 * A failing re-read falls back to the listed consumer and says so, naming only the error class.
	 *
	 * @return void
	 */
	public function testAFailedReReadFallsBackToTheListedConsumer(): void {
		$listed = $this->consumer('dso-1', ['authorizationType' => 'dso-stam']);
		$this->listing([$listed], fn (string $uuid) => throw new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DsoConnection] raw consumer re-read failed; using the listed entity',
			['consumerUuid' => 'dso-1', 'errorClass' => RuntimeException::class]
		);

		$this->assertSame([$listed], $this->connection()->findConsumers());

	}//end testAFailedReReadFallsBackToTheListedConsumer()

	/**
	 * A re-read that finds nothing falls back to the listed consumer.
	 *
	 * @return void
	 */
	public function testAnEmptyReReadFallsBackToTheListedConsumer(): void {
		$listed = $this->consumer('dso-1', ['authorizationType' => 'dso-stam']);
		$this->listing([$listed], fn (string $uuid): ?ObjectEntity => null);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([$listed], $this->connection()->findConsumers());
		$this->assertCount(1, $this->finds);

	}//end testAnEmptyReReadFallsBackToTheListedConsumer()

	/**
	 * A trust that is not an array reaches the signature check as an empty trust, and refuses.
	 *
	 * @return void
	 */
	public function testAnUnusableTrustIsCheckedAsEmpty(): void {
		$consumer = $this->consumer('dso-1', ['authorizationType' => 'dso-stam', 'authorizationConfiguration' => 'hmac:secret', 'userId' => 'dso-intake']);
		$this->listing([$consumer], fn (string $uuid): ObjectEntity => $consumer);
		$this->verifier->expects($this->once())->method('verify')
			->with(signatureHeader: 'sha256=abc', rawBody: '{}', trust: [])
			->willReturn(false);

		$this->expectException(DsoSignatureException::class);
		$this->connection()->authenticate(rawBody: '{}', signatureHeader: 'sha256=abc');

	}//end testAnUnusableTrustIsCheckedAsEmpty()
}//end class
