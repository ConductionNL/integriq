<?php

/**
 * The facade's routes, built the way Nextcloud builds them.
 *
 * Every other facade test hands the handlers their seams by hand. This one
 * builds the controller from the factories the app registers, over a fake
 * OpenRegister, so a seam left at null (every route answering 401, 404 or an
 * empty list) fails here instead of on an instance.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Controller\ObjectenApiController;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectenOpenRegisterAccess;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjectenWiring;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCA\Integriq\Service\Objecten\OpenRegisterObjectenGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verifies that the registered factories wire every seam (REQ-OAF-002..005).
 */
class ObjectenWiringTest extends TestCase {

	/**
	 * The factories the app registered, by class.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Services already built.
	 *
	 * @var array<string, mixed>
	 */
	private array $built = [];

	/**
	 * The user the fake session holds.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * Who was signed in each time the object service read a schema's objects.
	 *
	 * @var array<string, string|null>
	 */
	private array $readAs = [];

	/**
	 * What the object service was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Declarations the fake object service answers instead of its own, by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private ?array $declarations = null;

	/**
	 * What the gateway logged as a warning.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * What the fake broker throws instead of answering, when set.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $brokerThrows = null;

	/**
	 * What went out as a CloudEvent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $announced = [];

	/**
	 * Register the app's factories into a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		ObjectenWiring::register(context: $context);
	}//end setUp()

	/**
	 * Every facade service the controller needs has a factory.
	 *
	 * @return void
	 */
	public function testEveryFacadeServiceIsRegistered(): void {
		foreach ([
			ObjecttypeRegistry::class,
			ObjectenTokenService::class,
			ObjecttypeEndpointHandler::class,
			ObjectEndpointHandler::class,
			ObjectWriteHandler::class,
		] as $service) {
			$this->assertArrayHasKey($service, $this->factories, $service . ' is autowired with its seams at null.');
		}
	}//end testEveryFacadeServiceIsRegistered()

	/**
	 * The app's register() calls the wiring; without the call the factories exist and nothing uses them.
	 *
	 * @return void
	 */
	public function testTheApplicationRegistersTheWiring(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString('ObjectenWiring::register(context: $context)', $source);
	}//end testTheApplicationRegistersTheWiring()

	/**
	 * A declared token reads a declared objecttype's objects, as its principal.
	 *
	 * @return void
	 */
	public function testAListAnswersTheRegistersObjectsReadAsTheTokensPrincipal(): void {
		$response = $this->controller(authorization: 'Token the-key', type: 'aaa-published')->objects();

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();
		$this->assertSame(1, $body['count']);
		$this->assertSame('m-1', $body['results'][0]['uuid']);
		$this->assertSame(['Kerkstraat'], [$body['results'][0]['record']['data']['straatnaam']]);
		$this->assertSame('svc-meldingen', $this->readAs['melding'], 'The read did not run as the token\'s principal.');
		$this->assertNull($this->sessionUser, 'The principal stayed signed in after the read.');
	}//end testAListAnswersTheRegistersObjectsReadAsTheTokensPrincipal()

	/**
	 * The published objecttype list answers the declaration.
	 *
	 * @return void
	 */
	public function testTheObjecttypeListAnswersTheDeclaration(): void {
		$response = $this->controller(authorization: 'Token the-key')->objecttypes();

		$this->assertSame(200, $response->getStatus());
		$this->assertStringContainsString('aaa-published', (string)json_encode($response->getData()));
	}//end testTheObjecttypeListAnswersTheDeclaration()

	/**
	 * An unknown key is refused before any register is read.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefusedBeforeARegisterIsRead(): void {
		$response = $this->controller(authorization: 'Token not-the-key', type: 'aaa-published')->objects();

		$this->assertSame(401, $response->getStatus());
		$this->assertArrayNotHasKey('melding', $this->readAs);
	}//end testAnUnknownKeyIsRefusedBeforeARegisterIsRead()

	/**
	 * A create lands in the object service as the principal and is announced.
	 *
	 * @return void
	 */
	public function testACreateIsWrittenAsThePrincipalAndAnnounced(): void {
		$response = $this->controller(
			authorization: 'Token the-key',
			type: 'aaa-published',
			params: ['record' => ['data' => ['straatnaam' => 'Dorpsstraat']]]
		)->createObject();

		$this->assertSame(201, $response->getStatus(), (string)json_encode($response->getData()));
		$this->assertCount(1, $this->saved);
		$this->assertSame('meldingen', $this->saved[0]['register']);
		$this->assertSame('svc-meldingen', $this->saved[0]['as']);
		$this->assertCount(1, $this->announced);
		$this->assertSame('nl.vng.objecten.object.create', $this->announced[0]['type']);
	}//end testACreateIsWrittenAsThePrincipalAndAnnounced()

	/**
	 * A principal that is no user of the instance reads nothing.
	 *
	 * @return void
	 */
	public function testAPrincipalThatIsNoUserReadsNothing(): void {
		$access = $this->container()->get(ObjectenOpenRegisterAccess::class);

		$this->expectException(RuntimeException::class);
		$access->readObjects(register: 'meldingen', schema: 'melding', principal: 'nobody');
	}//end testAPrincipalThatIsNoUserReadsNothing()

	/**
	 * The seeded declarations validate against their schemas and load without a refusal.
	 *
	 * The gateway reads `publishedUuid`, `credential`, `principal` and
	 * `permissions` by name; a schema that spelled one differently would store
	 * declarations the facade then refuses one by one.
	 *
	 * @return void
	 */
	public function testTheSeededDeclarationsValidateAndLoad(): void {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/integriq_mock_register.json'), true);
		$rows = ['objecttype' => [], 'objecten_token' => []];
		foreach ($mock['components']['objects'] as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			if (array_key_exists($schema, $rows) === true) {
				$this->assertSame([], RegisterSchemaValidator::errors($schema, $object), $object['@self']['slug']);
				$rows[$schema][] = $object;
			}
		}

		$this->assertCount(3, $rows['objecttype']);
		$this->assertCount(3, $rows['objecten_token']);

		$this->declarations = $rows;
		$gateway = $this->container()->get(OpenRegisterObjectenGateway::class);
		$this->assertSame([], $gateway->registry()->refused());
		$this->assertCount(3, $gateway->registry()->all());
		$gateway->tokens();
		$this->assertSame([], $this->warnings);
	}//end testTheSeededDeclarationsValidateAndLoad()

	/**
	 * A broker refusal that quotes the reference and the key leaves neither in the log.
	 *
	 * The broker's exception message is not ours to trust: it can name the
	 * reference, and a careless one the key. The gateway logs the exception
	 * class only, so the request is refused and the log says why without
	 * either.
	 *
	 * @return void
	 */
	public function testNoKeyMaterialReachesTheLogWhenTheBrokerRefuses(): void {
		$this->brokerThrows = new RuntimeException('credential cred-1 holding the-key is not admitted for integriq');

		$response = $this->controller(authorization: 'Token the-key', type: 'aaa-published')->objects();

		$this->assertSame(401, $response->getStatus());
		$this->assertNotSame([], $this->warnings, 'Control: the refusal must be logged at all, or the assertion below proves nothing.');
		foreach ($this->warnings as $warning) {
			$this->assertStringNotContainsString('the-key', $warning);
			$this->assertStringNotContainsString('cred-1', $warning);
		}

		$this->assertStringNotContainsString('the-key', (string)json_encode($response->getData()));
	}//end testNoKeyMaterialReachesTheLogWhenTheBrokerRefuses()

	/**
	 * Every routed facade method is throttled.
	 *
	 * The routes are public, so Nextcloud's anonymous rate limit is the
	 * throttle (ADR-082); it counts per client address, not per token.
	 *
	 * @return void
	 */
	public function testEveryRouteIsThrottled(): void {
		$routes = require __DIR__ . '/../../../../appinfo/routes.php';
		$methods = [];
		foreach ($routes['routes'] as $route) {
			if (str_starts_with((string)$route['name'], 'objectenApi#') === true) {
				$methods[] = substr((string)$route['name'], strlen('objectenApi#'));
			}
		}

		$this->assertCount(10, $methods);
		foreach ($methods as $method) {
			$attributes = (new \ReflectionMethod(ObjectenApiController::class, $method))->getAttributes(AnonRateLimit::class);
			$this->assertCount(1, $attributes, $method . ' is not throttled.');
			$this->assertLessThanOrEqual(600, $attributes[0]->newInstance()->getLimit(), $method);
		}
	}//end testEveryRouteIsThrottled()

	/**
	 * The controller, built from the registered factories.
	 *
	 * @param string               $authorization The Authorization header.
	 * @param string               $type          The `type` parameter.
	 * @param array<string, mixed> $params        Further parameters.
	 *
	 * @return ObjectenApiController The controller.
	 */
	private function controller(string $authorization, string $type = '', array $params = []): ObjectenApiController {
		$container = $this->container();

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($authorization);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($type, $params): mixed {
				if ($key === 'type') {
					return $type;
				}

				return ($params[$key] ?? $default);
			}
		);
		$request->method('getServerHost')->willReturn('example.nl');

		return new ObjectenApiController(
			request: $request,
			tokens: $container->get(ObjectenTokenService::class),
			types: $container->get(ObjecttypeEndpointHandler::class),
			objects: $container->get(ObjectEndpointHandler::class),
			writes: $container->get(ObjectWriteHandler::class),
		);
	}//end controller()

	/**
	 * A container answering the registered factories and a fake OpenRegister.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$test = $this;

		return new class ($test) implements ContainerInterface {

			/**
			 * Constructor.
			 *
			 * @param ObjectenWiringTest $test The test.
			 */
			public function __construct(private readonly ObjectenWiringTest $test) {
			}

			/**
			 * Resolve one service.
			 *
			 * @param string $id The service.
			 *
			 * @return mixed The service.
			 */
			public function get(string $id): mixed {
				return $this->test->resolve(id: $id, container: $this);
			}

			/**
			 * Whether the service resolves.
			 *
			 * @param string $id The service.
			 *
			 * @return bool Always true.
			 */
			public function has(string $id): bool {
				return true;
			}
		};
	}//end container()

	/**
	 * Resolve one service for the container.
	 *
	 * @param string             $id        The service.
	 * @param ContainerInterface $container The container.
	 *
	 * @return mixed The service.
	 */
	public function resolve(string $id, ContainerInterface $container): mixed {
		if (array_key_exists($id, $this->built) === true) {
			return $this->built[$id];
		}

		$service = match ($id) {
			OpenRegisterObjectenGateway::class => new OpenRegisterObjectenGateway(
				access: $container->get(ObjectenOpenRegisterAccess::class),
				logger: $this->logger()
			),
			ObjectenOpenRegisterAccess::class => new ObjectenOpenRegisterAccess(
				container: $container,
				userManager: $this->userManager(),
				userSession: $this->userSession(),
				logger: $this->logger()
			),
			ObjectenOpenRegisterAccess::OBJECT_SERVICE => $this->objectService(),
			ObjectenOpenRegisterAccess::BROKER => $this->broker(),
			EventService::class => $this->eventService(),
			default => ($this->factories[$id])($container),
		};

		$this->built[$id] = $service;

		return $service;
	}//end resolve()

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return $logger;
	}//end logger()

	/**
	 * A user manager knowing one user.
	 *
	 * @return IUserManager The manager.
	 */
	private function userManager(): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'svc-meldingen') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			}
		);

		return $manager;
	}//end userManager()

	/**
	 * A session whose user the gateway sets and restores.
	 *
	 * @return IUserSession The session.
	 */
	private function userSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);

		return $session;
	}//end userSession()

	/**
	 * OpenRegister's object service over one declaration, one token and one melding.
	 *
	 * @return ObjectService The service.
	 */
	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				$this->readAs[$schema] = $this->sessionUser?->getUID();
				if ($this->declarations !== null && array_key_exists($schema, $this->declarations) === true) {
					return ['results' => $this->declarations[$schema]];
				}

				return match ($schema) {
					ObjectenOpenRegisterAccess::OBJECTTYPE_SCHEMA => ['results' => [[
						'@self' => ['id' => 'config-1'],
						'publishedUuid' => 'aaa-published',
						'name' => 'melding',
						'register' => 'meldingen',
						'schema' => 'melding',
					]]],
					ObjectenOpenRegisterAccess::TOKEN_SCHEMA => ['results' => [[
						'@self' => ['id' => 'token-1'],
						'name' => 'Leverancier meldingen',
						'credential' => 'cred-1',
						'principal' => 'svc-meldingen',
						'permissions' => ['aaa-published' => ObjectenTokenService::READ_WRITE],
					]]],
					'melding' => ['results' => [[
						'@self' => ['id' => 'm-1', 'created' => '2026-02-01T09:00:00+01:00'],
						'straatnaam' => 'Kerkstraat',
					]]],
					default => ['results' => []],
				};
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (...$arguments): ObjectEntity {
				$this->saved[] = [
					'register' => $arguments[1],
					'schema' => $arguments[2],
					'as' => $this->sessionUser?->getUID(),
				];

				$entity = new ObjectEntity();
				$entity->setUuid('m-2');
				$entity->setObject(['@self' => ['id' => 'm-2'], 'straatnaam' => 'Dorpsstraat']);

				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A broker resolving one reference.
	 *
	 * @return CredentialBrokerService The broker.
	 */
	private function broker(): CredentialBrokerService {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturnCallback(
			function (string $reference): ?string {
				if ($this->brokerThrows !== null) {
					throw $this->brokerThrows;
				}

				return ($reference === 'cred-1' ? 'the-key' : null);
			}
		);

		return $broker;
	}//end broker()

	/**
	 * An event service recording what goes out.
	 *
	 * @return EventService The service.
	 */
	private function eventService(): EventService {
		$events = $this->createMock(EventService::class);
		$events->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data): array {
				$this->announced[] = ['type' => $type, 'subject' => $subject];

				return [];
			}
		);

		return $events;
	}//end eventService()
}//end class
