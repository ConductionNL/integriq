<?php

/**
 * Extending input with an external object refuses an unusable reference the same way.
 *
 * Pins RuleService::extendExternalUrl(): an object that fails validation and
 * an object that cannot be fetched each answer 400 "Invalid Input" naming the
 * field, and a fetched object is added to `extendedParameters` and the body's
 * `_extendedInput`, so the shared refusal can move without changing either.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\RuleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for extending input with an external object.
 */
final class RuleExtendExternalUrlGoldenTest extends TestCase {

	/**
	 * Run the rule over a call service that answers or throws as given.
	 *
	 * @param CallService $calls The call service double.
	 *
	 * @return array|JSONResponse
	 */
	private function extend(CallService $calls): array|JSONResponse {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [new ObjectEntity()]]);

		$reflection = new ReflectionClass(RuleService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('orObjectService')->setValue($service, $objects);
		$reflection->getProperty('callService')->setValue($service, $calls);

		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['extend_external_input' => ['validate' => false, 'properties' => [['property' => 'body.case.zaaktype', 'schema' => 7]]]]]);

		return $service->extendExternalUrl(
			rule: $rule,
			data: ['body' => ['case' => ['zaaktype' => 'https://ztc.example/zaaktypen/1']], 'extendedParameters' => ['kept' => true]]
		);

	}//end extend()

	/**
	 * The 400 body for a field.
	 *
	 * @param string $error The error sentence.
	 *
	 * @return array<string, mixed>
	 */
	private function invalid(string $error): array {
		return [
			'message' => 'Invalid Input',
			'error' => $error,
			'errors' => [['name' => 'zaaktype', 'code' => 'invalid-resource', 'reason' => 'The resource is not valid']],
		];

	}//end invalid()

	/**
	 * An object that fails validation answers 400 naming the field.
	 *
	 * @return void
	 */
	public function testAnInvalidObjectIsRefused(): void {
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willThrowException(new ValidationException('Fetched object cannot be validated', 400));

		$response = $this->extend(calls: $calls);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame([400, $this->invalid(error: 'The object referenced in field body.case.zaaktype is not valid')], [$response->getStatus(), $response->getData()]);

	}//end testAnInvalidObjectIsRefused()

	/**
	 * An object that cannot be fetched answers 400 with the failure.
	 *
	 * @return void
	 */
	public function testAnUnfetchableObjectIsRefused(): void {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => 500]);
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturn($log);

		$response = $this->extend(calls: $calls);

		$this->assertSame([400, $this->invalid(error: 'The object on https://ztc.example/zaaktypen/1 could not be fetched')], [$response->getStatus(), $response->getData()]);

	}//end testAnUnfetchableObjectIsRefused()

	/**
	 * A fetched object is added beside the existing extended parameters and into the body.
	 *
	 * @return void
	 */
	public function testAFetchedObjectExtendsTheInput(): void {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => 200, 'response' => ['body' => '{"naam":"Melding"}']]);
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturn($log);

		$data = $this->extend(calls: $calls);

		$extended = ['body' => ['case' => ['zaaktype' => ['naam' => 'Melding']]], 'kept' => true];
		$this->assertSame($extended, $data['extendedParameters']);
		$this->assertSame($extended, $data['body']['_extendedInput']);

	}//end testAFetchedObjectExtendsTheInput()
}//end class
