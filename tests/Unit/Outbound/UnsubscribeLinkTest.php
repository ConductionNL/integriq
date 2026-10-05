<?php

/**
 * Unit tests for the unsubscribe link writing into integriq's own table.
 *
 * Through the real controller, token service, registry and migrator. Only the
 * storage below them is a double: the table (in memory, one row per key, as
 * the unique index has it) and OpenRegister, which refuses every write the way
 * it refuses an anonymous one on a live instance.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Controller\SenderIdentityController;
use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\OptOutTableMigrator;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Tests\Helpers\InMemoryOptOutMapper;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the public unsubscribe leg and the copy of the old opt-outs.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class UnsubscribeLinkTest extends TestCase {

	/**
	 * The opt-out services, kept for one test so the short link table is shared.
	 *
	 * @var OptOutFixture|null
	 */
	private ?OptOutFixture $fixture = null;

	/**
	 * The signing secret the app config holds.
	 *
	 * @var string
	 */
	private const SECRET = 'unit-secret-0123456789';

	/**
	 * The unix time the clock answers.
	 *
	 * @var int
	 */
	private int $now = 1790000000;

	/**
	 * The opt-out table.
	 *
	 * @var InMemoryOptOutMapper
	 */
	private InMemoryOptOutMapper $table;

	/**
	 * OpenRegister, refusing every write as it refuses an anonymous one.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The `_rbac` argument of every OpenRegister read.
	 *
	 * @var array<int,bool>
	 */
	private array $readRbac = [];

	/**
	 * The `recipient_opt_out` objects OpenRegister holds.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $legacy = [];

	/**
	 * The app config values.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->table = new InMemoryOptOutMapper($this->createMock(IDBConnection::class));
		$this->config = [UnsubscribeTokenService::CONFIG_SECRET => self::SECRET];
		$this->legacy = [];
		$this->readRbac = [];

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willThrowException(
			new RuntimeException("User 'Anonymous' does not have permission to 'create' objects in schema 'Recipient Opt Out'")
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->readRbac[] = $_rbac;
				if ($_rbac === true) {
					// Deny-all schema, no administrator: an empty set.
					return ['results' => [], 'total' => 0];
				}

				$offset = (int)($config['offset'] ?? 0);
				$limit = (int)($config['limit'] ?? 500);
				$rows = [];
				foreach (array_slice($this->legacy, $offset, $limit, true) as $uuid => $object) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
				}

				return ['results' => $rows, 'total' => count($this->legacy)];
			}
		);

	}//end setUp()

	/**
	 * A valid link, no login: 200, one row, and OpenRegister is not written.
	 *
	 * @return void
	 */
	public function testAValidLinkWithoutALoginAddsOneRow(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$token = $this->tokens()->mint('Jan@Example.org', 'zaak/1');

		$response = $this->controller()->unsubscribeConfirm($token);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('stopped', $response->getParams()['state']);
		$this->assertSame(1, $this->table->countAll());
		$row = array_values($this->table->rows)[0];
		$this->assertSame('jan@example.org', $row->getAddress());
		$this->assertSame(OptOutRegistry::SCOPE_CASE, $row->getScope());
		$this->assertSame('zaak/1', $row->getCaseRef());
		$this->assertSame('unsubscribe-link', $row->getSource());

	}//end testAValidLinkWithoutALoginAddsOneRow()

	/**
	 * Following the same link twice leaves one row.
	 *
	 * @return void
	 */
	public function testFollowingALinkTwiceAddsOneRow(): void {
		$token = $this->tokens()->mint('jan@example.org', 'zaak/1');
		$controller = $this->controller();

		$controller->unsubscribeConfirm($token);
		$second = $controller->unsubscribeConfirm($token);

		$this->assertSame(Http::STATUS_OK, $second->getStatus());
		$this->assertSame(1, $this->table->countAll());

	}//end testFollowingALinkTwiceAddsOneRow()

	/**
	 * A tampered link is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testATamperedLinkIsRefusedAndAddsNoRow(): void {
		$token = $this->tokens()->mint('jan@example.org', 'zaak/1');
		$parts = explode('.', $token);
		$parts[1] = rtrim(strtr(base64_encode('{"a":"piet@example.org","c":"zaak/1","e":1999999999}'), '+/', '-_'), '=');

		$response = $this->controller()->unsubscribe(implode('.', $parts));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid', $response->getParams()['state']);
		$this->assertSame(0, $this->table->countAll());

	}//end testATamperedLinkIsRefusedAndAddsNoRow()

	/**
	 * A new link past its expiry is refused with 410 and writes nothing.
	 *
	 * @return void
	 */
	public function testAnExpiredLinkIsRefusedAndAddsNoRow(): void {
		$token = $this->tokens()->mint('jan@example.org', 'zaak/1');
		$this->now += (UnsubscribeTokenService::DEFAULT_TTL_DAYS * 86400) + 1;

		$response = $this->controller()->unsubscribe($token);

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame('expired', $response->getParams()['state']);
		$this->assertSame(0, $this->table->countAll());

	}//end testAnExpiredLinkIsRefusedAndAddsNoRow()

	/**
	 * The expiry follows the instance setting.
	 *
	 * @return void
	 */
	public function testTheExpiryFollowsTheInstanceSetting(): void {
		$this->config[UnsubscribeTokenService::CONFIG_TTL_DAYS] = '2';
		$token = $this->tokens()->mint('jan@example.org', 'zaak/1');

		$this->now += 86400;
		$this->assertSame(UnsubscribeTokenService::STATUS_VALID, $this->tokens()->inspect($token)['status']);

		$this->now += 86401;
		$this->assertSame(UnsubscribeTokenService::STATUS_EXPIRED, $this->tokens()->inspect($token)['status']);

	}//end testTheExpiryFollowsTheInstanceSetting()

	/**
	 * A link in the old format, already in people's mail, is still honoured.
	 *
	 * @return void
	 */
	public function testAnOldFormatLinkIsHonoured(): void {
		$payload = rtrim(strtr(base64_encode('{"a":"jan@example.org","c":"zaak\/1"}'), '+/', '-_'), '=');
		$token = $payload . '.' . hash_hmac('sha256', $payload, self::SECRET);

		$response = $this->controller()->unsubscribeConfirm($token);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$row = array_values($this->table->rows)[0];
		$this->assertSame('zaak/1', $row->getCaseRef());
		$this->assertSame('unsubscribe-link-v1', $row->getSource());

	}//end testAnOldFormatLinkIsHonoured()

	/**
	 * An old-format signature does not verify as a new-format token.
	 *
	 * @return void
	 */
	public function testAnOldSignatureCannotBeReusedInTheNewFormat(): void {
		$payload = rtrim(strtr(base64_encode('{"a":"jan@example.org","c":"zaak\/1","e":1999999999}'), '+/', '-_'), '=');
		$token = 'v2.' . $payload . '.' . hash_hmac('sha256', $payload, self::SECRET);

		$this->assertSame(UnsubscribeTokenService::STATUS_INVALID, $this->tokens()->inspect($token)['status']);

	}//end testAnOldSignatureCannotBeReusedInTheNewFormat()

	/**
	 * The decision whether to send reads the table, so an opt-out made by the
	 * link stops the next update on that case and nothing else.
	 *
	 * @return void
	 */
	public function testTheDecisionReadsTheTable(): void {
		$this->controller()->unsubscribeConfirm($this->tokens()->mint('jan@example.org', 'zaak/1'));
		$registry = $this->registry();

		$this->assertFalse($registry->decide('jan@example.org', 'status-update', 'zaak/1')['send']);
		$this->assertTrue($registry->decide('jan@example.org', 'status-update', 'zaak/2')['send']);
		$this->assertTrue($registry->decide('jan@example.org', 'besluit', 'zaak/1')['overridden']);
		$this->assertSame([], $this->readRbac, 'the decision does not read OpenRegister');

	}//end testTheDecisionReadsTheTable()

	/**
	 * The old opt-outs are copied once, as an engine read, and honoured after.
	 *
	 * @return void
	 */
	public function testTheOldOptOutsAreCopiedOnce(): void {
		$this->legacy = [
			'uuid-piet' => ['address' => 'Piet@Example.org', 'scope' => 'case', 'caseRef' => 'zaak/7', 'source' => 'unsubscribe-link', 'createdAt' => '2026-09-01T10:00:00+00:00'],
			'uuid-klaas' => ['address' => 'klaas@example.org', 'scope' => 'instance', 'caseRef' => null, 'source' => 'administrator'],
			'uuid-empty' => ['scope' => 'instance'],
		];
		$migrator = new OptOutTableMigrator($this->objectService, $this->table);

		$first = $migrator->migrate();
		$second = $migrator->migrate();

		$this->assertSame(['read' => 3, 'copied' => 2, 'present' => 0, 'skipped' => 1], $first);
		$this->assertSame(['read' => 3, 'copied' => 0, 'present' => 2, 'skipped' => 1], $second);
		$this->assertSame([false, false], $this->readRbac, 'read as the engine, both runs');
		$this->assertSame(2, $this->table->countAll());

		$piet = $this->table->findByKey(OptOut::keyFor('piet@example.org', 'case', 'zaak/7'));
		$this->assertNotNull($piet);
		$this->assertSame('uuid-piet', $piet->getLegacyUuid());
		$this->assertSame(1788256800, $piet->getCreatedAt());

		$registry = $this->registry();
		$this->assertFalse($registry->decide('klaas@example.org', 'status-update')['send']);
		$this->assertFalse($registry->decide('piet@example.org', 'status-update', 'zaak/7')['send']);

	}//end testTheOldOptOutsAreCopiedOnce()

	/**
	 * The admin list reads the table, newest first.
	 *
	 * @return void
	 */
	public function testTheListReadsTheTable(): void {
		$registry = $this->registry();
		$registry->add('a@example.org', OptOutRegistry::SCOPE_INSTANCE, null, 'administrator');
		$this->now += 10;
		$registry->add('b@example.org', OptOutRegistry::SCOPE_CASE, 'zaak/2');

		$data = $this->controller()->optOuts(50, 0)->getData();

		$this->assertSame(2, $data['total']);
		$this->assertSame('b@example.org', $data['results'][0]['address']);
		$this->assertSame('', $data['results'][1]['caseRef']);

	}//end testTheListReadsTheTable()

	/**
	 * Opening a version 3 link with GET asks to confirm and writes nothing;
	 * the POST writes a channel opt-out and answers 200 without a redirect.
	 *
	 * @return void
	 */
	public function testAGetDoesNotUnsubscribeAndAPostDoes(): void {
		$token = $this->tokens()->mintScoped('+31612345678', 'channel', 'sms', '');
		$controller = $this->controller();

		$page = $controller->unsubscribe($token);
		$this->assertSame(Http::STATUS_OK, $page->getStatus());
		$this->assertSame('confirm', $page->getParams()['state']);
		$this->assertSame('/index.php/apps/integriq/unsubscribe/' . $token, $page->getParams()['action']);
		$this->assertSame(0, $this->table->countAll(), 'a GET writes nothing');

		$done = $controller->unsubscribeConfirm($token);
		$this->assertSame(Http::STATUS_OK, $done->getStatus());
		$this->assertSame('stopped', $done->getParams()['state']);
		$row = array_values($this->table->rows)[0];
		$this->assertSame('opted-out', $row->getState());
		$this->assertSame('channel', $row->getScope());
		$this->assertSame('sms', $row->getChannel());

	}//end testAGetDoesNotUnsubscribeAndAPostDoes()

	/**
	 * "Stop everything" writes an instance opt-out.
	 *
	 * @return void
	 */
	public function testStopEverythingWritesAnInstanceOptOut(): void {
		$token = $this->tokens()->mint('jan@example.org', 'zaak/1');

		$this->controller()->unsubscribeConfirm($token, 'all');

		$row = array_values($this->table->rows)[0];
		$this->assertSame('instance', $row->getScope());

	}//end testStopEverythingWritesAnInstanceOptOut()

	/**
	 * A version 2 link minted before this change still stops its case.
	 *
	 * @return void
	 */
	public function testAVersionTwoLinkStillStopsACase(): void {
		$payload = rtrim(strtr(base64_encode('{"a":"jan@example.org","c":"zaak\/9","e":1999999999}'), '+/', '-_'), '=');
		$token = 'v2.' . $payload . '.' . hash_hmac('sha256', 'v2.' . $payload, self::SECRET);

		$this->assertSame('confirm', $this->controller()->unsubscribe($token)->getParams()['state']);
		$this->controller()->unsubscribeConfirm($token);

		$row = array_values($this->table->rows)[0];
		$this->assertSame('case', $row->getScope());
		$this->assertSame('zaak/9', $row->getCaseRef());

	}//end testAVersionTwoLinkStillStopsACase()

	/**
	 * A short SMS id opens the same confirmation; an unknown one is refused.
	 *
	 * @return void
	 */
	public function testAShortLinkOpensTheConfirmation(): void {
		$material = $this->tokens()->materialFor('+31612345678', 'channel', 'sms', '', 'service', 'https://gem.nl');
		$shortId = substr((string)$material['smsText'], -10);

		$page = $this->controller()->shortLink($shortId);

		$this->assertSame('confirm', $page->getParams()['state']);
		$this->assertSame(0, $this->table->countAll());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->shortLink('ZZZZZZZZZZ')->getStatus());

	}//end testAShortLinkOpensTheConfirmation()

	/**
	 * The controller over the real services.
	 *
	 * @return SenderIdentityController The controller.
	 */
	private function controller(): SenderIdentityController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$registry = $this->registry();
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => '/index.php/apps/integriq/unsubscribe/' . ($params['token'] ?? '')
		);

		return new SenderIdentityController(
			'integriq',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(ActionAuthService::class),
			$this->createMock(SenderIdentityService::class),
			$this->createMock(DomainAlignmentChecker::class),
			$this->tokens(registry: $registry),
			$registry,
			$this->createMock(HoldQueue::class),
			$l,
			$urls
		);

	}//end controller()

	/**
	 * The registry over the table.
	 *
	 * @return OptOutRegistry The registry.
	 */
	private function registry(): OptOutRegistry {
		return $this->fixture()->registry();

	}//end registry()

	/**
	 * The token service.
	 *
	 * @param OptOutRegistry|null $registry The registry, or a fresh one.
	 *
	 * @return UnsubscribeTokenService The service.
	 */
	private function tokens(?OptOutRegistry $registry = null): UnsubscribeTokenService {
		return $this->fixture()->tokens();

	}//end tokens()

	/**
	 * The opt-out services over this test's table, config and clock.
	 *
	 * @return OptOutFixture The fixture.
	 */
	private function fixture(): OptOutFixture {
		if ($this->fixture !== null && $this->fixture->table === $this->table) {
			return $this->fixture;
		}

		return $this->fixture = new OptOutFixture(
			$this,
			$this->createMock(IDBConnection::class),
			$this->table,
			fn (): array => $this->config,
			fn (): int => $this->now
		);

	}//end fixture()

	/**
	 * The app config double over $this->config.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return $appConfig;

	}//end appConfig()

	/**
	 * A clock answering $this->now at each call.
	 *
	 * @return ITimeFactory The clock.
	 */
	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $time;

	}//end clock()

}//end class
