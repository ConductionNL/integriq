<?php

/**
 * Unit tests for the schema an ObjecttypeEndpointHandler version answer carries.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that an unwired, failing or malformed schema read yields an empty jsonSchema, and a good one is passed on.
 */
#[CoversClass(ObjecttypeEndpointHandler::class)]
#[UsesClass(ObjecttypeRegistry::class)]
class ObjecttypeEndpointHandlerSchemaTest extends TestCase {

	/**
	 * The handler over one objecttype with the given schema read.
	 *
	 * @param callable|null $schemaRead The schema read.
	 *
	 * @return ObjecttypeEndpointHandler
	 */
	private function handler(?callable $schemaRead): ObjecttypeEndpointHandler {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);

		return new ObjecttypeEndpointHandler(objecttypes: $registry, schemaRead: $schemaRead);
	}//end handler()

	/**
	 * Schema reads that yield nothing usable.
	 *
	 * @return array<string, array{0: callable|null}>
	 */
	public static function emptySchemas(): array {
		return [
			'not wired' => [null],
			'throws' => [static function (): array {
				throw new RuntimeException('schema gone');
			}],
			'not an array' => [static fn (): string => 'oops'],
		];
	}//end emptySchemas()

	/**
	 * An unusable schema read still answers the version, with an empty schema.
	 *
	 * @param callable|null $schemaRead The schema read.
	 *
	 * @return void
	 */
	#[DataProvider('emptySchemas')]
	public function testAnUnusableSchemaReadGivesAnEmptySchema(?callable $schemaRead): void {
		$answer = $this->handler($schemaRead)->version(uuid: 'aaa', version: '1', baseUrl: 'https://x.nl/api/v2');

		$this->assertSame(200, $answer['status']);
		$this->assertSame([], $answer['body']['jsonSchema']);
		$this->assertSame('1', $answer['body']['version']);
	}//end testAnUnusableSchemaReadGivesAnEmptySchema()

	/**
	 * A schema read receives the declared register and schema, and its result is the jsonSchema.
	 *
	 * @return void
	 */
	public function testTheSchemaReadIsAskedForTheDeclaredSchema(): void {
		$asked = [];
		$answer = $this->handler(function (string $register, string $schema) use (&$asked): array {
			$asked[] = [$register, $schema];
			return ['type' => 'object'];
		})->version(uuid: 'aaa', version: '1');

		$this->assertSame([['meldingen', 'melding']], $asked);
		$this->assertSame(['type' => 'object'], $answer['body']['jsonSchema']);
	}//end testTheSchemaReadIsAskedForTheDeclaredSchema()
}//end class
