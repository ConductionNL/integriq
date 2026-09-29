<?php

/**
 * The subscription form asks which brokers this instance has.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-app-lists-the-broker-transports-it-has-req-ebsc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Broker\BrokerTransportRegistry;
use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCA\Integriq\Broker\Transport\CloudEventsHttpTransport;
use OCA\Integriq\Broker\Transport\KafkaRestTransport;
use OCA\Integriq\Broker\Transport\LogBrokerTransport;
use OCA\Integriq\Broker\Transport\RabbitMqHttpTransport;
use OCA\Integriq\Controller\EventBrokersController;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-EBSC-001: GET /api/events/brokers.
 */
class EventBrokersControllerTest extends TestCase {

	/**
	 * A controller over the four transports the app registers.
	 *
	 * @param ActionAuthService $actionAuth The action check.
	 *
	 * @return EventsController
	 */
	private function controller(ActionAuthService $actionAuth): EventBrokersController {
		$logger = $this->createMock(LoggerInterface::class);
		$clients = $this->createMock(IClientService::class);
		$binding = new CloudEventHttpBinding();
		$registry = new BrokerTransportRegistry(
			logger: $logger,
			transports: [
				new RabbitMqHttpTransport($clients, $binding),
				new KafkaRestTransport($clients),
				new CloudEventsHttpTransport($clients, $binding),
				new LogBrokerTransport($logger),
			]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new EventBrokersController('integriq', $this->createMock(IRequest::class), $registry, $session, $actionAuth, $l10n);

	}//end controller()

	/**
	 * The list reflects the registry: four entries, the log transport labelled as no broker.
	 *
	 * @return void
	 */
	public function testTheListReflectsTheRegistry(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'event.subscriptions');

		$response = $this->controller($actionAuth)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$brokers = $response->getData()['results'];
		$this->assertSame(['cloudevents-http', 'kafka-rest', 'log', 'rabbitmq'], array_column($brokers, 'id'));
		$byId = array_column($brokers, null, 'id');
		$this->assertSame('No broker configured', $byId['log']['label']);
		$this->assertSame(['structured'], $byId['kafka-rest']['contentModes']);
		$this->assertTrue($byId['rabbitmq']['needsTopic']);

	}//end testTheListReflectsTheRegistry()

	/**
	 * The list needs the action: a user outside event.subscriptions is refused.
	 *
	 * @return void
	 */
	public function testTheListNeedsTheAction(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('Not allowed'));

		$this->expectException(OCSForbiddenException::class);
		$this->controller($actionAuth)->index();

	}//end testTheListNeedsTheAction()

}//end class
