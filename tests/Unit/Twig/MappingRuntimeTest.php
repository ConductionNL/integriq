<?php

/**
 * The mapping Twig runtime: encoding helpers, slugs, source calls, mapping
 * execution and the synchronization-contract id lookups.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authentication-twig/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Twig;

use JsonSerializable;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Twig\MappingRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

/**
 * Tests for MappingRuntime.
 *
 * @covers \OCA\Integriq\Twig\MappingRuntime
 */
final class MappingRuntimeTest extends TestCase {

	/**
	 * @var MappingServiceInterface&MockObject
	 */
	private MappingServiceInterface $mappingService;

	/**
	 * @var CallServiceInterface&MockObject
	 */
	private CallServiceInterface $callService;

	/**
	 * @var SourceMappingServiceInterface&MockObject
	 */
	private SourceMappingServiceInterface $objectService;

	/**
	 * @var SynchronizationContractServiceInterface&MockObject
	 */
	private SynchronizationContractServiceInterface $contracts;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mappingService = $this->createMock(MappingServiceInterface::class);
		$this->callService    = $this->createMock(CallServiceInterface::class);
		$this->objectService  = $this->createMock(SourceMappingServiceInterface::class);
		$this->contracts      = $this->createMock(SynchronizationContractServiceInterface::class);
	}//end setUp()

	/**
	 * The runtime under test.
	 *
	 * @return MappingRuntime
	 */
	private function runtime(): MappingRuntime {
		return new MappingRuntime(
			$this->mappingService,
			$this->callService,
			$this->createMock(FileService::class),
			$this->objectService,
			$this->contracts,
		);
	}//end runtime()

	/**
	 * An ObjectEntity carrying the given object data.
	 *
	 * @param array $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Base64 encoding round-trips, and JSON decodes to an associative array.
	 *
	 * @return void
	 */
	public function testEncodingHelpers(): void {
		$runtime = $this->runtime();

		$this->assertSame('aGVsbG8gd29ybGQ=', $runtime->b64enc(input: 'hello world'));
		$this->assertSame('hello world', $runtime->b64dec(input: 'aGVsbG8gd29ybGQ='));
		$this->assertSame(['a' => ['b' => 1]], $runtime->json_decode(input: '{"a":{"b":1}}'));
	}//end testEncodingHelpers()

	/**
	 * A slug is lowercase, hyphenated, stripped of other characters and of edge or repeated hyphens.
	 *
	 * @return void
	 */
	public function testCreateSlug(): void {
		$runtime = $this->runtime();

		$this->assertSame('hello-world-2026', $runtime->createSlug(text: '  Hello_World  2026! '));
		$this->assertSame('a-b', $runtime->createSlug(text: '--A---B--'));
		$this->assertSame('', $runtime->createSlug(text: '!!!'));
	}//end testCreateSlug()

	/**
	 * Every generated uuid is a fresh version 4 uuid.
	 *
	 * @return void
	 */
	public function testGenerateUuidIsAFreshV4(): void {
		$runtime = $this->runtime();
		$first   = $runtime->generateUuid();

		$this->assertInstanceOf(UuidV4::class, $first);
		$this->assertNotSame((string) $first, (string) $runtime->generateUuid());
	}//end testGenerateUuidIsAFreshV4()

	/**
	 * executeMapping passes its arguments through and returns the mapped result.
	 *
	 * @return void
	 */
	public function testExecuteMappingDelegates(): void {
		$this->mappingService->expects($this->once())->method('executeMapping')
			->with(12, ['in' => 1], true)
			->willReturn(['out' => 2]);

		$this->assertSame(['out' => 2], $this->runtime()->executeMapping(mapping: 12, input: ['in' => 1], list: true));
	}//end testExecuteMappingDelegates()

	/**
	 * callSource reads the source in system context, strips its location from an absolute
	 * endpoint and returns the response body.
	 *
	 * @return void
	 */
	public function testCallSourceStripsTheLocationAndReturnsTheBody(): void {
		$source   = $this->entity(data: ['location' => 'https://api.example/v2']);
		$registry = $this->createMock(ORObjectService::class);
		$registry->expects($this->once())->method('find')
			->with('src-1', 'integriq', 'source', false, false)
			->willReturn($source);
		$this->objectService->method('getOpenRegisters')->willReturn($registry);
		$this->callService->expects($this->once())->method('call')
			->with($source, '/zaken?page=2', 'POST', ['headers' => ['X' => 'y']])
			->willReturn($this->entity(data: ['response' => ['body' => '{"ok":true}']]));

		$body = $this->runtime()->callSource(
			sourceId: 'src-1',
			endpoint: 'https://api.example/v2/zaken?page=2',
			method: 'POST',
			configuration: ['headers' => ['X' => 'y']]
		);

		$this->assertSame('{"ok":true}', $body);
	}//end testCallSourceStripsTheLocationAndReturnsTheBody()

	/**
	 * A relative endpoint is passed on as it is, and a response without a body gives ''.
	 *
	 * @return void
	 */
	public function testCallSourceKeepsARelativeEndpointAndDefaultsToAnEmptyBody(): void {
		$source   = $this->entity(data: ['location' => 'https://api.example']);
		$registry = $this->createMock(ORObjectService::class);
		$registry->method('find')->willReturn($source);
		$this->objectService->method('getOpenRegisters')->willReturn($registry);
		$this->callService->expects($this->once())->method('call')
			->with($source, '/status', 'GET', [])
			->willReturn($this->entity(data: ['response' => ['status' => 204]]));

		$this->assertSame('', $this->runtime()->callSource(sourceId: 'src-1', endpoint: '/status'));
	}//end testCallSourceKeepsARelativeEndpointAndDefaultsToAnEmptyBody()

	/**
	 * With a synchronization id the target comes from that synchronization's contract.
	 *
	 * @return void
	 */
	public function testGetTargetIdByOriginIdWithinASynchronization(): void {
		$this->contracts->expects($this->exactly(3))->method('findBySyncAndOrigin')
			->with('sync-1', 'origin-1')
			->willReturnOnConsecutiveCalls(['targetId' => 'target-1'], ['originId' => 'origin-1'], null);
		$this->contracts->expects($this->never())->method('findTargetIdByOriginId');
		$runtime = $this->runtime();

		$this->assertSame('target-1', $runtime->getTargetIdByOriginId(originId: 'origin-1', synchronizationId: 'sync-1'));
		$this->assertNull($runtime->getTargetIdByOriginId(originId: 'origin-1', synchronizationId: 'sync-1'));
		$this->assertNull($runtime->getTargetIdByOriginId(originId: 'origin-1', synchronizationId: 'sync-1'));
	}//end testGetTargetIdByOriginIdWithinASynchronization()

	/**
	 * Without a synchronization id the target is looked up across all contracts.
	 *
	 * @return void
	 */
	public function testGetTargetIdByOriginIdAcrossSynchronizations(): void {
		$this->contracts->expects($this->never())->method('findBySyncAndOrigin');
		$this->contracts->expects($this->once())->method('findTargetIdByOriginId')->with('origin-1')->willReturn('target-9');

		$this->assertSame('target-9', $this->runtime()->getTargetIdByOriginId(originId: 'origin-1'));
	}//end testGetTargetIdByOriginIdAcrossSynchronizations()

	/**
	 * The origin comes from the first matching contract, filtered by synchronization when given.
	 *
	 * @return void
	 */
	public function testGetOriginIdByTargetId(): void {
		$first = $this->createMock(JsonSerializable::class);
		$first->method('jsonSerialize')->willReturn(['originId' => 'origin-7']);
		$second = $this->createMock(JsonSerializable::class);
		$second->expects($this->never())->method('jsonSerialize');

		$filters = [];
		$this->contracts->expects($this->exactly(2))->method('findAllObjects')
			->willReturnCallback(
				static function (array $given) use (&$filters, $first, $second): array {
					$filters[] = $given;
					return [$first, $second];
				}
			);
		$runtime = $this->runtime();

		$this->assertSame('origin-7', $runtime->getOriginIdByTargetId(targetId: 'target-7', synchronizationId: 'sync-1'));
		$this->assertSame('origin-7', $runtime->getOriginIdByTargetId(targetId: 'target-7'));
		$this->assertSame(
			[['targetId' => 'target-7', 'synchronizationId' => 'sync-1'], ['targetId' => 'target-7']],
			$filters
		);
	}//end testGetOriginIdByTargetId()

	/**
	 * No contract, or a contract with an empty or missing origin, gives null.
	 *
	 * @return void
	 */
	public function testGetOriginIdByTargetIdWithoutAnOrigin(): void {
		$empty = $this->createMock(JsonSerializable::class);
		$empty->method('jsonSerialize')->willReturn(['originId' => '']);
		$missing = $this->createMock(JsonSerializable::class);
		$missing->method('jsonSerialize')->willReturn(['targetId' => 'target-7']);
		$this->contracts->method('findAllObjects')->willReturnOnConsecutiveCalls([], [$empty], [$missing]);
		$runtime = $this->runtime();

		$this->assertNull($runtime->getOriginIdByTargetId(targetId: 'target-7'));
		$this->assertNull($runtime->getOriginIdByTargetId(targetId: 'target-7'));
		$this->assertNull($runtime->getOriginIdByTargetId(targetId: 'target-7'));
	}//end testGetOriginIdByTargetIdWithoutAnOrigin()
}//end class
