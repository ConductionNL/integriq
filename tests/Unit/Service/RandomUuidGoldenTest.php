<?php

/**
 * Every hand-rolled uuid generator mints a fresh RFC 4122 version-4 uuid.
 *
 * Pins the private generators of the bank feed, card feed, case-system mock,
 * EUDI credential offer and EUDI status list services: each answers a
 * lower-case, hyphenated uuid with the version-4 and RFC 4122 variant bits
 * set, and a new one on every call, so the shared generator can move without
 * changing the ids they mint.
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
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\BankfeedSyncService;
use OCA\Integriq\Service\CardfeedSyncService;
use OCA\Integriq\Service\CaseSystem\CaseSystemMock;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiStatusListService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the uuid generators.
 */
final class RandomUuidGoldenTest extends TestCase {

	/**
	 * Each class and its generator.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function generators(): array {
		return [
			'bank feed' => [BankfeedSyncService::class, 'generateUuid'],
			'card feed' => [CardfeedSyncService::class, 'generateUuid'],
			'case system mock' => [CaseSystemMock::class, 'uuid'],
			'credential offer' => [EudiCredentialOfferService::class, 'generateUuid'],
			'status list' => [EudiStatusListService::class, 'generateUuid'],
		];

	}//end generators()

	/**
	 * A generator mints distinct version-4 uuids.
	 *
	 * @param class-string $class  The class.
	 * @param string       $method Its generator.
	 *
	 * @return void
	 */
	#[DataProvider('generators')]
	public function testItMintsDistinctVersionFourUuids(string $class, string $method): void {
		$reflection = new ReflectionClass($class);
		$generator = $reflection->getMethod($method);
		$owner = null;
		if ($generator->isStatic() === false) {
			$owner = $reflection->newInstanceWithoutConstructor();
		}

		$minted = [];
		for ($i = 0; $i < 50; $i++) {
			$uuid = $generator->invoke($owner);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
			$minted[$uuid] = true;
		}

		$this->assertCount(50, $minted);

	}//end testItMintsDistinctVersionFourUuids()
}//end class
