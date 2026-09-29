<?php

/**
 * Broker secrets on a subscription are masked by every subscription endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integriq#2211: `protocolSettings` is `writeOnly`, so none of its secrets may
 * come back in clear from the app's own subscription endpoints. The endpoints
 * serve the entity's stored array (`getObject()`), and `redactSubscription()`
 * masked the two signing secrets only, so a broker `password` or `token` under
 * `protocolSettings.broker` (read by the RabbitMQ and Kafka REST transports),
 * and an `Authorization` header under `protocolSettings.headers`, came back as
 * stored.
 *
 * Each endpoint is driven with the SAME raw subscription and the serialised
 * response is searched for every secret, so a new read path that forgets the
 * redaction fails here too.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
 */
class EventsControllerBrokerSecretRedactionTest extends TestCase {

	/**
	 * Every secret the fixture stores; none may appear in any response.
	 *
	 * @var array<int, string>
	 */
	private const SECRETS = [
		'rabbit-pw-9f2c',
		'kafka-token-77ab',
		'Bearer receiver-key-31d0',
		'whsec_current-5e1a',
		'whsec_previous-0c4d',
	];

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * @var OrObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * The controller under test.
	 *
	 * @var EventsController
	 */
	private EventsController $controller;

	/**
	 * Set up the controller with every action granted.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$this->orObjectService = $this->createMock(OrObjectService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new EventsController(
			'integriq',
			$this->request,
			$this->orObjectService,
			$this->createMock(EventService::class),
			$l10n,
			$userSession,
			$this->createMock(ActionAuthService::class),
			$this->createMock(WebhookSignatureService::class)
		);
	}//end setUp()

	/**
	 * A broker subscription as stored, with every secret-bearing key set.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedSubscription(): array {
		return [
			'name' => 'orders-to-rabbit',
			'sink' => 'https://receiver.example.org/hook',
			'action' => ['kind' => 'broker', 'brokerId' => 'rabbitmq-http', 'topic' => 'orders'],
			'protocolSettings' => [
				'broker' => [
					'baseUrl' => 'https://rabbit.example.org:15672',
					'vhost' => '/integriq',
					'username' => 'integriq',
					'password' => 'rabbit-pw-9f2c',
					'token' => 'kafka-token-77ab',
					'credentialRef' => 'cred-uuid-1',
				],
				'headers' => [
					'Authorization' => 'Bearer receiver-key-31d0',
					'X-Trace-Source' => 'integriq',
				],
				'signingSecret' => 'whsec_current-5e1a',
				'previousSigningSecret' => 'whsec_previous-0c4d',
				'secretRotatedAt' => '2026-09-27T10:00:00+00:00',
			],
		];
	}//end storedSubscription()

	/**
	 * The four subscription read surfaces named in integriq#2211.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return [
			'subscribe' => ['subscribe'],
			'updateSubscription' => ['updateSubscription'],
			'subscriptions' => ['subscriptions'],
			'subscriptionMessages' => ['subscriptionMessages'],
		];
	}//end endpoints()

	/**
	 * Call one endpoint with the stored subscription behind every read path.
	 *
	 * @param string $endpoint The controller method to call.
	 *
	 * @return JSONResponse
	 */
	private function callEndpoint(string $endpoint): JSONResponse {
		$entity = ObjectServiceMockBuilder::objectEntity($this, self::storedSubscription(), 'sub-uuid-1');
		$this->orObjectService->method('saveObject')->willReturn($entity);
		$this->orObjectService->method('find')->willReturn($entity);
		$this->orObjectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($entity) {
				if (($config['filters']['schema'] ?? null) === 'event_message') {
					return ['results' => [], 'total' => 0];
				}

				return ['results' => [$entity], 'total' => 1];
			}
		);

		return match ($endpoint) {
			'subscribe' => $this->controller->subscribe(),
			'updateSubscription' => $this->controller->updateSubscription('sub-uuid-1'),
			'subscriptions' => $this->controller->subscriptions(),
			'subscriptionMessages' => $this->controller->subscriptionMessages('sub-uuid-1'),
		};
	}//end callEndpoint()

	/**
	 * Pull the one subscription array out of an endpoint's response body.
	 *
	 * @param string $endpoint The endpoint that produced the body.
	 * @param array $data The response body.
	 *
	 * @return array<string, mixed>
	 */
	private static function subscriptionFrom(string $endpoint, array $data): array {
		return match ($endpoint) {
			'subscriptions' => $data['results'][0],
			'subscriptionMessages' => $data['subscription'],
			default => $data,
		};
	}//end subscriptionFrom()

	/**
	 * No stored secret appears anywhere in the serialised response.
	 *
	 * @param string $endpoint The controller method to call.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testNoStoredSecretIsReturnedInClear(string $endpoint): void {
		$response = $this->callEndpoint(endpoint: $endpoint);
		$serialised = (string)json_encode($response->getData());

		$this->assertSame(200, $response->getStatus());
		foreach (self::SECRETS as $secret) {
			$this->assertStringNotContainsString(
				$secret,
				$serialised,
				$endpoint . '() returned a stored secret in clear'
			);
		}
	}//end testNoStoredSecretIsReturnedInClear()

	/**
	 * Secrets are masked in place, and the non-secret settings survive, so the
	 * form can still tell that a password is stored and show the connection.
	 *
	 * @param string $endpoint The controller method to call.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testSecretsAreMaskedAndSettingsSurvive(string $endpoint): void {
		$response = $this->callEndpoint(endpoint: $endpoint);
		$settings = self::subscriptionFrom(endpoint: $endpoint, data: $response->getData())['protocolSettings'];

		$this->assertNotSame('', (string)$settings['broker']['password'], 'a masked password still shows one is stored');
		$this->assertNotSame('rabbit-pw-9f2c', $settings['broker']['password']);
		$this->assertNotSame('kafka-token-77ab', $settings['broker']['token']);
		$this->assertNotSame('Bearer receiver-key-31d0', $settings['headers']['Authorization']);
		$this->assertSame('**********', $settings['signingSecret']);
		$this->assertSame('**********', $settings['previousSigningSecret']);

		$this->assertSame('https://rabbit.example.org:15672', $settings['broker']['baseUrl']);
		$this->assertSame('/integriq', $settings['broker']['vhost']);
		$this->assertSame('cred-uuid-1', $settings['broker']['credentialRef'], 'a credential reference is not a secret');
		$this->assertSame('integriq', $settings['headers']['X-Trace-Source']);
		$this->assertSame('2026-09-27T10:00:00+00:00', $settings['secretRotatedAt'], 'the rotation time is not a secret');
	}//end testSecretsAreMaskedAndSettingsSurvive()
}//end class
