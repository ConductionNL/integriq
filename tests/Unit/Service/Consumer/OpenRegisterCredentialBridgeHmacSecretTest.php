<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
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
 * A JWT consumer with an HMAC secret below the algorithm's hash output is refused, as before gate 23.
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
final class OpenRegisterCredentialBridgeHmacSecretTest extends TestCase {

	private ORObjectService $orObjectService;
	private IUserManager $userManager;
	private IUserSession $userSession;


	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->orObjectService = $this->createMock(ORObjectService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()


	/**
	 * The bridge over a real OpenRegister AuthorizationService and the doubles.
	 *
	 * @return OpenRegisterCredentialBridge
	 */
	private function bridge(): OpenRegisterCredentialBridge {
		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return OpenRegisterCredentials::bridge(
			$this->userManager,
			$this->userSession,
			$this->orObjectService,
			$this->createMock(IGroupManager::class),
			$cacheFactory,
			$this->createMock(IRequest::class)
		);
	}//end bridge()


	/**
	 * A JWT consumer named `zaaksysteem` verifying with the given HMAC secret.
	 *
	 * @param string $algorithm The HS algorithm.
	 * @param string $secret    The stored secret.
	 *
	 * @return void
	 */
	private function consumerWithSecret(string $algorithm, string $secret, string $name = 'zaaksysteem'): void {
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
		$this->orObjectService->method('findAll')->willReturn(['results' => [$entity]]);
	}//end consumerWithSecret()


	/**
	 * A compact JWS signed with hash_hmac, which takes any secret length — exactly what the guard is for.
	 *
	 * @param string $algorithm The HS algorithm.
	 * @param string $secret    The signing secret.
	 *
	 * @return string
	 */
	private function tokenSignedWith(string $algorithm, string $secret, string|int $issuer = 'zaaksysteem'): string {
		$encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		$header = $encode((string)json_encode(['alg' => $algorithm, 'typ' => 'JWT']));
		$payload = $encode((string)json_encode(['iss' => $issuer, 'iat' => time(), 'exp' => time() + 600]));
		$hash = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'][$algorithm];

		return $header . '.' . $payload . '.' . $encode(hash_hmac($hash, $header . '.' . $payload, $secret, true));
	}//end tokenSignedWith()


	/**
	 * Secrets below the hash output, the empty one included, are refused before anybody acts as the consumer's user.
	 *
	 * @return void
	 */
	public function testAShortOrEmptyHmacSecretIsRefused(): void {
		foreach ([['HS256', ''], ['HS256', 'short'], ['HS256', str_repeat('k', 31)], ['HS384', str_repeat('k', 47)], ['HS512', str_repeat('k', 63)]] as [$algorithm, $secret]) {
			$this->setUp();
			$this->consumerWithSecret($algorithm, $secret);
			$this->userSession->expects($this->never())->method('setVolatileActiveUser');

			try {
				$this->bridge()->authorizeJwt('Bearer ' . $this->tokenSignedWith($algorithm, $secret));
				$this->fail($algorithm . ' with a ' . strlen($secret) . '-byte secret must be refused');
			} catch (AuthenticationException $e) {
				$this->assertSame('The token could not be validated', $e->getMessage(), $algorithm);
				$this->assertStringContainsString('HMAC secret is shorter', (string)json_encode($e->getDetails()), $algorithm);
			}
		}
	}//end testAShortOrEmptyHmacSecretIsRefused()


	/**
	 * A secret of the hash output's size is handed to OpenRegister and accepted.
	 *
	 * @return void
	 */
	public function testASecretOfTheHashOutputSizeIsAccepted(): void {
		foreach ([['HS256', 32], ['HS384', 48], ['HS512', 64]] as [$algorithm, $bytes]) {
			$this->setUp();
			$secret = str_repeat('k', $bytes);
			$this->consumerWithSecret($algorithm, $secret);
			$user = $this->createMock(IUser::class);
			$this->userManager->method('get')->with('svc-zaaksysteem')->willReturn($user);
			$this->userSession->expects($this->once())->method('setVolatileActiveUser')->with($user);

			$bridge = $this->bridge();
			$bridge->authorizeJwt('Bearer ' . $this->tokenSignedWith($algorithm, $secret));

			$this->assertNotNull($bridge->getResolvedConsumer(), $algorithm);
		}
	}//end testASecretOfTheHashOutputSizeIsAccepted()


	/**
	 * A numeric `iss` is read with the same string cast OpenRegister uses, so it cannot slip past the guard.
	 *
	 * @return void
	 */
	public function testANumericIssuerIsGuardedToo(): void {
		$this->consumerWithSecret('HS256', 'short', name: '12345');
		$this->userSession->expects($this->never())->method('setVolatileActiveUser');

		try {
			$this->bridge()->authorizeJwt('Bearer ' . $this->tokenSignedWith('HS256', 'short', issuer: 12345));
			$this->fail('an integer iss naming an HS256 consumer with a short secret must be refused');
		} catch (AuthenticationException $e) {
			$this->assertStringContainsString('HMAC secret is shorter', (string)json_encode($e->getDetails()));
		}
	}//end testANumericIssuerIsGuardedToo()


	/**
	 * An unknown issuer is left to OpenRegister, which refuses it as not found.
	 *
	 * @return void
	 */
	public function testAnUnknownIssuerIsStillRefusedByOpenRegister(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => []]);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('issuer was not found');
		$this->bridge()->authorizeJwt('Bearer ' . $this->tokenSignedWith('HS256', 'short'));
	}//end testAnUnknownIssuerIsStillRefusedByOpenRegister()
}//end class
