<?php

/**
 * Unit tests for CloudEventReceiverController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCA\Integriq\Controller\CloudEventReceiverController;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Event\CloudEventReceiver;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The receiver URL: signature first, then the envelope, then store once and route.
 *
 * @covers \OCA\Integriq\Controller\CloudEventReceiverController
 * @uses   \OCA\Integriq\Broker\CloudEventHttpBinding
 * @uses   \OCA\Integriq\Service\Dso\DsoIdentity
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
class CloudEventReceiverControllerTest extends TestCase {

	/**
	 * The webhook gate.
	 *
	 * @var WebhookGateInterface&MockObject
	 */
	private WebhookGateInterface&MockObject $gate;

	/**
	 * The receiver service.
	 *
	 * @var CloudEventReceiver&MockObject
	 */
	private CloudEventReceiver&MockObject $receiver;

	/**
	 * Set up the mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(WebhookGateInterface::class);
		$this->receiver = $this->createMock(CloudEventReceiver::class);
	}//end setUp()

	/**
	 * The controller with a fixed raw body and request headers.
	 *
	 * @param string               $rawBody The request body.
	 * @param array<string,string> $headers The request headers.
	 *
	 * @return CloudEventReceiverController
	 */
	private function controller(string $rawBody, array $headers): CloudEventReceiverController {
		$lower = array_change_key_case($headers, CASE_LOWER);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($lower[strtolower($name)] ?? '')
		);

		// getRawContent() reads php://input, which a unit test cannot fill.
		$controller = $this->getMockBuilder(CloudEventReceiverController::class)
			->setConstructorArgs(['integriq', $request, $this->gate, new CloudEventHttpBinding(), $this->receiver])
			->onlyMethods(['getRawContent'])
			->getMock();
		$controller->method('getRawContent')->willReturn($rawBody);

		return $controller;
	}//end controller()

	/**
	 * A structured event body.
	 *
	 * @param array<string,mixed> $override Attributes to change.
	 *
	 * @return string
	 */
	private function structured(array $override = []): string {
		return (string)json_encode(
			array_merge(
				['specversion' => '1.0', 'id' => 'a1', 'source' => '/shop', 'type' => 'nl.example.order.created', 'data' => ['order' => 42]],
				$override
			)
		);
	}//end structured()

	/**
	 * Let the gate admit the request and run the store directly.
	 *
	 * @return void
	 */
	private function admit(): void {
		$this->gate->method('identify')->willReturn(new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'consumer-1'));
		$this->gate->method('deliver')->willReturnCallback(
			static fn (DsoIdentity $identity, callable $operation): mixed => $operation()
		);
	}//end admit()

	/**
	 * A valid structured event is checked against its source's profile, stored and answered 202.
	 *
	 * @return void
	 */
	public function testAValidEventIsStoredAndAnswered202(): void {
		$body = $this->structured();
		$this->gate->expects($this->once())->method('identify')
			->with('cloudevents-source-webshop', $body, $this->isInstanceOf(IRequest::class))
			->willReturn(new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'consumer-1'));
		$this->gate->method('deliver')->willReturnCallback(
			static fn (DsoIdentity $identity, callable $operation): mixed => $operation()
		);
		$stored = new ObjectEntity();
		$stored->setUuid('event-uuid');
		$this->receiver->expects($this->once())->method('receive')
			->with('webshop', $this->callback(static fn (array $event): bool => $event['type'] === 'nl.example.order.created' && $event['data'] === ['order' => 42]))
			->willReturn($stored);

		$response = $this->controller(rawBody: $body, headers: ['Content-Type' => 'application/cloudevents+json'])->receive(source: 'WebShop');

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['received' => true, 'id' => 'event-uuid'], $response->getData());
	}//end testAValidEventIsStoredAndAnswered202()

	/**
	 * A binary-mode event is stored the same way as its structured form.
	 *
	 * @return void
	 */
	public function testABinaryEventIsReadFromItsHeaders(): void {
		$this->admit();
		$this->receiver->expects($this->once())->method('receive')
			->with(
				'webshop',
				[
					'specversion' => '1.0',
					'id' => 'a1',
					'source' => '/shop',
					'type' => 'nl.example.order.created',
					'datacontenttype' => 'application/json',
					'data' => ['order' => 42],
				]
			)
			->willReturn(new ObjectEntity());

		$response = $this->controller(
			rawBody: '{"order":42}',
			headers: ['Content-Type' => 'application/json', 'ce-specversion' => '1.0', 'ce-id' => 'a1', 'ce-source' => '/shop', 'ce-type' => 'nl.example.order.created']
		)->receive(source: 'webshop');

		$this->assertSame(202, $response->getStatus());
	}//end testABinaryEventIsReadFromItsHeaders()

	/**
	 * An envelope without a type answers 400 naming it, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutATypeIsRefusedWith400(): void {
		$this->admit();
		$this->receiver->expects($this->never())->method('receive');

		$body = (string)json_encode(['specversion' => '1.0', 'id' => 'a1', 'source' => '/shop']);
		$response = $this->controller(rawBody: $body, headers: ['Content-Type' => 'application/cloudevents+json'])->receive(source: 'webshop');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'The CloudEvent has no type.'], $response->getData());
	}//end testAnEnvelopeWithoutATypeIsRefusedWith400()

	/**
	 * A repeated id answers 200 without routing it again.
	 *
	 * @return void
	 */
	public function testARepeatedEventAnswers200(): void {
		$this->admit();
		$this->receiver->expects($this->once())->method('receive')->willReturn(null);

		$response = $this->controller(rawBody: $this->structured(), headers: ['Content-Type' => 'application/cloudevents+json'])->receive(source: 'webshop');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['received' => true, 'duplicate' => true], $response->getData());
	}//end testARepeatedEventAnswers200()

	/**
	 * An unsigned request gets the gate's 401 and the body is never read or stored.
	 *
	 * @return void
	 */
	public function testAnUnsignedRequestIsRefusedWith401(): void {
		$refusal = new JSONResponse(['error' => 'invalid signature'], 401);
		$this->gate->method('identify')->willReturn($refusal);
		$this->gate->expects($this->never())->method('deliver');
		$this->receiver->expects($this->never())->method('receive');

		$response = $this->controller(rawBody: 'not even json', headers: [])->receive(source: 'webshop');

		$this->assertSame($refusal, $response);
		$this->assertSame(401, $response->getStatus());
	}//end testAnUnsignedRequestIsRefusedWith401()

	/**
	 * A store that fails is handed to the gate's notStored(), so the source sends it again.
	 *
	 * @return void
	 */
	public function testAFailedStoreAnswersTheGatesNotStored(): void {
		$this->admit();
		$this->receiver->method('receive')->willThrowException(new RuntimeException('write refused'));
		$unavailable = new JSONResponse(['error' => 'cloudevents_delivery_not_stored'], 503);
		$this->gate->expects($this->once())->method('notStored')
			->with('cloudevents-source-webshop', 'write refused')
			->willReturn($unavailable);

		$response = $this->controller(rawBody: $this->structured(), headers: ['Content-Type' => 'application/cloudevents+json'])->receive(source: 'webshop');

		$this->assertSame($unavailable, $response);
	}//end testAFailedStoreAnswersTheGatesNotStored()
}//end class
