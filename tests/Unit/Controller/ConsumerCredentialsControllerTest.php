<?php

/**
 * Unit tests for ConsumerCredentialsController: admin only, a key shown once, revoke one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-consumer-credentials/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ConsumerCredentialsController;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Tests\Unit\Service\Auth\ClientCertificateFixtures;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Controller\ConsumerCredentialsController
 * @uses   \OCA\Integriq\Service\Consumer\ConsumerCredentials
 * @uses   \OCA\Integriq\Service\Helper\RandomUuid
 */
final class ConsumerCredentialsControllerTest extends TestCase {

	/**
	 * Stored consumers by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * A client certificate is pinned by its fingerprint; the response carries no key.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 *
	 * @return void
	 */
	public function testAClientCertificateIsPinnedByFingerprint(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];
		$this->params = ['label' => 'Zaaksysteem', 'type' => 'clientCertificate', 'certificate' => $pem];

		$created = $this->controller()->create('c-1');

		$this->assertSame(201, $created->getStatus());
		$this->assertArrayNotHasKey('key', $created->getData());
		$credential = $created->getData()['credential'];
		$this->assertSame('clientCertificate', $credential['type']);
		$this->assertSame(strtolower((string)openssl_x509_fingerprint($pem, 'sha256')), $credential['fingerprint']);
		$this->assertSame(ClientCertificateFixtures::OIN, $credential['oin']);
		$this->assertSame($credential, end($this->stored['c-1'][ConsumerCredentials::FIELD]));
	}//end testAClientCertificateIsPinnedByFingerprint()

	/**
	 * An issuing CA with a subject pattern is pinned; neither, or garbage, is a 400 that stores nothing.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 *
	 * @return void
	 */
	public function testAnIssuerPinAndAnIncompleteOne(): void {
		$this->params = ['label' => 'Gemeente', 'type' => 'clientCertificate', 'issuerCertificate' => ClientCertificateFixtures::pems()['ca'], 'subjectPattern' => '*/serialNumber=' . ClientCertificateFixtures::OIN];
		$created = $this->controller()->create('c-1');
		$this->assertSame(201, $created->getStatus());
		$this->assertSame('*/serialNumber=' . ClientCertificateFixtures::OIN, $created->getData()['credential']['subjectPattern']);
		$this->assertCount(3, $this->stored['c-1'][ConsumerCredentials::FIELD]);

		foreach ([['certificate' => 'garbage'], ['issuerCertificate' => ClientCertificateFixtures::pems()['ca']], []] as $extra) {
			$this->params = ['label' => 'x', 'type' => 'clientCertificate'] + $extra;
			$response = $this->controller()->create('c-1');
			$this->assertSame(400, $response->getStatus());
			$this->assertCount(3, $this->stored['c-1'][ConsumerCredentials::FIELD]);
		}
	}//end testAnIssuerPinAndAnIncompleteOne()

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private bool $saveFails = false;

	private ConsumerCredentials $credentials;

	protected function setUp(): void {
		$config = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => ($config[$k] ?? $d));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $a, string $k, string $v) use (&$config): bool {
				$config[$k] = $v;
				return true;
			}
		);
		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length) use (&$counter): string {
				$counter++;
				return substr(str_repeat('K' . $counter . 'abcdefgh', 10), 0, $length);
			}
		);
		$this->credentials = new ConsumerCredentials($appConfig, $random);
		$this->stored = [
			'c-1' => [
				'name' => 'partner',
				'authorizationConfiguration' => ['apiKey' => null],
				ConsumerCredentials::FIELD => [
					['id' => 'cred-old', 'label' => 'old', 'type' => 'apiKey', 'keyPrefix' => 'oldkey', 'keyHash' => 'h-old', 'expiresAt' => '2020-01-01T00:00:00+00:00'],
					['id' => 'cred-new', 'label' => 'new', 'type' => 'apiKey', 'keyPrefix' => 'newkey', 'keyHash' => 'h-new', 'expiresAt' => null],
				],
			],
		];
	}

	public function testAGeneratedKeyIsInTheCreateResponseAndNeverInTheList(): void {
		$this->params = ['label' => '  Partner 2026  ', 'expiresAt' => '2027-01-01T00:00:00+00:00'];
		$controller = $this->controller();

		$created = $controller->create('c-1');

		$this->assertSame(201, $created->getStatus());
		$key = $created->getData()['key'];
		$this->assertSame(ConsumerCredentials::KEY_LENGTH, strlen($key));
		$this->assertSame('Partner 2026', $created->getData()['credential']['label']);
		$this->assertSame(substr($key, 0, 6), $created->getData()['credential']['keyPrefix']);
		$this->assertArrayNotHasKey('keyHash', $created->getData()['credential']);

		$stored = $this->stored['c-1'][ConsumerCredentials::FIELD];
		$this->assertCount(3, $stored);
		$this->assertSame($this->credentials->hashKey(key: $key), $stored[2]['keyHash']);
		$this->assertStringNotContainsString($key, json_encode($stored));

		$listed = $controller->index('c-1')->getData()['results'];
		$this->assertCount(3, $listed);
		$this->assertStringNotContainsString($key, json_encode($listed));
		$this->assertStringNotContainsString('keyHash', json_encode($listed));
		$this->assertSame([true, false, false], array_column($listed, 'expired'));
	}

	public function testRevokingOneKeyLeavesTheOthers(): void {
		$response = $this->controller()->destroy('c-1', 'cred-old');

		$this->assertSame(['revoked' => 'cred-old'], $response->getData());
		$this->assertSame(['cred-new'], array_column($this->stored['c-1'][ConsumerCredentials::FIELD], 'id'));
	}

	public function testRevokingAnUnknownCredentialIsNotFoundAndChangesNothing(): void {
		$before = $this->stored;

		$this->assertSame(404, $this->controller()->destroy('c-1', 'nope')->getStatus());
		$this->assertSame($before, $this->stored);
	}

	public function testAnUnknownConsumerIsNotFound(): void {
		$this->params = ['label' => 'x'];
		$controller = $this->controller();

		$this->assertSame(404, $controller->index('missing')->getStatus());
		$this->assertSame(404, $controller->create('missing')->getStatus());
		$this->assertSame(404, $controller->destroy('missing', 'cred-old')->getStatus());
	}

	public function testALabelAndAnExpiryAreRequiredToBeReadable(): void {
		$before = $this->stored;

		$this->params = ['label' => '   '];
		$this->assertSame(400, $this->controller()->create('c-1')->getStatus());
		$this->params = ['label' => str_repeat('x', 101)];
		$this->assertSame(400, $this->controller()->create('c-1')->getStatus());
		$this->params = ['label' => 'ok', 'expiresAt' => 'next week-ish'];
		$this->assertSame(400, $this->controller()->create('c-1')->getStatus());
		$this->params = ['label' => 'ok', 'expiresAt' => ['2027']];
		$this->assertSame(400, $this->controller()->create('c-1')->getStatus());

		$this->assertSame($before, $this->stored);
	}

	public function testAFailedSaveReturnsNoKey(): void {
		$this->params = ['label' => 'Partner'];
		$this->saveFails = true;

		$response = $this->controller()->create('c-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertArrayNotHasKey('key', $response->getData());
		$this->assertSame(500, $this->controller()->destroy('c-1', 'cred-old')->getStatus());
	}

	public function testOnlyAnAdministratorManagesCredentials(): void {
		$this->params = ['label' => 'Partner'];
		$before = $this->stored;

		foreach ([[null, 401], ['alice', 403]] as [$uid, $status]) {
			$controller = $this->controller(uid: $uid, admin: false);
			$this->assertSame($status, $controller->index('c-1')->getStatus());
			$this->assertSame($status, $controller->create('c-1')->getStatus());
			$this->assertSame($status, $controller->destroy('c-1', 'cred-old')->getStatus());
		}

		$this->assertSame($before, $this->stored);
	}

	/**
	 * The controller over the fake store.
	 *
	 * @param string|null $uid   The session user, or null for none.
	 * @param bool        $admin Whether that user is an administrator.
	 *
	 * @return ConsumerCredentialsController
	 */
	private function controller(?string $uid = 'admin', bool $admin = true): ConsumerCredentialsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true): ObjectEntity {
				$this->assertSame(['integriq', 'consumer', false, false], [$register, $schema, $_rbac, $_render]);
				if (isset($this->stored[$id]) === false) {
					throw new DoesNotExistException('no');
				}
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->stored[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				if ($this->saveFails === true) {
					throw new RuntimeException('down');
				}
				$this->stored[(string)$uuid] = $object;
				return new ObjectEntity();
			}
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf(str_replace('%1$s', '%s', $text), $parameters));

		return new ConsumerCredentialsController('integriq', $request, $session, $groups, $objects, $this->credentials, $l);
	}
}
