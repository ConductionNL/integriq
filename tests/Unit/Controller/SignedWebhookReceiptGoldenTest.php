<?php

/**
 * The five signed XML receivers verify, store and refuse a delivery the same way.
 *
 * Pins, for the ROD, Verzuimloket and iWMO/iJW receivers and the OSO import,
 * OSO retour and UWLR/Edu-V retour: the gate's own answer for an unverified
 * delivery with nothing handled, the raw bytes reaching the service as the
 * connection's account with `{received: true}`, and a refused write answered
 * by the gate's not-stored response for that webhook with the refusal's
 * reason, so the shared receipt can move without changing any outcome.
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
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IwmoIjwController;
use OCA\Integriq\Controller\OsoController;
use OCA\Integriq\Controller\RodController;
use OCA\Integriq\Controller\UwlrEduVController;
use OCA\Integriq\Controller\VerzuimloketController;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\Integriq\Service\IwmoIjwSyncServiceInterface;
use OCA\Integriq\Service\OsoServiceInterface;
use OCA\Integriq\Service\RodServiceInterface;
use OCA\Integriq\Service\UwlrEduVServiceInterface;
use OCA\Integriq\Service\VerzuimloketServiceInterface;
use OCA\Integriq\Tests\Helpers\PhpInputStream;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Golden tests for the signed XML receivers.
 */
final class SignedWebhookReceiptGoldenTest extends TestCase {

	/**
	 * Restore php://input.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		PhpInputStream::restore();
		parent::tearDown();

	}//end tearDown()

	/**
	 * Each receiver: controller, route, service property and interface, service method, webhook.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string, 3: class-string, 4: string, 5: string}>
	 */
	public static function receivers(): array {
		return [
			'rod retour' => [RodController::class, 'retour', 'rodService', RodServiceInterface::class, 'receiveReturn', WebhookProfilesInterface::ROD],
			'verzuimloket retour' => [VerzuimloketController::class, 'retour', 'verzuimloketService', VerzuimloketServiceInterface::class, 'receiveReturn', WebhookProfilesInterface::VERZUIMLOKET],
			'iwmo-ijw inbound' => [IwmoIjwController::class, 'inbound', 'syncService', IwmoIjwSyncServiceInterface::class, 'receiveReturn', WebhookProfilesInterface::IWMO_IJW],
			'oso import' => [OsoController::class, 'import', 'osoService', OsoServiceInterface::class, 'receiveImport', WebhookProfilesInterface::OSO],
			'oso retour' => [OsoController::class, 'retour', 'osoService', OsoServiceInterface::class, 'receiveReturn', WebhookProfilesInterface::OSO],
			'uwlr-eduv retour' => [UwlrEduVController::class, 'retour', 'uwlrEduVService', UwlrEduVServiceInterface::class, 'receiveReturn', WebhookProfilesInterface::UWLR_EDUV],
		];

	}//end receivers()

	/**
	 * A controller over a gate and service double, with the raw body served on php://input.
	 *
	 * @param class-string         $class    The controller.
	 * @param string               $property The service property.
	 * @param object               $service  The service double.
	 * @param WebhookGateInterface $gate     The gate double.
	 * @param IRequest             $request  The request double.
	 *
	 * @return object
	 */
	private function controller(string $class, string $property, object $service, WebhookGateInterface $gate, IRequest $request): object {
		PhpInputStream::serve('<Retour>K-1</Retour>');
		$reflection = new ReflectionClass($class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty($property)->setValue($controller, $service);
		$reflection->getProperty('gate')->setValue($controller, $gate);
		(function (IRequest $request): void {
			$this->request = $request;
		})->call($controller, $request);

		return $controller;

	}//end controller()

	/**
	 * An unverified delivery is answered by the gate and handles nothing.
	 *
	 * @param class-string $class    The controller.
	 * @param string       $route    The route.
	 * @param string       $property The service property.
	 * @param class-string $service  The service interface.
	 * @param string       $method   The service method.
	 * @param string       $profile  The webhook.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testAnUnverifiedDeliveryIsTheGatesAnswer(string $class, string $route, string $property, string $service, string $method, string $profile): void {
		$request = $this->createMock(IRequest::class);
		$refusal = new JSONResponse(['error' => 'invalid signature'], 401);
		$gate = $this->createMock(WebhookGateInterface::class);
		$gate->expects($this->once())->method('identify')->with($profile, '<Retour>K-1</Retour>', $request)->willReturn($refusal);
		$gate->expects($this->never())->method('deliver');
		$double = $this->createMock($service);
		$double->expects($this->never())->method($method);

		$this->assertSame($refusal, $this->controller(class: $class, property: $property, service: $double, gate: $gate, request: $request)->$route());

	}//end testAnUnverifiedDeliveryIsTheGatesAnswer()

	/**
	 * A verified delivery reaches the service with its raw bytes and is answered received.
	 *
	 * @param class-string $class    The controller.
	 * @param string       $route    The route.
	 * @param string       $property The service property.
	 * @param class-string $service  The service interface.
	 * @param string       $method   The service method.
	 * @param string       $profile  The webhook.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testAVerifiedDeliveryIsStored(string $class, string $route, string $property, string $service, string $method, string $profile): void {
		$identity = new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'c-1');
		$gate = $this->createMock(WebhookGateInterface::class);
		$gate->method('identify')->willReturn($identity);
		$gate->expects($this->once())->method('deliver')->with($identity, $this->anything())->willReturnCallback(fn ($identity, callable $operation) => $operation());
		$gate->expects($this->never())->method('notStored');
		$double = $this->createMock($service);
		$double->expects($this->once())->method($method)->with('<Retour>K-1</Retour>');

		$response = $this->controller(class: $class, property: $property, service: $double, gate: $gate, request: $this->createMock(IRequest::class))->$route();

		$this->assertSame([200, ['received' => true]], [$response->getStatus(), $response->getData()]);
		$this->assertNotSame('', $profile);

	}//end testAVerifiedDeliveryIsStored()

	/**
	 * A refused write is answered by the gate's not-stored response with the refusal's reason.
	 *
	 * @param class-string $class    The controller.
	 * @param string       $route    The route.
	 * @param string       $property The service property.
	 * @param class-string $service  The service interface.
	 * @param string       $method   The service method.
	 * @param string       $profile  The webhook.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testARefusedWriteIsNotStored(string $class, string $route, string $property, string $service, string $method, string $profile): void {
		$notStored = new JSONResponse(['error' => 'not stored'], 503);
		$gate = $this->createMock(WebhookGateInterface::class);
		$gate->method('identify')->willReturn(new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'c-1'));
		$gate->method('deliver')->willThrowException(new RuntimeException('the account may not write'));
		$gate->expects($this->once())->method('notStored')->with($profile, 'the account may not write')->willReturn($notStored);

		$response = $this->controller(class: $class, property: $property, service: $this->createMock($service), gate: $gate, request: $this->createMock(IRequest::class))->$route();

		$this->assertSame($notStored, $response);
		$this->assertNotSame('', $method);

	}//end testARefusedWriteIsNotStored()
}//end class
