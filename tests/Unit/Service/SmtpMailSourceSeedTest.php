<?php

/**
 * The seeded `smtp-mail` source OpenRegister's messaging leaf sends e-mail
 * through (send-at-on-the-messaging-leaf, task 1.1).
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CatalogRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The seed is found under the slug the leaf names, ships in mock mode at an
 * address that never resolves, and is listed in the catalog as messaging.
 */
class SmtpMailSourceSeedTest extends TestCase {

	/**
	 * The catalog service over the shipped fragments.
	 *
	 * @return CatalogRegistryService
	 */
	private function service(): CatalogRegistryService {
		return new CatalogRegistryService(
			new IntegrationRegistry(new NullLogger()),
			$this->createMock(OrObjectService::class),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);

	}//end service()

	/**
	 * The seed is a mock-mode HTTP source at a reserved, never-resolving address.
	 *
	 * @return void
	 */
	public function testTheSeedIsAMockHttpSourceAtAPlaceholderAddress(): void {
		$payload = $this->service()->findSeedSourcePayload('smtp-mail');

		$this->assertIsArray($payload);
		$this->assertSame('SMTP mail relay', $payload['name']);
		$this->assertSame('api', $payload['type']);
		$this->assertTrue($payload['configuration']['mock']);
		$this->assertSame('accepted', $payload['configuration']['mockResponse']['status']);
		$this->assertStringEndsWith('.invalid', (string)parse_url((string)$payload['location'], PHP_URL_HOST));
		$this->assertSame('application/json', $payload['configuration']['headers']['Content-Type']);

	}//end testTheSeedIsAMockHttpSourceAtAPlaceholderAddress()

	/**
	 * The catalog lists the seed under Messaging, beside the SMS sources.
	 *
	 * @return void
	 */
	public function testTheCatalogListsItAsMessaging(): void {
		$entries = array_values(
			array_filter(
				$this->service()->collect(),
				static fn (array $entry): bool => ($entry['sourceTemplateSlug'] ?? '') === 'smtp-mail'
			)
		);

		$this->assertCount(1, $entries);
		$this->assertSame('Messaging', $entries[0]['category']);
		$this->assertSame('source-template:smtp-mail', $entries[0]['slug']);

	}//end testTheCatalogListsItAsMessaging()
}//end class
