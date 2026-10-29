<?php

/**
 * Characterization test for the registrations Application::register() makes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Tests\Helpers\AppContainerInjection;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins every registration register() makes, in order.
 *
 * Listener order decides which listener runs first for the same event, so the
 * sequence is compared as a whole against a recorded fixture.
 */
class ApplicationRegistrationOrderTest extends TestCase {

	use AppContainerInjection;

	/**
	 * The fixture holding the recorded registration sequence.
	 */
	private const FIXTURE = __DIR__ . '/../../fixtures/application-registration-order.json';

	/**
	 * The service factories register() made, keyed by service name.
	 *
	 * @var array<string, \Closure>
	 */
	private array $factories = [];

	/**
	 * Runs register() against recording doubles and returns the calls made.
	 *
	 * @return list<string> One line per registration call.
	 */
	private function recordRegistrations(): array {
		$calls = [];
		$this->factories = [];
		$factories = &$this->factories;
		$record = static function (string $method) use (&$calls, &$factories): \Closure {
			return static function (...$args) use (&$calls, &$factories, $method): void {
				if ($method === 'context.registerService' && $args[1] instanceof \Closure) {
					$factories[$args[0]] = $args[1];
				}

				$parts = [];
				foreach ($args as $arg) {
					if (is_string($arg) === true) {
						$parts[] = $arg;
					} elseif ($arg instanceof \Closure) {
						$parts[] = 'closure';
					} else {
						$parts[] = get_debug_type($arg);
					}
				}

				$calls[] = $method . '(' . implode(', ', $parts) . ')';
			};
		};

		$context = $this->createMock(IRegistrationContext::class);
		foreach ((new ReflectionClass(IRegistrationContext::class))->getMethods() as $method) {
			$context->method($method->getName())->willReturnCallback($record('context.' . $method->getName()));
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		foreach (['addServiceListener', 'addListener'] as $method) {
			$dispatcher->method($method)->willReturnCallback($record('dispatcher.' . $method));
		}

		$container = $this->createMock($this->appContainerType());
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($dispatcher) {
				if ($id === IEventDispatcher::class) {
					return $dispatcher;
				}

				throw new \RuntimeException('unexpected container id: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$this->injectAppContainer($app, $container);
		$app->register($context);

		return $calls;
	}//end recordRegistrations()

	/**
	 * Test that register() makes the recorded registrations in the recorded order.
	 *
	 * @return void
	 */
	public function testRegistrationsMatchTheRecordedSequence(): void {
		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);

		$this->assertSame($expected, $this->recordRegistrations());
	}//end testRegistrationsMatchTheRecordedSequence()

	/**
	 * The PDOK clients and the class each resolves to, live then mock.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function pdokClientProvider(): array {
		return [
			'wms'       => ['OCA\\Integriq\\Adapters\\Pdok\\PdokWmsClient', 'OCA\\Integriq\\Adapters\\Pdok\\PdokWmsClientHttp', 'OCA\\Integriq\\Adapters\\Pdok\\PdokWmsClientMock'],
			'wfs'       => ['OCA\\Integriq\\Adapters\\Pdok\\PdokWfsClient', 'OCA\\Integriq\\Adapters\\Pdok\\PdokWfsClientHttp', 'OCA\\Integriq\\Adapters\\Pdok\\PdokWfsClientMock'],
			'geocoding' => ['OCA\\Integriq\\Adapters\\Pdok\\PdokGeocodingClient', 'OCA\\Integriq\\Adapters\\Pdok\\PdokGeocodingClientHttp', 'OCA\\Integriq\\Adapters\\Pdok\\PdokGeocodingClientMock'],
		];
	}//end pdokClientProvider()

	/**
	 * Test that each PDOK client resolves to its live class only when the flag is on.
	 *
	 * @param string $client The abstract client.
	 * @param string $http   The live class.
	 * @param string $mock   The mock class.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('pdokClientProvider')]
	public function testPdokClientFollowsTheFeatureFlag(string $client, string $http, string $mock): void {
		$this->recordRegistrations();
		$factory = $this->factories[$client];

		$resolve = function (string $flag) use ($factory): string {
			$config = $this->createMock(\OCP\IAppConfig::class);
			$config->method('getValueString')->willReturn($flag);
			$container = $this->createMock(\Psr\Container\ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static fn (string $id) => $id === 'OCP\\IAppConfig' ? $config : $id
			);

			return $factory($container);
		};

		$this->assertSame($http, $resolve('1'));
		$this->assertSame($http, $resolve('TRUE'));
		$this->assertSame($mock, $resolve('0'));
	}//end testPdokClientFollowsTheFeatureFlag()
}//end class
