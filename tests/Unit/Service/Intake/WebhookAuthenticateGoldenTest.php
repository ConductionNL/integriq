<?php

/**
 * Characterisation test for WebhookConnection::authenticate.
 *
 * Pins every allowed and refused webhook delivery: the signature check it is
 * asked to make, and each refusal reason before an identity is answered.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Intake;

use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Intake\WebhookConnection;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden record of the webhook authentication.
 */
class WebhookAuthenticateGoldenTest extends TestCase {

	/**
	 * The recorded deliveries.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$profile = new WebhookProfile(authorizationType: 'openformulieren', channel: 'openformulieren', label: 'Open Formulieren', schema: 'openformulieren_submission');
		$consumer = static function (string $uuid, array $data): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entity->setObject($data);
			return $entity;
		};
		$trust = ['header' => 'X-Sig', 'scheme' => 'hmac-sha256-hex', 'secret' => 's3cr3t', 'toleranceSeconds' => '60'];
		$cases = [
			'allowed' => [[$consumer('c-1', ['userId' => 'svc', 'authorizationConfiguration' => $trust])], true, 'svc', []],
			'defaults' => [[$consumer('c-2', ['userId' => 'svc', 'authorizationConfiguration' => 'x'])], true, 'svc', []],
			'no consumer' => [[], true, 'svc', []],
			'two consumers' => [[$consumer('c-1', []), $consumer('c-2', [])], true, 'svc', []],
			'bad signature' => [[$consumer('c-1', ['userId' => 'svc', 'authorizationConfiguration' => $trust])], false, 'svc', []],
			'no account' => [[$consumer('c-1', ['authorizationConfiguration' => $trust])], true, 'svc', []],
			'unknown account' => [[$consumer('c-1', ['userId' => 'ghost', 'authorizationConfiguration' => $trust])], true, 'svc', []],
			'disabled account' => [[$consumer('c-1', ['userId' => 'off', 'authorizationConfiguration' => $trust])], true, 'svc', []],
			'rights unverifiable' => [[$consumer('c-1', ['userId' => 'svc', 'authorizationConfiguration' => $trust])], true, 'svc', null],
			'rights missing' => [[$consumer('c-1', ['userId' => 'svc', 'authorizationConfiguration' => $trust])], true, 'svc', ['update']],
		];

		$record = [];
		foreach ($cases as $name => [$consumers, $signatureOk, , $missing]) {
			$calls = [];
			$connections = $this->createMock(DsoConnection::class);
			$connections->method('findConsumers')->willReturn($consumers);
			$signatures = $this->createMock(WebhookSignatureService::class);
			$signatures->method('verify')->willReturnCallback(
				static function (string $rawBody, string $headerValue, array $config) use (&$calls, $signatureOk): bool {
					$calls[] = ['verify' => [$rawBody, $headerValue, $config]];
					return $signatureOk;
				}
			);
			$users = $this->createMock(IUserManager::class);
			$users->method('get')->willReturnCallback(
				function (string $uid): ?IUser {
					if ($uid === 'ghost') {
						return null;
					}

					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$user->method('isEnabled')->willReturn($uid !== 'off');
					return $user;
				}
			);
			$rights = $this->createMock(DsoAccountRights::class);
			$rights->method('missing')->willReturnCallback(
				static function (string $userId, array $actions, string $schema) use (&$calls, $missing): ?array {
					$calls[] = ['missing' => [$userId, $actions, $schema]];
					return $missing;
				}
			);
			$headers = [];
			$headerOf = static function (string $name) use (&$headers): string {
				$headers[] = $name;
				return 'sig-for-' . $name;
			};

			try {
				$identity = (new WebhookConnection($connections, $signatures, $users, $rights))->authenticate($profile, '{"a":1}', $headerOf);
				$outcome = ['identity' => [$identity->account->getUID(), $identity->consumerUuid]];
			} catch (Throwable $e) {
				$outcome = ['refused' => [$e::class, $e->getMessage(), (method_exists($e, 'getReason') === true ? $e->getReason() : null), (method_exists($e, 'getChannel') === true ? $e->getChannel() : null)]];
			}

			$record[$name] = $outcome + ['headers' => $headers, 'calls' => $calls];
		}

		$path = __DIR__ . '/../../../fixtures/intake/webhook-authenticate.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
