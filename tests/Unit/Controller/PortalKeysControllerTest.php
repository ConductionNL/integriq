<?php

/**
 * Unit tests for PortalKeysController: a developer generates, lists and
 * revokes the keys of an application they own; another developer's
 * application, or an account outside the developer group, is refused and
 * nothing is read or written.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Integriq\Controller\PortalKeysController;
use OCA\Integriq\Service\Consumer\ConsumerCredentialList;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Service\DeveloperPortalAccess;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
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
 * @covers \OCA\Integriq\Controller\PortalKeysController
 * @covers \OCA\Integriq\Service\DeveloperPortalAccess
 * @uses   \OCA\Integriq\Service\Consumer\ConsumerCredentialList
 * @uses   \OCA\Integriq\Service\Consumer\ConsumerCredentials
 * @uses   \OCA\Integriq\Service\Helper\RandomUuid
 */
final class PortalKeysControllerTest extends TestCase {

	/**
	 * Stored consumers by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Whether saving a consumer fails.
	 *
	 * @var boolean
	 */
	private bool $saveFails = false;

	/**
	 * How many times a consumer was saved.
	 *
	 * @var integer
	 */
	private int $saves = 0;

	/**
	 * The credentials rules.
	 *
	 * @var ConsumerCredentials
	 */
	private ConsumerCredentials $credentials;

	/**
	 * Two applications of developer-a, one of developer-b, and one consumer an administrator made.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);
		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return substr(str_repeat('K'.$counter.'abcdefgh', 10), 0, $length);
			}
		);
		$this->credentials = new ConsumerCredentials($appConfig, $random);

		$this->stored = [
			'app-a' => [
				'name'      => 'Case reader',
				'ownerKind' => 'developer',
				'userId'    => 'developer-a',
				ConsumerCredentials::FIELD => [
					['id' => 'key-old', 'label' => 'old', 'type' => 'apiKey', 'keyPrefix' => 'oldkey', 'keyHash' => 'h-old', 'expiresAt' => null, 'lastUsedAt' => null],
					['id' => 'cert-1', 'label' => 'pinned', 'type' => 'clientCertificate', 'fingerprint' => 'ab'],
				],
			],
			'app-b' => [
				'name'      => 'Not yours',
				'ownerKind' => 'developer',
				'userId'    => 'developer-b',
				ConsumerCredentials::FIELD => [
					['id' => 'key-b', 'label' => 'b', 'type' => 'apiKey', 'keyPrefix' => 'bbbbbb', 'keyHash' => 'h-b'],
				],
			],
			'admin-made' => [
				'name'   => 'Partner',
				'userId' => 'developer-a',
				ConsumerCredentials::FIELD => [],
			],
		];
	}//end setUp()

	/**
	 * REQ-DEVP-003: a generated key is in the create response once, and the list shows it without key or hash.
	 *
	 * @return void
	 */
	public function testAGeneratedKeyIsShownOnceAndListedWithoutItsHash(): void {
		$this->params = ['label' => '  Production  '];

		$created = $this->controller()->create('app-a');

		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$key = $created->getData()['key'];
		$this->assertNotSame('', $key);
		$this->assertSame('Production', $created->getData()['credential']['label']);
		$this->assertArrayNotHasKey('keyHash', $created->getData()['credential']);

		$stored = end($this->stored['app-a'][ConsumerCredentials::FIELD]);
		$this->assertSame($this->credentials->hashKey(key: $key), $stored['keyHash']);

		$listed = $this->controller()->index('app-a')->getData()['results'];
		$this->assertSame(['key-old', 'cert-1', $stored['id']], array_column($listed, 'id'));
		$this->assertStringNotContainsString($key, (string)json_encode($listed));
		$this->assertStringNotContainsString('h-old', (string)json_encode($listed));
	}//end testAGeneratedKeyIsShownOnceAndListedWithoutItsHash()

	/**
	 * REQ-DEVP-003: a developer replaces a leaked key; the new key passes and the revoked one no longer matches.
	 *
	 * @return void
	 */
	public function testARevokedKeyNoLongerMatchesAndTheNewOneDoes(): void {
		$this->params = ['label' => 'new'];
		$key = $this->controller()->create('app-a')->getData()['key'];

		$revoked = $this->controller()->destroy('app-a', 'key-old');

		$this->assertSame(['revoked' => 'key-old'], $revoked->getData());
		$list = $this->stored['app-a'][ConsumerCredentials::FIELD];
		$this->assertNotContains('key-old', array_column($list, 'id'));
		$this->assertNotNull($this->credentials->matchingApiKey(credentials: $list, key: $key, now: new DateTimeImmutable()));
	}//end testARevokedKeyNoLongerMatchesAndTheNewOneDoes()

	/**
	 * The portal revokes API keys only: a pinned client certificate is the administrator's.
	 *
	 * @return void
	 */
	public function testAPinnedCertificateIsNotRevokedFromThePortal(): void {
		$response = $this->controller()->destroy('app-a', 'cert-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(0, $this->saves);
		$this->assertContains('cert-1', array_column($this->stored['app-a'][ConsumerCredentials::FIELD], 'id'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->destroy('app-a', 'no-such-key')->getStatus());
	}//end testAPinnedCertificateIsNotRevokedFromThePortal()

	/**
	 * Another developer's application, a consumer an administrator made and an unknown id all answer 404,
	 * and nothing is written.
	 *
	 * @return void
	 */
	public function testAnApplicationTheDeveloperDoesNotOwnIsNotFound(): void {
		$this->params = ['label' => 'mine now'];
		foreach (['app-b', 'admin-made', 'missing'] as $id) {
			$controller = $this->controller();
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index($id)->getStatus(), $id);
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->create($id)->getStatus(), $id);
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->destroy($id, 'key-b')->getStatus(), $id);
		}

		$this->assertSame(0, $this->saves);
		$this->assertSame(['key-b'], array_column($this->stored['app-b'][ConsumerCredentials::FIELD], 'id'));
	}//end testAnApplicationTheDeveloperDoesNotOwnIsNotFound()

	/**
	 * A signed-out caller gets 401 and an account outside the developer group 403, before anything is read.
	 *
	 * @return void
	 */
	public function testOnlyASignedInDeveloperReachesTheKeys(): void {
		$signedOut = $this->controller(uid: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $signedOut->index('app-a')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $signedOut->create('app-a')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $signedOut->destroy('app-a', 'key-old')->getStatus());

		$outsider = $this->controller(isDeveloper: false);
		$this->assertSame(Http::STATUS_FORBIDDEN, $outsider->index('app-a')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $outsider->create('app-a')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $outsider->destroy('app-a', 'key-old')->getStatus());
		$this->assertSame(0, $this->saves);
	}//end testOnlyASignedInDeveloperReachesTheKeys()

	/**
	 * An empty or too long label is a 400, and a failed save a 500; neither changes the list.
	 *
	 * @return void
	 */
	public function testABadLabelOrAFailedSaveChangesNothing(): void {
		foreach (['', '   ', str_repeat('x', 101)] as $label) {
			$this->params = ['label' => $label];
			$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->create('app-a')->getStatus());
		}

		$this->params = ['label' => 'ok'];
		$this->saveFails = true;
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller()->create('app-a')->getStatus());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller()->destroy('app-a', 'key-old')->getStatus());
		$this->assertCount(2, $this->stored['app-a'][ConsumerCredentials::FIELD]);
	}//end testABadLabelOrAFailedSaveChangesNothing()

	/**
	 * The controller for an account.
	 *
	 * @param string|null $uid         The signed-in account, or null when signed out.
	 * @param bool        $isDeveloper Whether it is in the developer group.
	 *
	 * @return PortalKeysController The controller.
	 */
	private function controller(?string $uid='developer-a', bool $isDeveloper=true): PortalKeysController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $member, string $group): bool => $isDeveloper === true && $group === 'integriq-developers'
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf(str_replace('%1$s', '%s', $text), $parameters));

		return new PortalKeysController(
			request: $request,
			access: new DeveloperPortalAccess(appConfig: $appConfig, groupManager: $groups),
			userSession: $session,
			consumers: new ConsumerCredentialList($this->objects()),
			credentials: $this->credentials,
			l: $l
		);
	}//end controller()

	/**
	 * An object service over the stored consumers.
	 *
	 * @return ObjectService The service.
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?ObjectFindOptions $options=null): ObjectEntity {
				$this->assertSame(['integriq', 'consumer'], [$options?->register, $options?->schema]);
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
			function ($object, ObjectSaveOptions $options=new ObjectSaveOptions()): ObjectEntity {
				if ($this->saveFails === true) {
					throw new RuntimeException('down');
				}

				$this->saves++;
				$this->stored[(string)$options->uuid] = $object;
				return new ObjectEntity();
			}
		);

		return $objects;
	}//end objects()
}//end class
