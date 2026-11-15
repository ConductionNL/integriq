<?php

/**
 * EndpointService: ZGW validation messages and the reduced extend keys.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The private helpers of EndpointService that need no collaborator.
 */
#[CoversClass(EndpointService::class)]
class EndpointServiceMessageAndExtendHelpersTest extends TestCase {

	/**
	 * Invoke a private method on an instance built without its constructor.
	 *
	 * @param string $name The method.
	 * @param mixed  ...$args Its arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, mixed ...$args): mixed {
		$reflection = new ReflectionClass(EndpointService::class);

		return $reflection->getMethod($name)->invoke($reflection->newInstanceWithoutConstructor(), ...$args);

	}//end call()

	/**
	 * Missing required properties become one required invalid parameter each.
	 *
	 * @return void
	 */
	public function testMissingPropertiesBecomeRequiredInvalidParams(): void {
		$message = 'The required properties (bronorganisatie, zaaktype ,startdatum) are missing';

		$response = $this->call(
			'parseMessage',
			['title' => 'Bad request'],
			['message' => 'Validation failed', 'errors' => [['message' => $message]]]
		);

		$reason = 'The required property is missing';
		$this->assertSame(
			[
				'title' => 'Bad request',
				'detail' => $message,
				'invalidParams' => [
					['property' => 'bronorganisatie', 'code' => 'required', 'reason' => $reason],
					['property' => 'zaaktype', 'code' => 'required', 'reason' => $reason],
					['property' => 'startdatum', 'code' => 'required', 'reason' => $reason],
				],
			],
			$response
		);

	}//end testMissingPropertiesBecomeRequiredInvalidParams()

	/**
	 * Per-property errors become invalid type or invalid value, by their first message.
	 *
	 * @return void
	 */
	public function testPropertyErrorsBecomeTypeOrValueInvalidParams(): void {
		$response = $this->call(
			'parseMessage',
			[],
			[
				'message' => 'Validation failed',
				'errors' => [[
					'message' => 'The data does not match the schema',
					'errors' => [
						'omschrijving' => ['The data (integer) must match the type: string'],
						'einddatum' => ['The date must be after the start date'],
					],
				]],
			]
		);

		$this->assertSame(
			[
				'detail' => 'The data does not match the schema',
				'invalidParams' => [
					['property' => 'omschrijving', 'code' => 'invalid type', 'reason' => 'The data (integer) must match the type: string'],
					['property' => 'einddatum', 'code' => 'invalid value', 'reason' => 'The date must be after the start date'],
				],
			],
			$response
		);

	}//end testPropertyErrorsBecomeTypeOrValueInvalidParams()

	/**
	 * Other errors are passed on as they are, and a response without errors is unchanged.
	 *
	 * @return void
	 */
	public function testOtherErrorsArePassedOnAsInvalidParams(): void {
		$errors = [['message' => 'Object not found']];

		$this->assertSame(['invalidParams' => $errors], $this->call('parseMessage', [], ['message' => 'Not found', 'errors' => $errors]));
		$this->assertSame(['title' => 'x'], $this->call('parseMessage', ['title' => 'x'], ['message' => 'Validation failed']));

	}//end testOtherErrorsArePassedOnAsInvalidParams()

	/**
	 * Extend keys, as a list or as keys, become one nested tree.
	 *
	 * @return void
	 */
	public function testExtendKeysBecomeANestedTree(): void {
		$this->assertSame([], $this->call('reduceExtendKeys', []));
		$this->assertSame(
			['zaaktype' => true, 'rollen' => ['betrokkene' => ['adres' => true]], 'status' => ['statustype' => true]],
			$this->call('reduceExtendKeys', ['zaaktype', 'rollen.betrokkene.adres', 'status.statustype'])
		);
		$this->assertSame(
			['eigenschappen' => ['eigenschap' => true], 'resultaat' => true],
			$this->call('reduceExtendKeys', ['eigenschappen.eigenschap' => [], 'resultaat' => []])
		);
		// A path under a key that is itself extended keeps its sub key.
		$this->assertSame(
			['rollen' => ['betrokkene' => true]],
			$this->call('reduceExtendKeys', ['rollen', 'rollen.betrokkene'])
		);

	}//end testExtendKeysBecomeANestedTree()
}//end class
