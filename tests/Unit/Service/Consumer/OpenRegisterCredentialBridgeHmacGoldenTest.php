<?php

/**
 * Characterisation test for the HMAC secret guard of OpenRegisterCredentialBridge.
 *
 * Pins the allowed and refused outcomes of authorizeJwt over every issuer
 * shape, algorithm and secret length the guard decides on.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
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

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Tests\Helpers\OpenRegisterCredentials;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the HMAC secret guard.
 */
class OpenRegisterCredentialBridgeHmacGoldenTest extends TestCase {

	/**
	 * One authorizeJwt outcome.
	 *
	 * @param string $algorithm The consumer's algorithm.
	 * @param string $secret    The consumer's stored secret.
	 * @param mixed  $issuer    The token's iss claim; the string "(absent)" leaves it out.
	 * @param string $name      The consumer's name.
	 *
	 * @return string
	 */
	private function outcome(string $algorithm, string $secret, mixed $issuer, string $name = 'zaaksysteem'): string {
		$objects = $this->createMock(ORObjectService::class);
		$entity = new ObjectEntity();
		$entity->setUuid('consumer-hmac');
		$entity->setObject(
			[
				'name' => $name,
				'authorizationType' => 'jwt',
				'authorizationConfiguration' => ['algorithm' => $algorithm, 'publicKey' => $secret],
				'userId' => 'svc-zaaksysteem',
			]
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($entity, $name): array {
				$wanted = ($config['filters']['name'] ?? ($config['name'] ?? null));
				if ($wanted !== null && (string)$wanted !== $name) {
					return ['results' => []];
				}

				return ['results' => [$entity]];
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$session = $this->createMock(IUserSession::class);
		$activated = false;
		$session->method('setVolatileActiveUser')->willReturnCallback(
			static function () use (&$activated): void {
				$activated = true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$bridge = OpenRegisterCredentials::bridge($users, $session, $objects, $this->createMock(IGroupManager::class), $cacheFactory, $this->createMock(IRequest::class));

		try {
			$bridge->authorizeJwt('Bearer ' . $this->token(algorithm: $algorithm, secret: $secret, issuer: $issuer));
			return 'allowed activated=' . var_export($activated, true);
		} catch (AuthenticationException $e) {
			return 'refused ' . $e->getMessage() . ' ' . json_encode($e->getDetails()) . ' activated=' . var_export($activated, true);
		}
	}//end outcome()

	/**
	 * A token signed with the secret (HS*) or carrying a dummy signature (other algorithms).
	 *
	 * @param string $algorithm The algorithm.
	 * @param string $secret    The secret.
	 * @param mixed  $issuer    The iss claim.
	 *
	 * @return string
	 */
	private function token(string $algorithm, string $secret, mixed $issuer): string {
		$encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		$claims = ['iat' => time(), 'exp' => (time() + 600)];
		if ($issuer !== '(absent)') {
			$claims = ['iss' => $issuer] + $claims;
		}

		$header = $encode((string)json_encode(['alg' => $algorithm, 'typ' => 'JWT']));
		$payload = $encode((string)json_encode($claims));
		$hash = (['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'][$algorithm] ?? 'sha256');

		return $header . '.' . $payload . '.' . $encode(hash_hmac($hash, $header . '.' . $payload, $secret, true));
	}//end token()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$record = [];
		foreach (['HS256' => 32, 'HS384' => 48, 'HS512' => 64] as $algorithm => $bytes) {
			foreach ([0, 5, ($bytes - 1), $bytes] as $length) {
				$record[$algorithm . ' secret ' . $length] = $this->outcome($algorithm, str_repeat('k', $length), 'zaaksysteem');
			}
		}

		$record['RS256 short key'] = $this->outcome('RS256', 'short', 'zaaksysteem');
		$record['RS256 empty key'] = $this->outcome('RS256', '', 'zaaksysteem');
		$record['HS256 secret padded to 32'] = $this->outcome('HS256', ' ' . str_repeat('k', 30) . ' ', 'zaaksysteem');
		$record['unknown issuer'] = $this->outcome('HS256', 'short', 'someone-else');
		$record['numeric issuer'] = $this->outcome('HS256', 'short', 12345, '12345');
		$record['float issuer'] = $this->outcome('HS256', 'short', 1.5, '1.5');
		$record['true issuer'] = $this->outcome('HS256', 'short', true, '1');
		$record['list issuer'] = $this->outcome('HS256', 'short', ['x'], 'Array');
		$record['object issuer'] = $this->outcome('HS256', 'short', ['a' => 1], 'Array');
		$record['empty issuer'] = $this->outcome('HS256', 'short', '', '');
		$record['zero string issuer'] = $this->outcome('HS256', 'short', '0', '0');
		$record['zero issuer'] = $this->outcome('HS256', 'short', 0, '0');
		$record['null issuer'] = $this->outcome('HS256', 'short', null);
		$record['no issuer'] = $this->outcome('HS256', 'short', '(absent)');

		$path = __DIR__ . '/../../../fixtures/consumer/hmac-secret-guard.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
