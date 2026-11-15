<?php

/**
 * Unit tests for ObjectenTokenService's key parsing and key resolution.
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

use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins which Authorization headers yield a key, and that an unresolvable credential never matches.
 */
#[CoversClass(ObjectenTokenService::class)]
#[UsesClass(ObjecttypeRegistry::class)]
class ObjectenTokenServiceKeyTest extends TestCase {

	/**
	 * Headers and the key each yields.
	 *
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function headers(): array {
		return [
			'absent' => [null, null],
			'blank' => ['   ', null],
			'scheme only' => ['Token', null],
			'other scheme' => ['Bearer abc', null],
			'scheme then only a form feed' => ["Token \f", null],
			'lowercase scheme' => ['token abc', 'abc'],
			'key kept exactly' => ['Token  AbC dEf ', 'AbC dEf'],
		];
	}//end headers()

	/**
	 * The key a header yields.
	 *
	 * @param string|null $header   The Authorization header.
	 * @param string|null $expected The key.
	 *
	 * @return void
	 */
	#[DataProvider('headers')]
	public function testKeyFrom(?string $header, ?string $expected): void {
		$service = new ObjectenTokenService(objecttypes: new ObjecttypeRegistry());

		$this->assertSame($expected, $service->keyFrom($header));
	}//end testKeyFrom()

	/**
	 * Without a credential reader no token resolves, so a well-formed key is an unknown credential.
	 *
	 * @return void
	 */
	public function testNoCredentialReaderMatchesNoToken(): void {
		$service = new ObjectenTokenService(objecttypes: new ObjecttypeRegistry());
		$this->assertSame([], $service->load([['credential' => 'ref-a', 'principal' => 'p-a']]));

		$this->assertSame(
			['verdict' => ObjectenTokenService::UNKNOWN_CREDENTIAL, 'status' => 401, 'principal' => ''],
			$service->verdictFor('Token key-a', '')
		);
	}//end testNoCredentialReaderMatchesNoToken()

	/**
	 * A credential that resolves to an empty or non-string key is skipped, and a later one still matches.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCredentialIsSkipped(): void {
		$keys = ['ref-empty' => '', 'ref-int' => 5, 'ref-good' => 'key-g'];
		$service = new ObjectenTokenService(
			objecttypes: new ObjecttypeRegistry(),
			credentialRead: static fn (string $reference): mixed => ($keys[$reference] ?? null)
		);
		$service->load(
			[
				['credential' => 'ref-empty', 'principal' => 'p-empty'],
				['credential' => 'ref-int', 'principal' => 'p-int'],
				['credential' => 'ref-good', 'principal' => 'p-good'],
			]
		);

		$this->assertSame(ObjectenTokenService::UNKNOWN_CREDENTIAL, $service->verdictFor('Token 5', '')['verdict']);
		$this->assertSame(
			['verdict' => ObjectenTokenService::ALLOWED, 'status' => 200, 'principal' => 'p-good'],
			$service->verdictFor('Token key-g', '')
		);
	}//end testAnUnresolvableCredentialIsSkipped()
}//end class
