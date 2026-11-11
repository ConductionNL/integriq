<?php

/**
 * Golden tests for LtiNrpsService::readRoster.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiNrpsService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the roster read: the scope check first, every refusal, the mapper it
 * reads, each member's shape with and without a mapping, and the log lines.
 */
class LtiRosterGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * Read the roster of a deployment with the given data.
	 *
	 * @param array|null      $deploymentData The deployment's object, null for none.
	 * @param \Throwable|null $scopeFailure   What the scope check throws.
	 * @param \Throwable|null $mappingFailure What the mapping throws.
	 *
	 * @return array|string The roster, or the refusal as "status message details".
	 */
	private function read(?array $deploymentData, ?\Throwable $scopeFailure=null, ?\Throwable $mappingFailure=null): array|string {
		$this->seen = [];
		$ags        = $this->createMock(LtiAgsService::class);
		$ags->method('assertScopedToDeployment')->willReturnCallback(
			function (string $accessToken, string $deploymentUuid, string|array $requiredScope) use ($scopeFailure): array {
				$this->seen[] = ['scope', $accessToken, $deploymentUuid, $requiredScope];
				if ($scopeFailure !== null) {
					throw $scopeFailure;
				}

				return [];
			}
		);
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			function (string $uuid) use ($deploymentData): ?ObjectEntity {
				$this->seen[] = ['deployment', $uuid];
				if ($deploymentData === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($deploymentData);
				return $entity;
			}
		);
		$member = new ObjectEntity();
		$member->setUuid('m-1');
		$member->setObject(['name' => 'Jan', 'email' => 'jan@x.nl']);
		$mapper = new class ([$member]) {
			/**
			 * Constructor.
			 *
			 * @param array $objects The objects.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * The page.
			 *
			 * @param array $params The params.
			 *
			 * @return array The results.
			 */
			public function findAllPaginated(array $params=[]): array {
				return ['results' => $this->objects];
			}
		};
		$objects = $this->createMock(ObjectService::class);
		$objects->method('getMapper')->willReturnCallback(
			function (...$arguments) use ($mapper) {
				$this->seen[] = ['mapper', $arguments];
				return $mapper;
			}
		);
		$mappings = $this->createMock(MappingService::class);
		$mappings->method('executeMapping')->willReturnCallback(
			function ($mapping, array $input) use ($mappingFailure): array {
				$this->seen[] = ['mapping', $mapping, array_keys($input)];
				if ($mappingFailure !== null) {
					throw $mappingFailure;
				}

				return ['mapped' => $input['name'] ?? null];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->seen[] = [$level, $message, $context];
				}
			);
		}

		try {
			return (new LtiNrpsService($resolver, $ags, $objects, $mappings, $logger))->readRoster('token-1', 'dep-1');
		} catch (LtiValidationException $exception) {
			return $exception->getHttpStatus().' '.$exception->getMessage();
		}

	}//end read()

	/**
	 * Every refusal, and that nothing after it is read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame('401 denied', $this->read(['deploymentId' => 'D'], new LtiValidationException(message: 'denied', details: [], httpStatus: 401)));
		$this->assertSame([['scope', 'token-1', 'dep-1', LtiAgsService::SCOPE_NRPS]], $this->seen);

		$this->assertSame('400 Unknown lti_deployment', $this->read(null));
		$cases = [
			[[], 'Deployment has no rosterSource configured'],
			[['rosterSource' => 'x'], 'Deployment has no rosterSource configured'],
			[['rosterSource' => ['targetType' => 'source', 'targetId' => '1/2']], 'Deployment has no rosterSource configured'],
			[['rosterSource' => ['targetType' => 'register/schema', 'targetId' => '']], 'Deployment has no rosterSource configured'],
			[['rosterSource' => ['targetType' => 'register/schema', 'targetId' => '1/2/3']], 'rosterSource.targetId is malformed'],
			[['rosterSource' => ['targetType' => 'register/schema', 'targetId' => '12']], 'rosterSource.targetId is malformed'],
		];
		foreach ($cases as [$data, $message]) {
			$this->assertSame('400 '.$message, $this->read($data));
			$this->assertSame(['scope', 'deployment'], array_column($this->seen, 0));
		}

	}//end testRefusals()

	/**
	 * The roster with the default member shape and with a mapping, also when the mapping fails.
	 *
	 * @return void
	 */
	public function testRoster(): void {
		$data = ['deploymentId' => 'D-1', 'rosterSource' => ['targetType' => 'register/schema', 'targetId' => '4/9']];
		$roster = $this->read($data);
		$this->assertSame('D-1', $roster['id']);
		$this->assertSame(['id' => 'dep-1'], $roster['context']);
		$this->assertCount(1, $roster['members']);
		$this->assertSame(['name' => 'Jan', 'email' => 'jan@x.nl', 'roles' => []], array_intersect_key($roster['members'][0], ['name' => 1, 'email' => 1, 'roles' => 1]));
		$this->assertSame(['scope', 'deployment', 'mapper', 'info'], array_column($this->seen, 0));
		$this->assertSame([4, 9], $this->seen[2][1]);
		$this->assertSame(['info', 'LtiNrpsService: roster served', ['deploymentUuid' => 'dep-1', 'memberCount' => 1]], $this->seen[3]);

		$roster = $this->read($data + ['mappingId' => 'map-1']);
		$this->assertSame([['mapped' => 'Jan']], $roster['members']);

		$roster = $this->read($data + ['mappingId' => 'map-1'], null, new RuntimeException('bad mapping'));
		$this->assertSame(['mapping', 'warning', 'info'], array_slice(array_column($this->seen, 0), 3));
		$this->assertSame(['warning', 'LtiNrpsService: mapping execution failed, falling back to default shape', ['error' => 'bad mapping']], $this->seen[4]);
		$this->assertSame(['name' => 'Jan', 'email' => 'jan@x.nl', 'roles' => []], array_intersect_key($roster['members'][0], ['name' => 1, 'email' => 1, 'roles' => 1]));

	}//end testRoster()
}//end class
