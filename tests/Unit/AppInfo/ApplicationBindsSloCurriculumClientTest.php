<?php

/**
 * `Application::register()` binds the SLO curriculum client to the right flavour.
 *
 * Runs `register()` against a recording context, then calls the recorded
 * factory for `SloCurriculumClient` with the dormant flag off and on. Proves
 * the binding is executed, not only written down.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientHttp;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Tests\Helpers\AppContainerInjection;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The SLO client resolves to the mock unless the flag is on.
 */
class ApplicationBindsSloCurriculumClientTest extends TestCase {
	use AppContainerInjection;

	/**
	 * Run `register()` and return the factory recorded for the SLO client.
	 *
	 * @return callable The factory.
	 */
	private function recordedFactory(): callable {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		$container = $this->createMock($this->appContainerType());
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === IEventDispatcher::class) {
					return $this->createMock(IEventDispatcher::class);
				}

				throw new RuntimeException('unexpected container id: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$this->injectAppContainer($app, $container);
		$app->register($context);

		$this->assertArrayHasKey(SloCurriculumClient::class, $factories);
		return $factories[SloCurriculumClient::class];
	}//end recordedFactory()

	/**
	 * A container answering the flag and both flavours.
	 *
	 * @param string $flag The flag value.
	 *
	 * @return object The container double.
	 */
	private function container(string $flag): object {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($flag);
		$mock = new SloCurriculumClientMock();
		$http = new SloCurriculumClientHttp($this->createMock(CallService::class), $this->createMock(OrObjectService::class));

		return new class($config, $mock, $http) {
			/**
			 * @param IAppConfig $config Config.
			 * @param SloCurriculumClientMock $mock Mock flavour.
			 * @param SloCurriculumClientHttp $http Live flavour.
			 */
			public function __construct(private IAppConfig $config, private SloCurriculumClientMock $mock, private SloCurriculumClientHttp $http) {
			}

			/**
			 * @param string $id Service id.
			 *
			 * @return object The service.
			 */
			public function get(string $id): object {
				return match ($id) {
					'OCP\IAppConfig' => $this->config,
					SloCurriculumClientMock::class => $this->mock,
					SloCurriculumClientHttp::class => $this->http,
				};
			}
		};
	}//end container()

	/**
	 * @return void
	 */
	public function testTheMockIsTheDefault(): void {
		$client = ($this->recordedFactory())($this->container('0'));

		$this->assertInstanceOf(SloCurriculumClientMock::class, $client);
	}//end testTheMockIsTheDefault()

	/**
	 * @return void
	 */
	public function testTheFlagSelectsTheLiveClient(): void {
		$factory = $this->recordedFactory();

		$this->assertInstanceOf(SloCurriculumClientHttp::class, $factory($this->container('1')));
		$this->assertInstanceOf(SloCurriculumClientHttp::class, $factory($this->container('TRUE')));
	}//end testTheFlagSelectsTheLiveClient()
}//end class
