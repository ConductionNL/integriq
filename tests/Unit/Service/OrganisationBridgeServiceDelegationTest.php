<?php

/**
 * Unit tests for OrganisationBridgeService when OpenRegister is present.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/organisation-bridge/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Each call delegates to OpenRegister and turns a failure into a safe answer.
 */
#[CoversClass(OrganisationBridgeService::class)]
class OrganisationBridgeServiceDelegationTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A bridge that resolves the given organisation service.
	 *
	 * @param OrganisationService $organisations The organisation service.
	 *
	 * @return OrganisationBridgeService
	 */
	private function bridge(OrganisationService $organisations): OrganisationBridgeService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['files', 'openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\OrganisationService')->willReturn($organisations);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new OrganisationBridgeService(appManager: $apps, container: $container, logger: $this->logger);
	}//end bridge()

	/**
	 * An organisation that serialises to the given data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return \JsonSerializable
	 */
	private function organisation(array $data): \JsonSerializable {
		return new class($data) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data The data.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The data.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end organisation()

	/**
	 * Stats are passed through and marked available.
	 *
	 * @return void
	 */
	public function testStatsArePassedThroughAndMarkedAvailable(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisationStats')->willReturn(['total' => 2, 'active' => 'o-1', 'results' => []]);

		$this->assertSame(
			['total' => 2, 'active' => 'o-1', 'results' => [], 'available' => true],
			$this->bridge(organisations: $organisations)->getUserOrganisationStats()
		);
	}//end testStatsArePassedThroughAndMarkedAvailable()

	/**
	 * A failing stats read is logged and answered with empty stats and an error.
	 *
	 * @return void
	 */
	public function testAFailingStatsReadAnswersEmptyStats(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisationStats')->willThrowException(new \RuntimeException('db down'));
		$bridge = $this->bridge(organisations: $organisations);
		$this->logger->expects($this->once())->method('error')
			->with('Failed to get user organization stats', ['exception' => 'db down']);

		$this->assertSame(
			['total' => 0, 'active' => null, 'results' => [], 'available' => false, 'error' => 'Failed to retrieve organization data'],
			$bridge->getUserOrganisationStats()
		);
	}//end testAFailingStatsReadAnswersEmptyStats()

	/**
	 * Setting the active organisation reports success or refusal from OpenRegister.
	 *
	 * @return void
	 */
	public function testSetActiveOrganisationReportsTheOutcome(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->expects($this->exactly(2))->method('setActiveOrganisation')->with('o-1')->willReturnOnConsecutiveCalls(true, false);
		$bridge = $this->bridge(organisations: $organisations);

		$this->assertSame(
			['success' => true, 'message' => 'Active organization updated successfully', 'available' => true],
			$bridge->setActiveOrganisation('o-1')
		);
		$this->assertSame(
			['success' => false, 'message' => 'Failed to update active organization', 'available' => true],
			$bridge->setActiveOrganisation('o-1')
		);
	}//end testSetActiveOrganisationReportsTheOutcome()

	/**
	 * A failing switch is logged and its message returned.
	 *
	 * @return void
	 */
	public function testAFailingSwitchReturnsItsMessage(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('setActiveOrganisation')->willThrowException(new \RuntimeException('not a member'));
		$bridge = $this->bridge(organisations: $organisations);
		$this->logger->expects($this->once())->method('error')
			->with('Failed to set active organization', ['organisationUuid' => 'o-2', 'exception' => 'not a member']);

		$this->assertSame(
			['success' => false, 'message' => 'not a member', 'available' => true],
			$bridge->setActiveOrganisation('o-2')
		);
	}//end testAFailingSwitchReturnsItsMessage()

	/**
	 * The active organisation is serialised; none answers null.
	 *
	 * @return void
	 */
	public function testTheActiveOrganisationIsSerialised(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnOnConsecutiveCalls($this->organisation(data: ['uuid' => 'o-1']), null);
		$bridge = $this->bridge(organisations: $organisations);

		$this->assertSame(['uuid' => 'o-1'], $bridge->getActiveOrganisation());
		$this->assertNull($bridge->getActiveOrganisation());
	}//end testTheActiveOrganisationIsSerialised()

	/**
	 * A failing active-organisation read is logged and answers null.
	 *
	 * @return void
	 */
	public function testAFailingActiveReadAnswersNull(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willThrowException(new \RuntimeException('boom'));
		$bridge = $this->bridge(organisations: $organisations);
		$this->logger->expects($this->once())->method('error')->with('Failed to get active organization', ['exception' => 'boom']);

		$this->assertNull($bridge->getActiveOrganisation());
	}//end testAFailingActiveReadAnswersNull()

	/**
	 * The user's organisations are each serialised, in order.
	 *
	 * @return void
	 */
	public function testTheUsersOrganisationsAreSerialised(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisations')->willReturn([$this->organisation(data: ['uuid' => 'a']), $this->organisation(data: ['uuid' => 'b'])]);

		$this->assertSame([['uuid' => 'a'], ['uuid' => 'b']], $this->bridge(organisations: $organisations)->getUserOrganisations());
	}//end testTheUsersOrganisationsAreSerialised()

	/**
	 * A failing organisations read is logged and answers an empty list.
	 *
	 * @return void
	 */
	public function testAFailingOrganisationsReadAnswersEmpty(): void {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisations')->willThrowException(new \RuntimeException('boom'));
		$bridge = $this->bridge(organisations: $organisations);
		$this->logger->expects($this->once())->method('error')->with('Failed to get user organizations', ['exception' => 'boom']);

		$this->assertSame([], $bridge->getUserOrganisations());
	}//end testAFailingOrganisationsReadAnswersEmpty()
}//end class
