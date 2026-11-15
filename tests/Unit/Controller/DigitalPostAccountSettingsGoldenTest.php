<?php

/**
 * Golden recording of what setConfig() answers and does for every account
 * choice: the status and body, and every account, group and save call in order.
 * The translator echoes its text and parameters, so a changed string shows in
 * the diff. Set RECORD_GOLDEN=1 to rewrite the fixture.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DigitalPostAccountSettingsController;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccountInterface;
use OCA\Integriq\Service\Intake\IntakeGroupsInterface;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every allowed and refused account choice of the digital post setting.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostAccountSettingsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/digital-post-account-settings.json';

	/**
	 * Every call the controller made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The account choices: the request's userId and the world it meets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function scenarios(): array {
		$fine = ['account' => 'ok', 'member' => false, 'missing' => [], 'admin' => false, 'saveFails' => false];
		return [
			'new account, no connection yet'      => ['userId' => 'dp', 'consumer' => null] + $fine,
			'padded uid is trimmed'               => ['userId' => '  dp  ', 'consumer' => null] + $fine,
			'cleared, previous withdrawn'         => ['userId' => '', 'consumer' => ['userId' => 'old', 'name' => 'Mine']] + $fine,
			'cleared, nothing before'             => ['userId' => '', 'consumer' => null] + $fine,
			'same account again'                  => ['userId' => 'dp', 'consumer' => ['userId' => 'dp']] + $fine,
			'changed account'                     => ['userId' => 'dp', 'consumer' => ['userId' => 'old']] + $fine,
			'two connections'                     => ['userId' => 'dp', 'consumer' => 'ambiguous'] + $fine,
			'unknown account'                     => ['userId' => 'dp', 'consumer' => null, 'account' => 'unknown'] + $fine,
			'disabled account'                    => ['userId' => 'dp', 'consumer' => null, 'account' => 'disabled'] + $fine,
			'lacks rights, new member withdrawn'  => ['userId' => 'dp', 'consumer' => null, 'missing' => ['create', 'update']] + $fine,
			'lacks rights, old member stays'      => ['userId' => 'dp', 'consumer' => null, 'missing' => ['create'], 'member' => true] + $fine,
			'rights unchecked'                    => ['userId' => 'dp', 'consumer' => null, 'missing' => null] + $fine,
			'administrator'                       => ['userId' => 'dp', 'consumer' => null, 'admin' => true] + $fine,
			'unchecked administrator'             => ['userId' => 'dp', 'consumer' => null, 'missing' => null, 'admin' => true] + $fine,
			'save fails, previous kept'           => ['userId' => 'dp', 'consumer' => ['userId' => 'old'], 'saveFails' => true] + $fine,
		];

	}//end scenarios()

	/**
	 * Each scenario answers and calls exactly what the recording holds.
	 *
	 * @return void
	 */
	public function testSetConfigMatchesTheRecording(): void {
		$found = [];
		foreach (self::scenarios() as $name => $scenario) {
			$this->calls = [];
			$response = $this->controller(scenario: $scenario)->setConfig();
			$found[$name] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}

	}//end testSetConfigMatchesTheRecording()

	/**
	 * The controller over mocks that log every call.
	 *
	 * @param array<string,mixed> $scenario The world.
	 *
	 * @return DigitalPostAccountSettingsController
	 */
	private function controller(array $scenario): DigitalPostAccountSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'userId' ? $scenario['userId'] : $default)
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $text . ' ' . json_encode($parameters));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(function (string $uid) use ($scenario): bool {
			$this->calls[] = 'isAdmin ' . $uid;
			return $scenario['admin'];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context): void {
			$this->calls[] = 'log ' . $message . ' ' . json_encode($context);
		});

		return new DigitalPostAccountSettingsController(
			request: $request,
			account: $this->account(scenario: $scenario),
			consumers: $this->consumers(scenario: $scenario),
			groups: $this->intakeGroups(scenario: $scenario),
			groupManager: $groupManager,
			l: $l,
			logger: $logger
		);

	}//end controller()

	/**
	 * The digital post account over the scenario.
	 *
	 * @param array<string,mixed> $scenario The world.
	 *
	 * @return DigitalPostAccountInterface
	 */
	private function account(array $scenario): DigitalPostAccountInterface {
		$account = $this->createMock(DigitalPostAccountInterface::class);
		$account->method('findConsumer')->willReturnCallback(function () use ($scenario): ?ObjectEntity {
			$this->calls[] = 'findConsumer';
			if ($scenario['consumer'] === 'ambiguous') {
				throw new DsoConnectionUnavailableException(reason: DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION, message: 'two');
			}

			if ($scenario['consumer'] === null) {
				return null;
			}

			$entity = new ObjectEntity();
			$entity->setObject($scenario['consumer']);
			$entity->setUuid('consumer-1');
			return $entity;
		});
		$account->method('account')->willReturnCallback(function (string $uid) use ($scenario): IUser {
			$this->calls[] = 'account ' . $uid;
			$reasons = ['unknown' => DsoConnectionUnavailableException::ACCOUNT_UNKNOWN, 'disabled' => DsoConnectionUnavailableException::ACCOUNT_DISABLED];
			if (isset($reasons[$scenario['account']]) === true) {
				throw new DsoConnectionUnavailableException(reason: $reasons[$scenario['account']], message: 'no');
			}

			return $this->createMock(IUser::class);
		});
		$account->method('missingRights')->willReturnCallback(function (string $uid) use ($scenario): ?array {
			$this->calls[] = 'missingRights ' . $uid;
			return $scenario['missing'];
		});
		$account->method('describe')->willReturnCallback(function (): array {
			$this->calls[] = 'describe';
			return ['state' => 'described'];
		});

		return $account;

	}//end account()

	/**
	 * The consumer store over the scenario.
	 *
	 * @param array<string,mixed> $scenario The world.
	 *
	 * @return PortalConnectionInterface
	 */
	private function consumers(array $scenario): PortalConnectionInterface {
		$consumers = $this->createMock(PortalConnectionInterface::class);
		$consumers->method('saveConsumer')->willReturnCallback(function (array $data, ?string $uuid) use ($scenario): ObjectEntity {
			$this->calls[] = 'save ' . json_encode($uuid) . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
			if ($scenario['saveFails'] === true) {
				throw new RuntimeException('store down');
			}

			return new ObjectEntity();
		});

		return $consumers;

	}//end consumers()

	/**
	 * The intake groups over the scenario.
	 *
	 * @param array<string,mixed> $scenario The world.
	 *
	 * @return IntakeGroupsInterface
	 */
	private function intakeGroups(array $scenario): IntakeGroupsInterface {
		$groups = $this->createMock(IntakeGroupsInterface::class);
		$groups->method('isMember')->willReturnCallback(function (string $group, string $uid) use ($scenario): bool {
			$this->calls[] = 'isMember ' . $group . ' ' . $uid;
			return $scenario['member'];
		});
		$groups->method('enrol')->willReturnCallback(function (string $group, string $uid): bool {
			$this->calls[] = 'enrol ' . $group . ' ' . $uid;
			return true;
		});
		$groups->method('withdraw')->willReturnCallback(function (string $group, string $uid): void {
			$this->calls[] = 'withdraw ' . $group . ' ' . $uid;
		});

		return $groups;

	}//end intakeGroups()
}//end class
