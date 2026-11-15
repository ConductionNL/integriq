<?php

/**
 * Unit tests for testing a draft rule and routing held messages again.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IntakeChannelsController;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\HeldMessageFiles;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\IntakeRoutingServiceInterface;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests `testRule()`, `routeAgain()` and `routeAgainMany()`.
 *
 * @covers \OCA\Integriq\Controller\IntakeChannelsController
 * @uses   \OCA\Integriq\Intake\IntakeRoutingService
 * @uses   \OCA\Integriq\Intake\InboundMessage
 * @uses   \OCA\Integriq\Exception\IntakeRoutingException
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
 */
class IntakeRuleTestAndRouteAgainTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest|MockObject
	 */
	private $request;

	/**
	 * The session double.
	 *
	 * @var IUserSession|MockObject
	 */
	private $userSession;

	/**
	 * The action gate double.
	 *
	 * @var ActionAuthService|MockObject
	 */
	private $actionAuth;

	/**
	 * The channel registry double.
	 *
	 * @var IntakeChannelRegistry|MockObject
	 */
	private $registry;

	/**
	 * The OR object service double.
	 *
	 * @var OrObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The event dispatcher double.
	 *
	 * @var IEventDispatcher|MockObject
	 */
	private $eventDispatcher;

	/**
	 * The saved rules findAll() answers with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rules = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rules = [];
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		$this->registry = $this->getMockBuilder(IntakeChannelRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['get', 'has', 'describeAll'])
			->getMock();
		$this->registry->method('has')->willReturnCallback(static fn (string $id): bool => in_array($id, ['public-space-report', 'teams'], true));
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->rules as $index => $rule) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $rule, 'rule-' . $index);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

	}//end setUp()

	/**
	 * A draft that matches the sample answers with the payload the case
	 * would receive and today's winning rule, and stores and dispatches
	 * nothing.
	 *
	 * @return void
	 */
	public function testADraftThatMatchesShowsItsPayloadAndStoresNothing(): void {
		$this->signedInAs('admin');
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), IntakeChannelsController::ACTION_RULES);
		$this->rules = [
			['name' => 'Alles naar algemeen', 'channelId' => 'public-space-report', 'targetSchema' => 'algemene_vraag', 'isEnabled' => true, 'order' => 50],
		];
		$this->paramsAre(
			[
				'rule' => $this->lightingDraft(),
				'sample' => ['channelId' => 'teams', 'text' => 'De lantaarnpaal brandt niet.', 'location' => ['latitude' => 52.09, 'longitude' => 5.12]],
			]
		);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$response = $this->controller($this->realRouting(['omschrijving', 'locatie']))->testRule();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'matches' => true,
				'payload' => ['omschrijving' => 'De lantaarnpaal brandt niet.', 'locatie' => ['latitude' => 52.09, 'longitude' => 5.12]],
				'winningRule' => ['name' => 'Alles naar algemeen', 'order' => 50],
			],
			$response->getData()
		);

	}//end testADraftThatMatchesShowsItsPayloadAndStoresNothing()

	/**
	 * A draft that does not match answers so, with no payload and no winner
	 * when no saved rule matches either.
	 *
	 * @return void
	 */
	public function testADraftThatDoesNotMatchSaysSo(): void {
		$this->signedInAs('admin');
		$this->paramsAre(['rule' => $this->lightingDraft(), 'sample' => ['text' => 'Losliggende stoeptegel.']]);

		$response = $this->controller($this->realRouting(['omschrijving', 'locatie']))->testRule();

		$this->assertSame(['matches' => false, 'payload' => [], 'winningRule' => null], $response->getData());

	}//end testADraftThatDoesNotMatchSaysSo()

	/**
	 * A draft the save would refuse is refused by the test with the same
	 * reason.
	 *
	 * @return void
	 */
	public function testADraftTheSaveWouldRefuseIsRefused(): void {
		$this->signedInAs('admin');
		$this->paramsAre(['rule' => $this->lightingDraft(), 'sample' => ['text' => 'lantaarnpaal']]);

		$response = $this->controller($this->realRouting(['omschrijving']))->testRule();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Case type "melding_openbare_ruimte" has no field "locatie".'], $response->getData());

	}//end testADraftTheSaveWouldRefuseIsRefused()

	/**
	 * A test without a rule object is a bad request.
	 *
	 * @return void
	 */
	public function testATestWithoutARuleIsABadRequest(): void {
		$this->signedInAs('admin');
		$this->paramsAre(['rule' => 'lantaarnpaal', 'sample' => []]);

		$response = $this->controller($this->realRouting([]))->testRule();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A test needs a rule and a sample message.'], $response->getData());

	}//end testATestWithoutARuleIsABadRequest()

	/**
	 * Anonymous callers and accounts without the rules action cannot test
	 * or route again.
	 *
	 * @return void
	 */
	public function testAnonymousAndOrdinaryAccountsAreRefused(): void {
		$routing = $this->mockedRouting();
		$routing->expects($this->never())->method('reroute');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($routing)->testRule()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($routing)->routeAgain('m-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($routing)->routeAgainMany()->getStatus());

		$this->signedInAs('burger');
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));
		$this->expectException(OCSForbiddenException::class);
		$this->controller($routing)->routeAgain('m-1');

	}//end testAnonymousAndOrdinaryAccountsAreRefused()

	/**
	 * Routing one message again answers with its new state.
	 *
	 * @return void
	 */
	public function testOneMessageRoutedAgainAnswersItsNewState(): void {
		$this->signedInAs('admin');
		$routing = $this->mockedRouting();
		$routing->expects($this->once())->method('reroute')->with('m-1')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'routed', 'ruleName' => 'Straatverlichting', 'targetRef' => 'zaak/7', 'reason' => ''],
				'm-1'
			)
		);

		$response = $this->controller($routing)->routeAgain('m-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id' => 'm-1', 'status' => 'routed', 'ruleName' => 'Straatverlichting', 'targetRef' => 'zaak/7', 'reason' => ''],
			$response->getData()
		);

	}//end testOneMessageRoutedAgainAnswersItsNewState()

	/**
	 * A message that cannot be routed again is a bad request naming why.
	 *
	 * @return void
	 */
	public function testAMessageThatCannotBeRoutedAgainIsABadRequest(): void {
		$this->signedInAs('admin');
		$routing = $this->mockedRouting();
		$routing->method('reroute')->willThrowException(new IntakeRoutingException('Only a held or failed message can be routed again.'));

		$response = $this->controller($routing)->routeAgain('m-9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['id' => 'm-9', 'error' => 'Only a held or failed message can be routed again.'], $response->getData());

	}//end testAMessageThatCannotBeRoutedAgainIsABadRequest()

	/**
	 * Three held messages routed in bulk are each answered: two routed, one
	 * still held.
	 *
	 * @return void
	 */
	public function testABulkRouteAnswersEachMessage(): void {
		$this->signedInAs('admin');
		$this->paramsAre(['ids' => ['a', 'b', 'c']]);
		$routing = $this->mockedRouting();
		$routing->expects($this->exactly(3))->method('reroute')->willReturnCallback(
			fn (string $uuid) => ObjectServiceMockBuilder::objectEntity(
				$this,
				match ($uuid) {
					'b' => ['status' => 'held', 'reason' => 'No routing rule matched channel "teams".'],
					default => ['status' => 'routed', 'ruleName' => 'Teams', 'targetRef' => 'zaak/' . $uuid],
				},
				$uuid
			)
		);

		$results = $this->controller($routing)->routeAgainMany()->getData()['results'];

		$this->assertSame(['a', 'b', 'c'], array_column($results, 'id'));
		$this->assertSame(['routed', 'held', 'routed'], array_column($results, 'status'));
		$this->assertSame('zaak/c', $results[2]['targetRef']);
		$this->assertSame('No routing rule matched channel "teams".', $results[1]['reason']);

	}//end testABulkRouteAnswersEachMessage()

	/**
	 * A bulk route without ids is a bad request.
	 *
	 * @return void
	 */
	public function testABulkRouteWithoutIdsIsABadRequest(): void {
		$this->signedInAs('admin');
		$this->paramsAre(['ids' => []]);
		$routing = $this->mockedRouting();
		$routing->expects($this->never())->method('reroute');

		$response = $this->controller($routing)->routeAgainMany();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Choose the messages to route again.'], $response->getData());

	}//end testABulkRouteWithoutIdsIsABadRequest()

	/**
	 * A draft lighting rule on the public space channel.
	 *
	 * @return array<string,mixed> The rule.
	 */
	private function lightingDraft(): array {
		return [
			'name' => 'Straatverlichting',
			'channelId' => 'public-space-report',
			'condition' => ['field' => 'text', 'operator' => 'contains', 'value' => 'lantaarnpaal'],
			'targetSchema' => 'melding_openbare_ruimte',
			'fieldMapping' => ['omschrijving' => 'text'],
			'locationField' => 'locatie',
		];

	}//end lightingDraft()

	/**
	 * The real routing engine over a case type with these fields.
	 *
	 * @param array<int,string> $fields The case type's fields.
	 *
	 * @return IntakeRoutingService The engine.
	 */
	private function realRouting(array $fields): IntakeRoutingService {
		$properties = array_fill_keys($fields, ['type' => 'string']);
		$schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemaMapper->method('find')->willReturn(
			new class($properties) {

				/**
				 * Constructor.
				 *
				 * @param array<string,mixed> $properties The declared properties.
				 */
				public function __construct(private readonly array $properties) {

				}

				/**
				 * The declared properties.
				 *
				 * @return array<string,mixed>
				 */
				public function getProperties(): array {
					return $this->properties;
				}
			}
		);

		return new IntakeRoutingService(
			$this->objectService,
			$this->eventDispatcher,
			$schemaMapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(HeldMessageFiles::class),
		);

	}//end realRouting()

	/**
	 * A routing double whose reroute() the test sets.
	 *
	 * @return IntakeRoutingServiceInterface|MockObject
	 */
	private function mockedRouting(): MockObject {
		return $this->createMock(IntakeRoutingServiceInterface::class);

	}//end mockedRouting()

	/**
	 * The controller over a routing engine.
	 *
	 * @param IntakeRoutingServiceInterface $routing The routing engine.
	 *
	 * @return IntakeChannelsController
	 */
	private function controller(IntakeRoutingServiceInterface $routing): IntakeChannelsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new IntakeChannelsController(
			'integriq',
			$this->request,
			$this->userSession,
			$this->actionAuth,
			$this->registry,
			$routing,
			$this->getMockBuilder(IntakeReplyService::class)->disableOriginalConstructor()->getMock(),
			$this->createMock(WebhookGate::class),
			$this->objectService,
			$l10n,
		);

	}//end controller()

	/**
	 * The request's parameters.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function paramsAre(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

	}//end paramsAre()

	/**
	 * Sign in as an account.
	 *
	 * @param string $uid The account id.
	 *
	 * @return void
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signedInAs()

}//end class
