<?php

/**
 * Golden recording of the OpenRegister organisation bridge: what each method
 * answers and logs with OpenRegister absent, unreachable, answering, refusing
 * or failing. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of the organisation bridge.
 */
class OrganisationBridgeServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/organisation-bridge-service.json';

	/**
	 * Every log call, in order.
	 *
	 * @var list<array>
	 */
	private array $logged = [];

	/**
	 * Every method in every world matches the recording.
	 *
	 * @return void
	 */
	public function testEveryAnswerMatchesTheRecording(): void {
		$worlds = ['not installed', 'not in the container', 'answers', 'refuses', 'fails'];
		$calls = [
			'isOrganisationServiceAvailable' => fn (OrganisationBridgeService $bridge) => $bridge->isOrganisationServiceAvailable(),
			'getUserOrganisationStats' => fn (OrganisationBridgeService $bridge) => $bridge->getUserOrganisationStats(),
			'setActiveOrganisation' => fn (OrganisationBridgeService $bridge) => $bridge->setActiveOrganisation('org-1'),
			'getActiveOrganisation' => fn (OrganisationBridgeService $bridge) => $bridge->getActiveOrganisation(),
			'getUserOrganisations' => fn (OrganisationBridgeService $bridge) => $bridge->getUserOrganisations(),
		];
		$found = [];
		foreach ($worlds as $world) {
			foreach ($calls as $name => $call) {
				$this->logged = [];
				$answer = $call($this->bridge(world: $world));
				$found[$world . ' ' . $name] = ['answer' => $answer, 'logged' => $this->logged];
			}
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}

	}//end testEveryAnswerMatchesTheRecording()

	/**
	 * The bridge in one world.
	 *
	 * @param string $world How OpenRegister behaves.
	 *
	 * @return OrganisationBridgeService
	 */
	private function bridge(string $world): OrganisationBridgeService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn($world === 'not installed' ? ['integriq'] : ['integriq', 'openregister']);
		$container = $this->createMock(ContainerInterface::class);
		if ($world === 'not in the container') {
			$container->method('get')->willThrowException(new class('no such service') extends Exception implements ContainerExceptionInterface {
			});
		} else {
			$container->method('get')->willReturn($this->organisations(world: $world));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		return new OrganisationBridgeService($apps, $container, $logger);

	}//end bridge()

	/**
	 * OpenRegister's organisation service in one world.
	 *
	 * @param string $world Answers, refuses (false and nothing) or fails (throws).
	 *
	 * @return OrganisationService
	 */
	private function organisations(string $world): OrganisationService {
		return new class($world) extends OrganisationService {
			/**
			 * @param string $world The world.
			 */
			public function __construct(private string $world) {
			}

			/**
			 * @return void
			 *
			 * @throws Exception When the world fails.
			 */
			private function maybeFail(): void {
				if ($this->world === 'fails') {
					throw new Exception('database gone');
				}
			}

			/**
			 * @param string $organisationUuid The organisation.
			 *
			 * @return bool
			 */
			public function setActiveOrganisation(string $organisationUuid): bool {
				$this->maybeFail();
				return $this->world === 'answers' && $organisationUuid === 'org-1';
			}

			/**
			 * @return array
			 */
			public function getUserOrganisationStats(): array {
				$this->maybeFail();
				return ['total' => 2, 'active' => 'org-1', 'results' => [['uuid' => 'org-1']]];
			}

			/**
			 * @param array|null $preloadedOrgs Unused.
			 *
			 * @return object|null
			 */
			public function getActiveOrganisation(?array $preloadedOrgs = null): ?object {
				$this->maybeFail();
				if ($this->world === 'refuses') {
					return null;
				}

				return new class implements \JsonSerializable {
					/**
					 * @return array
					 */
					public function jsonSerialize(): array {
						return ['uuid' => 'org-1', 'name' => 'Gemeente'];
					}
				};
			}

			/**
			 * @return array
			 */
			public function getUserOrganisations(): array {
				$this->maybeFail();
				if ($this->world === 'refuses') {
					return [];
				}

				return [$this->getActiveOrganisation(), $this->getActiveOrganisation()];
			}
		};

	}//end organisations()
}//end class
