<?php

/**
 * Unit tests for RuleService's external-input extension.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistryInterface;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A rule that extends its input with the objects its fields point at:
 * the source is found (or created without credentials, as the engine),
 * each URL is fetched, and a failed fetch answers 400 for that field.
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
#[CoversClass(RuleService::class)]
class RuleServiceExternalInputTest extends TestCase {

	/**
	 * @var MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * @var RuleService
	 */
	private RuleService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->callService = $this->createMock(CallService::class);
		$this->service = new RuleService(
			$this->createMock(ObjectService::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->callService,
			$this->orObjectService,
			$this->createMock(EndpointRulePluginRegistryInterface::class),
		);

	}//end setUp()

	/**
	 * A rule extending the given properties, without validation.
	 *
	 * @param array<int, string> $properties The dotted properties holding URLs.
	 *
	 * @return ObjectEntity
	 */
	private function rule(array $properties): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'configuration' => [
					'extend_external_input' => [
						'validate' => false,
						'properties' => array_map(static fn (string $property): array => ['property' => $property, 'schema' => 7], $properties),
					],
				],
			],
			'rule-1'
		);

	}//end rule()

	/**
	 * A call log answering the given status with the given JSON body.
	 *
	 * @param int $status The status code.
	 * @param array $body The decoded body.
	 *
	 * @return ObjectEntity
	 */
	private function answer(int $status, array $body): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => $status, 'response' => ['body' => json_encode($body)]], 'call-log');

	}//end answer()

	/**
	 * An existing source is reused, read as the engine, and its object lands in the input.
	 *
	 * @return void
	 */
	public function testAnExistingSourceIsReusedAndTheObjectAdded(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://api.example.test/zaken/1'], 'src-1');
		$this->orObjectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'source', 'location' => 'https://api.example.test/zaken/1']], false, false)
			->willReturn(['results' => [$source]]);
		$this->orObjectService->expects($this->never())->method('saveObject');
		$this->callService->expects($this->once())->method('call')->with($source)->willReturn($this->answer(200, ['id' => 'zaak-1']));

		$result = $this->service->extendExternalUrl(
			rule: $this->rule(['body.zaak']),
			data: ['body' => ['zaak' => 'https://api.example.test/zaken/1']]
		);

		$this->assertSame(['body' => ['zaak' => ['id' => 'zaak-1']]], $result['extendedParameters']);
		$this->assertSame($result['extendedParameters'], $result['body']['_extendedInput']);
	}//end testAnExistingSourceIsReusedAndTheObjectAdded()

	/**
	 * An unknown URL gets a credential-less API source created for it, as the engine.
	 *
	 * @return void
	 */
	public function testAnUnknownUrlGetsACredentialLessSource(): void {
		$created = ObjectServiceMockBuilder::objectEntity($this, [], 'src-new');
		$this->orObjectService->method('findAll')->willReturn(['results' => []]);
		$this->orObjectService->expects($this->once())->method('saveObject')
			->with(
				['location' => 'https://api.example.test/zaken/2', 'name' => '2', 'type' => 'api', 'isEnabled' => true],
				'integriq',
				'source',
				false,
				false
			)
			->willReturn($created);
		$this->callService->expects($this->once())->method('call')->with($created)->willReturn($this->answer(200, ['id' => 'zaak-2']));

		$result = $this->service->extendExternalUrl(rule: $this->rule(['zaak']), data: ['zaak' => 'https://api.example.test/zaken/2']);

		$this->assertSame(['zaak' => ['id' => 'zaak-2']], $result['extendedParameters']);
	}//end testAnUnknownUrlGetsACredentialLessSource()

	/**
	 * A failed fetch answers 400 naming the field.
	 *
	 * @return void
	 */
	public function testAFailedFetchAnswersInvalidInputForTheField(): void {
		$this->orObjectService->method('findAll')->willReturn([ObjectServiceMockBuilder::objectEntity($this, [], 'src-1')]);
		$this->callService->method('call')->willReturn($this->answer(404, []));

		$result = $this->service->extendExternalUrl(rule: $this->rule(['body.zaak']), data: ['body' => ['zaak' => 'https://api.example.test/zaken/9']]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
		$this->assertSame(
			[
				'message' => 'Invalid Input',
				'error' => 'The object on https://api.example.test/zaken/9 could not be fetched',
				'errors' => [['name' => 'zaak', 'code' => 'invalid-resource', 'reason' => 'The resource is not valid']],
			],
			$result->getData()
		);
	}//end testAFailedFetchAnswersInvalidInputForTheField()

	/**
	 * Extended parameters already on the input win over a freshly fetched object.
	 *
	 * @return void
	 */
	public function testExistingExtendedParametersWin(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [ObjectServiceMockBuilder::objectEntity($this, [], 'src-1')]]);
		$this->callService->method('call')->willReturn($this->answer(200, ['id' => 'fresh']));

		$result = $this->service->extendExternalUrl(
			rule: $this->rule(['zaak', 'besluit']),
			data: [
				'zaak' => 'https://api.example.test/zaken/1',
				'besluit' => 'https://api.example.test/besluiten/1',
				'extendedParameters' => ['zaak' => 'kept'],
			]
		);

		$this->assertSame(['zaak' => 'kept', 'besluit' => ['id' => 'fresh']], $result['extendedParameters']);
		$this->assertSame($result['extendedParameters'], $result['body']['_extendedInput']);
	}//end testExistingExtendedParametersWin()
}//end class
