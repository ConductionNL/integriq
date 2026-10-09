<?php

/**
 * The admin setting that picks the digital post account, and its setup check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DigitalPostAccountSettingsController;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\SetupCheck\DigitalPostAccountCheck;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Picks, checks and saves the account; the setup check reads the same state.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostAccountSettingsControllerTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The world's object service.
	 *
	 * @var ORObjectService
	 */
	private ORObjectService $objectService;

	/**
	 * Build the world with the group's grant.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->addAccount(uid: 'admin', grants: ['create', 'read', 'update']);
		$this->addAccount(uid: 'digitalepost', grants: []);
		$this->worldGroupGrants[IntakeGroups::DIGITAL_POST_SENDERS] = ['create', 'read', 'update'];
		$this->worldSession->setVolatileActiveUser($this->worldUser('admin'));
		$this->objectService = $this->buildWorldObjectService();

	}//end setUp()

	/**
	 * The account over the world.
	 *
	 * @return DigitalPostAccount
	 */
	private function account(): DigitalPostAccount {
		$connection = $this->buildWorldConnection(objectService: $this->objectService);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);

		return new DigitalPostAccount(
			consumers: $connection,
			userManager: $userManager,
			rights: (new ReflectionProperty(DsoConnection::class, 'rights'))->getValue($connection),
			alerts: $this->createMock(DsoConnectionAlerts::class),
			logger: new NullLogger()
		);

	}//end account()

	/**
	 * The controller for a request with this userId.
	 *
	 * @param string $userId The chosen uid.
	 *
	 * @return DigitalPostAccountSettingsController
	 */
	private function controller(string $userId): DigitalPostAccountSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'userId' ? $userId : $default)
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DigitalPostAccountSettingsController(
			request: $request,
			account: $this->account(),
			consumers: $this->buildWorldConnection(objectService: $this->objectService),
			groups: $this->buildWorldIntakeGroups(),
			groupManager: $this->createMock(IGroupManager::class),
			l: $l,
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * Picking an account writes the consumer as the admin and puts the account in the group.
	 *
	 * @return void
	 */
	public function testPickingAnAccountSavesTheConsumerAndEnrolsIt(): void {
		$response = $this->controller(userId: 'digitalepost')->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ok', $response->getData()['account']['state']);
		$this->assertSame(['digitalepost'], $this->worldGroupMembers[IntakeGroups::DIGITAL_POST_SENDERS]);
		$consumers = array_values(array_filter($this->worldWrites, static fn (array $write): bool => $write['schema'] === 'consumer'));
		$this->assertCount(1, $consumers);
		$this->assertSame('admin', $consumers[0]['uid']);
		$this->assertTrue($consumers[0]['rbac'], 'the consumer is written with RBAC on');
		$this->assertSame('digital-post', $consumers[0]['object']['authorizationType']);
		$this->assertSame('digitalepost', $consumers[0]['object']['userId']);

	}//end testPickingAnAccountSavesTheConsumerAndEnrolsIt()

	/**
	 * An unknown account is refused with a field error and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnknownAccountIsRefused(): void {
		$response = $this->controller(userId: 'nobody')->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Account nobody does not exist.', $response->getData()['fieldErrors']['userId']);
		$this->assertSame([], $this->worldWrites);

	}//end testAnUnknownAccountIsRefused()

	/**
	 * Changing the account takes the previous one out of the group.
	 *
	 * @return void
	 */
	public function testChangingTheAccountWithdrawsThePreviousOne(): void {
		$this->addAccount(uid: 'digitalepost2', grants: []);
		$this->controller(userId: 'digitalepost')->setConfig();

		$this->controller(userId: 'digitalepost2')->setConfig();

		$this->assertSame(['digitalepost2'], $this->worldGroupMembers[IntakeGroups::DIGITAL_POST_SENDERS]);
		$this->assertCount(1, $this->worldConsumers, 'the one consumer is updated, not duplicated');

	}//end testChangingTheAccountWithdrawsThePreviousOne()

	/**
	 * The setup check warns when a digital post source exists without a usable account.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 *
	 * @return void
	 */
	public function testTheSetupCheckFollowsTheAccount(): void {
		$this->assertSame('success', $this->check()->run()->getSeverity(), 'no source, no account: digital post is not in use');

		$this->addOther(schema: 'source', uuid: 'src-1', data: ['slug' => 'berichtenbox', 'type' => 'digital-post']);
		$warning = $this->check()->run();
		$this->assertSame('warning', $warning->getSeverity());
		$this->assertStringContainsString('No digital post account is set.', (string)$warning->getDescription());

		$this->controller(userId: 'digitalepost')->setConfig();
		$this->assertSame('success', $this->check()->run()->getSeverity());

	}//end testTheSetupCheckFollowsTheAccount()

	/**
	 * The setup check over the world.
	 *
	 * @return DigitalPostAccountCheck
	 */
	private function check(): DigitalPostAccountCheck {
		$account = $this->account();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				DigitalPostAccount::class => $account,
				default => $this->objectService,
			}
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DigitalPostAccountCheck(container: $container, l10n: $l);

	}//end check()
}//end class
