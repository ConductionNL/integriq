<?php

/**
 * Coverage tests for UserService: the session user, organisation switching,
 * the memory guard of the quota lookup and the profile-information sources.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\UserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the UserService branches the characterisation and golden tests leave out.
 *
 * @covers \OCA\Integriq\Service\UserService
 */
class UserServiceCoverageTest extends TestCase {

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * @var IConfig&MockObject
	 */
	private $config;

	/**
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * @var IAccountManager&MockObject
	 */
	private $accountManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var OrganisationBridgeService&MockObject
	 */
	private $bridge;

	/**
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * @var UserService
	 */
	private UserService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->bridge = $this->createMock(OrganisationBridgeService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willThrowException(new \Exception('no database'));
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('nl');

		$this->service = new UserService(
			$this->userSession,
			$this->config,
			$this->groupManager,
			$this->accountManager,
			$this->logger,
			$this->bridge,
			$this->db,
			$l10nFactory
		);

	}//end setUp()

	/**
	 * A plain IUser mock with uid `anja` and a numeric quota.
	 *
	 * @return IUser&MockObject
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anja');
		$user->method('getQuota')->willReturn('1073741824');

		return $user;
	}//end user()

	/**
	 * getCurrentUser() returns whatever the session holds.
	 *
	 * @return void
	 */
	public function testGetCurrentUserReturnsSessionUser(): void {
		$user = $this->user();
		$this->userSession->expects($this->once())->method('getUser')->willReturn($user);

		$this->assertSame($user, $this->service->getCurrentUser());

	}//end testGetCurrentUserReturnsSessionUser()

	/**
	 * getCurrentUser() returns null when nobody is logged in.
	 *
	 * @return void
	 */
	public function testGetCurrentUserReturnsNullWithoutSession(): void {
		$this->userSession->expects($this->once())->method('getUser')->willReturn(null);

		$this->assertNull($this->service->getCurrentUser());

	}//end testGetCurrentUserReturnsNullWithoutSession()

	/**
	 * An activeOrganisation string switches the organisation through the bridge,
	 * reports its result and is not treated as a user property.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesSwitchesActiveOrganisation(): void {
		$user = $this->user();
		$this->bridge->expects($this->once())
			->method('setActiveOrganisation')
			->with('org-uuid-1')
			->willReturn(['success' => true, 'message' => 'Active organisation set']);

		$account = $this->createMock(IAccount::class);
		$account->expects($this->never())->method('getProperty');
		$this->accountManager->expects($this->once())->method('getAccount')->with($user)->willReturn($account);
		$this->accountManager->expects($this->never())->method('updateAccount');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->service->updateUserProperties($user, ['activeOrganisation' => 'org-uuid-1']);

		$this->assertSame(
			[
				'success' => true,
				'message' => 'User properties updated successfully',
				'organisation_updated' => true,
				'organisation_message' => 'Active organisation set',
			],
			$result
		);

	}//end testUpdateUserPropertiesSwitchesActiveOrganisation()

	/**
	 * A failed organisation switch is reported, the update itself still succeeds.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesReportsFailedOrganisationSwitch(): void {
		$this->bridge->expects($this->once())
			->method('setActiveOrganisation')
			->with('org-unknown')
			->willReturn(['success' => false, 'message' => 'Organisation not found']);
		$this->accountManager->method('getAccount')->willReturn($this->createMock(IAccount::class));

		$result = $this->service->updateUserProperties($this->user(), ['activeOrganisation' => 'org-unknown']);

		$this->assertTrue($result['success']);
		$this->assertFalse($result['organisation_updated']);
		$this->assertSame('Organisation not found', $result['organisation_message']);

	}//end testUpdateUserPropertiesReportsFailedOrganisationSwitch()

	/**
	 * A non-string activeOrganisation is ignored: the bridge is never asked.
	 *
	 * @return void
	 */
	public function testUpdateUserPropertiesIgnoresNonStringActiveOrganisation(): void {
		$this->bridge->expects($this->never())->method('setActiveOrganisation');
		$this->accountManager->method('getAccount')->willReturn($this->createMock(IAccount::class));

		$result = $this->service->updateUserProperties($this->user(), ['activeOrganisation' => 42]);

		$this->assertFalse($result['organisation_updated']);
		$this->assertArrayNotHasKey('organisation_message', $result);

	}//end testUpdateUserPropertiesIgnoresNonStringActiveOrganisation()

	/**
	 * Above 128MB of process memory the quota lookup refuses to query storage:
	 * used space is 0 and the memory warning is logged.
	 *
	 * @return void
	 */
	public function testQuotaLookupBailsOutWhenMemoryUsageIsTooHigh(): void {
		$this->accountManager->method('getAccount')->willThrowException(new RuntimeException('no accounts'));
		$this->db->expects($this->never())->method('getQueryBuilder');

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		// Hold ~160MB so memory_get_usage(true) is above the 128MB threshold.
		$ballast = str_repeat('x', 160 * 1024 * 1024);
		try {
			$data = $this->service->buildUserDataArray($this->user());
		} finally {
			unset($ballast);
		}

		$this->assertSame(
			['free' => '1073741824', 'used' => 0, 'total' => '1073741824', 'relative' => 0.0],
			$data['quota']
		);

		$memoryWarnings = array_values(
			array_filter($warnings, static fn (array $w): bool => $w[0] === 'Memory usage too high for quota calculation')
		);
		$this->assertCount(1, $memoryWarnings);
		$this->assertSame('anja', $memoryWarnings[0][1]['user']);
		$this->assertGreaterThan(128 * 1024 * 1024, $memoryWarnings[0][1]['memory_usage']);

	}//end testQuotaLookupBailsOutWhenMemoryUsageIsTooHigh()

	/**
	 * When the AccountManager fails, phone and website fall back to the user's
	 * `settings` preferences and the failure is logged.
	 *
	 * @return void
	 */
	public function testProfileFallsBackToUserPreferencesWhenAccountManagerFails(): void {
		$this->accountManager->expects($this->once())
			->method('getAccount')
			->willThrowException(new RuntimeException('account backend down'));
		$this->config->method('getUserValue')->willReturnMap(
			[
				['anja', 'settings', 'phone', '', '0612345678'],
				['anja', 'settings', 'website', '', 'https://anja.example.nl'],
				['anja', 'core', 'firstName', '', 'Anja'],
				['anja', 'core', 'lastName', '', ''],
				['anja', 'core', 'middleName', '', 'de'],
				['anja', 'core', 'organisation', '', 'org-uuid-9'],
			]
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[$message] = $context;
			}
		);

		$data = $this->service->buildUserDataArray($this->user());

		$this->assertSame('0612345678', $data['phone']);
		$this->assertSame('https://anja.example.nl', $data['website']);
		$this->assertSame('Anja', $data['firstName']);
		$this->assertNull($data['lastName']);
		$this->assertSame('de', $data['middleName']);
		$this->assertSame('org-uuid-9', $data['organisation']);
		$this->assertSame(['exception' => 'account backend down'], $warnings['AccountManager failed for user: anja']);

	}//end testProfileFallsBackToUserPreferencesWhenAccountManagerFails()

	/**
	 * Empty fallback preferences add no phone or website key.
	 *
	 * @return void
	 */
	public function testProfileFallbackOmitsEmptyPreferences(): void {
		$this->accountManager->method('getAccount')->willThrowException(new RuntimeException('down'));
		$this->config->method('getUserValue')->willReturn('');

		$data = $this->service->buildUserDataArray($this->user());

		$this->assertArrayNotHasKey('phone', $data);
		$this->assertArrayNotHasKey('website', $data);
		$this->assertArrayNotHasKey('organisation', $data);
		$this->assertNull($data['firstName']);

	}//end testProfileFallbackOmitsEmptyPreferences()

	/**
	 * The AccountManager properties are loaded one by one: non-empty values are
	 * mapped to their API field, empty ones are left out, and a property that
	 * throws is logged at debug level without stopping the others.
	 *
	 * @return void
	 */
	public function testAccountManagerPropertiesAreLoadedSelectively(): void {
		$user = $this->user();
		$values = [
			IAccountManager::PROPERTY_PHONE => '+31201234567',
			IAccountManager::PROPERTY_ADDRESS => '',
			IAccountManager::PROPERTY_WEBSITE => 'https://example.nl',
			IAccountManager::PROPERTY_FEDIVERSE => '',
			IAccountManager::PROPERTY_ORGANISATION => 'Gemeente Utrecht',
			IAccountManager::PROPERTY_ROLE => 'Leerplichtambtenaar',
			IAccountManager::PROPERTY_HEADLINE => '',
		];

		$account = $this->createMock(IAccount::class);
		$account->expects($this->exactly(8))->method('getProperty')->willReturnCallback(
			function (string $name) use ($values): IAccountProperty {
				if (array_key_exists($name, $values) === false) {
					throw new RuntimeException('property ' . $name . ' unavailable');
				}

				$property = $this->createMock(IAccountProperty::class);
				$property->method('getValue')->willReturn($values[$name]);
				return $property;
			}
		);
		$this->accountManager->expects($this->once())->method('getAccount')->with($user)->willReturn($account);
		$this->config->method('getUserValue')->willReturn('');

		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'Failed to load account property: ' . IAccountManager::PROPERTY_BIOGRAPHY,
				['user' => 'anja', 'exception' => 'property ' . IAccountManager::PROPERTY_BIOGRAPHY . ' unavailable']
			);

		$data = $this->service->buildUserDataArray($user);

		$this->assertSame('+31201234567', $data['phone']);
		$this->assertSame('https://example.nl', $data['website']);
		$this->assertSame('Gemeente Utrecht', $data['organisation']);
		$this->assertSame('Leerplichtambtenaar', $data['role']);
		$this->assertArrayNotHasKey('address', $data);
		$this->assertArrayNotHasKey('fediverse', $data);
		$this->assertArrayNotHasKey('headline', $data);
		$this->assertArrayNotHasKey('biography', $data);

	}//end testAccountManagerPropertiesAreLoadedSelectively()
}//end class
