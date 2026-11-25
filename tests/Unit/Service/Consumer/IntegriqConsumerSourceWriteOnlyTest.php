<?php

/**
 * IntegriqConsumerSource reads a consumer's write-only credential from the stored row.
 *
 * OpenRegister strips the writeOnly `authorizationConfiguration` from every
 * rendered list row, an unscoped one included, so an API key compared against
 * the listed consumer never matches and every API-key consumer is refused.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The key check sees the stored credential, not the rendered row.
 *
 * @spec openspec/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
class IntegriqConsumerSourceWriteOnlyTest extends TestCase {

	/**
	 * A consumer object.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function consumer(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('c-scim');
		$entity->setObject($data);

		return $entity;
	}//end consumer()

	/**
	 * A listed consumer without its credential still matches the key it stores.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function testAnApiKeyMatchesTheStoredCredentialTheListLeavesOut(): void {
		$listed = self::consumer(data: ['name' => 'scim', 'authorizationType' => 'apiKey', 'userId' => 'svc-scim']);
		$stored = self::consumer(data: ['name' => 'scim', 'authorizationType' => 'apiKey', 'authorizationConfiguration' => ['apiKey' => 'k-scim'], 'userId' => 'svc-scim']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [$listed]]);
		$objects->expects($this->atLeastOnce())->method('find')
			->with(
				'c-scim',
				$this->callback(static fn (ObjectFindOptions $options): bool => $options->render === false),
				false,
				false
			)
			->willReturn($stored);

		$source = new IntegriqConsumerSource(objectService: $objects);

		$resolved = $source->findByApiKey(apiKey: 'k-scim');
		$this->assertNotNull(actual: $resolved, message: 'the stored key matches');
		$this->assertNull(actual: $source->findByApiKey(apiKey: 'k-wrong'), message: 'another key does not');
	}//end testAnApiKeyMatchesTheStoredCredentialTheListLeavesOut()

}//end class
