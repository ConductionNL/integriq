<?php

/**
 * Digital post is stored as its service account.
 *
 * Runs DigitalPostService over the consumer world: the real DigitalPostAccount,
 * DsoConnection, DsoAccountRights and OpenRegister's runAs() (the stub copies
 * the real scoping), with an object service that refuses a write the acting
 * user has no right to, as OpenRegister does.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
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

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\BackgroundJob\DigitalPostStatusJob;
use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCA\Integriq\Tests\Helpers\RecordingLogger;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Sends and polls run as the digital post account, or refuse out loud.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostServiceAccountTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Alerts raised, as [reason, channel].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $alerts = [];

	/**
	 * How often the provider was called.
	 *
	 * @var integer
	 */
	private int $providerCalls = 0;

	/**
	 * The logger the service and the account write to.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * Build the world: an account in the digital post group, a source on the log provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->alerts = [];
		$this->providerCalls = 0;
		$this->logger = new RecordingLogger();
		$this->addAccount(uid: 'digitalepost', grants: []);
		$this->addAccount(uid: 'behandelaar1', grants: []);
		$this->worldGroupGrants[IntakeGroups::DIGITAL_POST_SENDERS] = ['create', 'read', 'update'];
		$this->worldGroupMembers[IntakeGroups::DIGITAL_POST_SENDERS] = ['digitalepost'];

	}//end setUp()

	/**
	 * Add the digital post consumer.
	 *
	 * @param string $userId The account it names.
	 *
	 * @return void
	 */
	private function addDigitalPostConsumer(string $userId): void {
		$this->worldConsumers['consumer-digital-post'] = [
			'name' => 'Digital post',
			'authorizationType' => DigitalPostAccount::AUTHORIZATION_TYPE,
			'userId' => $userId,
		];

	}//end addDigitalPostConsumer()

	/**
	 * The account over the world.
	 *
	 * @param ORObjectService $objectService The world's object service.
	 *
	 * @return DigitalPostAccount
	 */
	private function account(ORObjectService $objectService): DigitalPostAccount {
		$connection = $this->buildWorldConnection(objectService: $objectService);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);
		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->alerts[] = [$reason, $channel];
				return true;
			}
		);

		return new DigitalPostAccount(
			consumers: $connection,
			userManager: $userManager,
			rights: (new ReflectionProperty(DsoConnection::class, 'rights'))->getValue($connection),
			alerts: $alerts,
			logger: $this->logger
		);

	}//end account()

	/**
	 * The service over the world.
	 *
	 * @param ORObjectService      $objectService The world's object service.
	 * @param DigitalPostResult|null $result      What the provider answers.
	 *
	 * @return DigitalPostService
	 */
	private function service(ORObjectService $objectService, ?DigitalPostResult $result = null): DigitalPostService {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('log');
		$provider->method('send')->willReturnCallback(
			function () use ($result): DigitalPostResult {
				$this->providerCalls++;
				return ($result ?? DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, 'ref-1'));
			}
		);
		$provider->method('status')->willReturn(DigitalPostResult::accepted(DigitalPostResult::STATUS_DELIVERED, 'ref-1'));

		$store = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug'])
			->getMock();
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['providerId' => 'log']]);
		$store->method('findSourceBySlug')->willReturn($source);

		return new DigitalPostService(
			new DigitalPostProviderRegistry([$provider]),
			$store,
			$objectService,
			$this->createMock(IEventDispatcher::class),
			$this->logger,
			(new OptOutFixture($this, $this->createMock(IDBConnection::class)))->gate(),
			$this->account(objectService: $objectService)
		);

	}//end service()

	/**
	 * The world's writes to the digital post schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function digitalPostWrites(): array {
		return array_values(array_filter($this->worldWrites, static fn (array $write): bool => $write['schema'] === DigitalPostService::SCHEMA));

	}//end digitalPostWrites()

	/**
	 * A letter as dossiq dispatches it.
	 *
	 * @param string $requestedBy Who asked.
	 *
	 * @return DigitalPostSendRequestedEvent
	 */
	private function request(string $requestedBy = 'system'): DigitalPostSendRequestedEvent {
		return new DigitalPostSendRequestedEvent(
			'dossiq',
			'digital-post-source',
			'999993653',
			'Uw zaak',
			'Beste burger,',
			[],
			$requestedBy,
			'corr-1',
			'case-update',
			'Z-2026-001'
		);

	}//end request()

	/**
	 * Without a session the letter is stored as the account, and nobody is signed in afterwards.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-a-letter-sent-without-a-user-session-is-stored-as-the-service-account
	 *
	 * @return void
	 */
	public function testALetterWithoutASessionIsStoredAsTheAccount(): void {
		$this->addDigitalPostConsumer(userId: 'digitalepost');
		$objectService = $this->buildWorldObjectService();
		$event = $this->request();

		$this->service(objectService: $objectService)->handleSendRequest($event);

		$this->assertNull($event->getRefusal());
		$this->assertNotNull($event->getMessageId());
		$writes = $this->digitalPostWrites();
		$this->assertCount(2, $writes, 'stored, then updated with the outcome');
		foreach ($writes as $write) {
			$this->assertSame('digitalepost', $write['uid']);
			$this->assertTrue($write['rbac'], 'no write with RBAC off');
			$this->assertFalse($write['system']);
		}

		$this->assertSame(1, $this->providerCalls);
		$this->assertNull($this->worldSession->getUser(), 'nobody is signed in afterwards');

	}//end testALetterWithoutASessionIsStoredAsTheAccount()

	/**
	 * An interactive send is stored as the account, keeps who asked, and restores the session.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-an-interactive-send-keeps-the-person-who-asked
	 *
	 * @return void
	 */
	public function testAnInteractiveSendKeepsThePersonWhoAsked(): void {
		$this->addDigitalPostConsumer(userId: 'digitalepost');
		$this->worldSession->setVolatileActiveUser($this->worldUser('behandelaar1'));
		$objectService = $this->buildWorldObjectService();
		$event = $this->request(requestedBy: 'behandelaar1');

		$this->service(objectService: $objectService)->handleSendRequest($event);

		$this->assertNull($event->getRefusal());
		$writes = $this->digitalPostWrites();
		$this->assertSame('digitalepost', $writes[0]['uid']);
		$this->assertSame('behandelaar1', $writes[0]['object']['requestedBy']);
		$this->assertSame('behandelaar1', $this->worldSession->getUser()?->getUID(), 'the session is restored');

	}//end testAnInteractiveSendKeepsThePersonWhoAsked()

	/**
	 * Without a consumer the send is refused, nothing is stored or sent, and the admins hear of it.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-a-missing-or-disabled-account-refuses-the-send-out-loud
	 *
	 * @return void
	 */
	public function testAMissingAccountRefusesTheSendOutLoud(): void {
		$objectService = $this->buildWorldObjectService();
		$event = $this->request();

		$this->service(objectService: $objectService)->handleSendRequest($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(DigitalPostService::CODE_NO_SERVICE_ACCOUNT, $event->getRefusal()['code']);
		$this->assertSame([], $this->digitalPostWrites());
		$this->assertSame(0, $this->providerCalls);
		$this->assertSame([['no_connection', 'digitalpost']], $this->alerts);
		$this->assertStringContainsString('digital-post.account.unavailable', $this->logger->flatten());

	}//end testAMissingAccountRefusesTheSendOutLoud()

	/**
	 * A disabled account refuses the same way.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-a-missing-or-disabled-account-refuses-the-send-out-loud
	 *
	 * @return void
	 */
	public function testADisabledAccountRefusesTheSend(): void {
		$this->addAccount(uid: 'digitalepost', grants: [], enabled: false);
		$this->addDigitalPostConsumer(userId: 'digitalepost');
		$objectService = $this->buildWorldObjectService();
		$event = $this->request();

		$this->service(objectService: $objectService)->handleSendRequest($event);

		$this->assertSame(DigitalPostService::CODE_NO_SERVICE_ACCOUNT, $event->getRefusal()['code']);
		$this->assertSame(0, $this->providerCalls);
		$this->assertSame([['account_disabled', 'digitalpost']], $this->alerts);

	}//end testADisabledAccountRefusesTheSend()

	/**
	 * An account outside the group lacks the rights, and the send is refused before the provider.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-a-missing-or-disabled-account-refuses-the-send-out-loud
	 *
	 * @return void
	 */
	public function testAnAccountWithoutTheRightsRefusesTheSend(): void {
		$this->worldGroupMembers[IntakeGroups::DIGITAL_POST_SENDERS] = [];
		$this->addDigitalPostConsumer(userId: 'digitalepost');
		$objectService = $this->buildWorldObjectService();
		$event = $this->request();

		$this->service(objectService: $objectService)->handleSendRequest($event);

		$this->assertSame(DigitalPostService::CODE_NO_SERVICE_ACCOUNT, $event->getRefusal()['code']);
		$this->assertSame(0, $this->providerCalls);
		$this->assertSame([['account_lacks_rights', 'digitalpost']], $this->alerts);

	}//end testAnAccountWithoutTheRightsRefusesTheSend()

	/**
	 * The status job reads and updates as the account.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 *
	 * @return void
	 */
	public function testTheStatusJobPollsAsTheAccount(): void {
		$this->addDigitalPostConsumer(userId: 'digitalepost');
		$objectService = $this->buildWorldObjectService();
		$service = $this->service(objectService: $objectService);
		$this->addOther(
			schema: DigitalPostService::SCHEMA,
			uuid: 'msg-open',
			data: ['uuid' => 'msg-open', 'providerId' => 'log', 'providerReference' => 'ref-1', 'status' => DigitalPostResult::STATUS_SENT, 'sourceId' => 'digital-post-source'],
			adminOnly: false
		);

		$job = new DigitalPostStatusJob(
			$this->createMock(ITimeFactory::class),
			$service,
			$objectService,
			$this->logger,
			$this->account(objectService: $objectService)
		);
		$job->poll();

		$reads = array_values(array_filter($this->worldReads, static fn (array $read): bool => $read['schema'] === DigitalPostService::SCHEMA));
		$this->assertSame('digitalepost', $reads[0]['uid']);
		$this->assertTrue($reads[0]['rbac']);
		$writes = $this->digitalPostWrites();
		$this->assertCount(1, $writes);
		$this->assertSame('digitalepost', $writes[0]['uid']);
		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $writes[0]['object']['status']);
		$this->assertNull($this->worldSession->getUser());

	}//end testTheStatusJobPollsAsTheAccount()
}//end class
