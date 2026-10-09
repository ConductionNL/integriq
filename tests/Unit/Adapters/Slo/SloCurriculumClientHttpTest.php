<?php

/**
 * Unit tests for the live SLO curriculum client.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\SloCurriculumClientHttp;
use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The live client goes through CallService with the seeded source.
 */
class SloCurriculumClientHttpTest extends TestCase {
	/**
	 * @var CallService&MockObject
	 */
	private CallService $callService;

	/**
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService $objectService;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->callService = $this->createMock(CallService::class);
		$this->objectService = $this->createMock(OrObjectService::class);
	}//end setUp()

	/**
	 * @param array<string,mixed> $object The entity payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * @return void
	 */
	private function seedSource(): ObjectEntity {
		$source = $this->entity(['slug' => 'slo-curriculum', 'location' => 'https://opendata.slo.nl/curriculum/api/v1']);
		$this->objectService->method('findAll')->willReturn(['results' => [$this->entity(['slug' => 'other']), 'junk', $source]]);
		return $source;
	}//end seedSource()

	/**
	 * @return void
	 */
	public function testSendsTheAcceptHeaderThroughTheSeededSource(): void {
		$source = $this->seedSource();
		$this->callService->expects($this->once())
			->method('call')
			->with(
				$source,
				'/tree/abc',
				'GET',
				['query' => [], 'headers' => ['Accept' => 'application/jsontag'], 'logBody' => true]
			)
			->willReturn($this->entity(['statusCode' => 200, 'response' => ['body' => '<object class="X">{}']]));

		$client = new SloCurriculumClientHttp($this->callService, $this->objectService);

		$this->assertSame('<object class="X">{}', $client->fetch('tree/abc', [], 'application/jsontag'));
		$this->assertSame('https', $client->flavour());
	}//end testSendsTheAcceptHeaderThroughTheSeededSource()

	/**
	 * @return void
	 */
	public function testAnErrorStatusIsNotAResult(): void {
		$this->seedSource();
		$this->callService->method('call')->willReturn($this->entity(['statusCode' => 401, 'response' => ['body' => '']]));

		try {
			(new SloCurriculumClientHttp($this->callService, $this->objectService))->fetch('kerndoel/');
			$this->fail('A 401 must throw.');
		} catch (SloCurriculumException $exception) {
			$this->assertSame(401, $exception->getStatus());
		}
	}//end testAnErrorStatusIsNotAResult()

	/**
	 * @return void
	 */
	public function testADecodedBodyIsReEncodedAndAMissingBodyThrows(): void {
		$this->seedSource();
		$this->callService->method('call')->willReturnOnConsecutiveCalls(
			$this->entity(['statusCode' => 200, 'response' => ['body' => ['data' => []]]]),
			$this->entity(['response' => ['statusCode' => 200]])
		);
		$client = new SloCurriculumClientHttp($this->callService, $this->objectService);

		$this->assertSame('{"data":[]}', $client->fetch('examenprogramma', ['page' => 0]));
		$this->expectException(SloCurriculumException::class);
		$client->fetch('examenprogramma', ['page' => 1]);
	}//end testADecodedBodyIsReEncodedAndAMissingBodyThrows()

	/**
	 * @return void
	 */
	public function testATransportFailureIsWrapped(): void {
		$this->seedSource();
		$this->callService->method('call')->willThrowException(new RuntimeException('circuit open'));

		$this->expectException(SloCurriculumException::class);
		$this->expectExceptionMessage('circuit open');
		(new SloCurriculumClientHttp($this->callService, $this->objectService))->fetch('kerndoel/');
	}//end testATransportFailureIsWrapped()

	/**
	 * @return void
	 */
	public function testAMissingSourceIsNamed(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$this->expectException(SloCurriculumException::class);
		$this->expectExceptionMessage('slo-curriculum');
		(new SloCurriculumClientHttp($this->callService, $this->objectService))->fetch('kerndoel/');
	}//end testAMissingSourceIsNamed()

	/**
	 * The source is resolved once per client.
	 *
	 * @return void
	 */
	public function testTheSourceIsResolvedOnce(): void {
		$source = $this->entity(['slug' => 'slo-curriculum']);
		$this->objectService->expects($this->once())->method('findAll')->willReturn([$source]);
		$this->callService->method('call')->willReturn($this->entity(['statusCode' => 200, 'response' => ['body' => '{}']]));
		$client = new SloCurriculumClientHttp($this->callService, $this->objectService);

		$client->fetch('a');
		$client->fetch('b');
		$this->addToAssertionCount(1);
	}//end testTheSourceIsResolvedOnce()
}//end class
