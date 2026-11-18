<?php

/**
 * Unit tests for the payments review endpoints of PaymentsController.
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
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-2
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\PaymentsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refresh status, the provider list and the provider check, over a real PaymentIntentService.
 *
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md
 */
class PaymentsReviewControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var MolliePaymentProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mollieProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IUser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $user;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->mollieProvider = $this->createMock(MolliePaymentProvider::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->user = $this->createMock(IUser::class);

	}//end setUp()

	/**
	 * The controller over a real PaymentIntentService with the real log binding.
	 *
	 * @param IUser|null $user The signed-in user, or null for none.
	 *
	 * @return PaymentsController
	 */
	private function controller(?IUser $user): PaymentsController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$service = new PaymentIntentService(
			$this->objectService,
			new LogPaymentProvider(),
			$this->mollieProvider,
			$this->eventService,
			$l,
			$logger
		);

		return new PaymentsController(
			'integriq',
			$this->request,
			$service,
			$this->createMock(WebhookSignatureService::class),
			$userSession,
			$this->actionAuth,
			$l,
			$logger
		);

	}//end controller()

	/**
	 * A real ObjectEntity.
	 *
	 * @param array  $data The object data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);
		return $entity;

	}//end entity()

	/**
	 * A payment the provider reports paid is settled by a refresh, with one event (REQ-PRV-002).
	 *
	 * @return void
	 */
	public function testRefreshSettlesAPaymentTheWebhookNeverReached(): void {
		$record = $this->entity(['sourceSlug' => 'ideal', 'providerPaymentId' => 'MOCK-PAY-3', 'paymentStatus' => 'open', 'lastOutcome' => null], 'pi-3');
		$source = $this->entity(
			['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['provider' => 'log', 'mockStatuses' => ['MOCK-PAY-3' => 'paid']]],
			'src-1'
		);
		$this->objectService->method('find')->willReturn($record);
		$this->objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => ['results' => [(($config['filters']['schema'] ?? null) === 'source') ? $source : $record]]
		);
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($record);
		$this->eventService->expects($this->once())->method('emitCloudEvent');
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'payments.review');

		$response = $this->controller(user: $this->user)->refresh(id: 'pi-3');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['result' => 'applied', 'outcome' => 'captured', 'paymentStatus' => 'paid'], $response->getData());

	}//end testRefreshSettlesAPaymentTheWebhookNeverReached()

	/**
	 * A user without payments.review is refused before any read or provider call (REQ-PRV-002).
	 *
	 * @return void
	 */
	public function testRefreshIsRefusedBeforeAnyProviderCall(): void {
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException("Action 'payments.review' requires admin rights"));
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('findAll');
		$this->mollieProvider->expects($this->never())->method('fetchPaymentStatus');

		$this->expectException(OCSForbiddenException::class);

		$this->controller(user: $this->user)->refresh(id: 'pi-3');

	}//end testRefreshIsRefusedBeforeAnyProviderCall()

	/**
	 * Refresh of an unknown payment is 404; without a session it is 401 with no action check.
	 *
	 * @return void
	 */
	public function testRefreshAnswersNotFoundAndUnauthenticated(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller(user: $this->user)->refresh(id: 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('not_found', $response->getData()['error']);

		$this->actionAuth->expects($this->never())->method('requireAction');
		$anonymous = $this->controller(user: null)->refresh(id: 'pi-3');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());

	}//end testRefreshAnswersNotFoundAndUnauthenticated()

	/**
	 * A refresh on a source with an unknown provider is a 502 naming the value (REQ-PRV-004).
	 *
	 * @return void
	 */
	public function testRefreshOnAnUnknownProviderIsABadGatewayNamingIt(): void {
		$record = $this->entity(['sourceSlug' => 'ideal', 'providerPaymentId' => 'MOCK-PAY-3'], 'pi-3');
		$source = $this->entity(['slug' => 'ideal', 'type' => 'payment', 'configuration' => ['provider' => 'Mollie']], 'src-1');
		$this->objectService->method('find')->willReturn($record);
		$this->objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => ['results' => [(($config['filters']['schema'] ?? null) === 'source') ? $source : $record]]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller(user: $this->user)->refresh(id: 'pi-3');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertStringContainsString('"Mollie"', $response->getData()['message']);

	}//end testRefreshOnAnUnknownProviderIsABadGatewayNamingIt()

	/**
	 * The provider list names log and mollie for a signed-in user (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testProvidersListsEveryBinding(): void {
		$response = $this->controller(user: $this->user)->providers();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['log', 'mollie'], array_column($response->getData()['providers'], 'id'));
		$this->assertSame([false, true], array_column($response->getData()['providers'], 'needsKey'));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(user: null)->providers()->getStatus());

	}//end testProvidersListsEveryBinding()

	/**
	 * The check probes the draft configuration and answers without the key (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testCheckProbesTheDraftConfiguration(): void {
		$configuration = ['provider' => 'mollie', 'authentication' => ['credentialRef' => ['credentialId' => 'cred-1']]];
		$this->request->method('getParam')->with('configuration')->willReturn($configuration);
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'payments.review');
		$this->mollieProvider->expects($this->once())
			->method('probe')
			->with($configuration)
			->willReturn(['ok' => true, 'mode' => 'test', 'profile' => 'School', 'message' => null]);

		$response = $this->controller(user: $this->user)->check();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['provider' => 'mollie', 'ok' => true, 'mode' => 'test', 'profile' => 'School', 'message' => null], $response->getData());

	}//end testCheckProbesTheDraftConfiguration()

	/**
	 * The check refuses a body without a configuration object, and a refused user probes nothing.
	 *
	 * @return void
	 */
	public function testCheckRefusesAMissingConfigurationAndAnUnauthorisedUser(): void {
		$this->request->method('getParam')->willReturn('not-an-object');
		$this->mollieProvider->expects($this->never())->method('probe');

		$response = $this->controller(user: $this->user)->check();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('refused'));
		$this->expectException(OCSForbiddenException::class);
		$this->controller(user: $this->user)->check();

	}//end testCheckRefusesAMissingConfigurationAndAnUnauthorisedUser()
}//end class
