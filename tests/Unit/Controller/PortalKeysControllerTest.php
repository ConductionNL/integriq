<?php

/**
 * Unit tests for PortalKeysController and DeveloperApplications: a developer
 * generates, lists and revokes the keys of an application they own; another
 * developer's application, or an account outside the developer group, is
 * refused and nothing is read or written.
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
use OCA\Integriq\Controller\DeveloperApplications;
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
 * @covers \OCA\Integriq\Controller\DeveloperApplications
 * @uses   \OCA\Integriq\Service\DeveloperPortalAccess
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
	 * Whether reading a consumer fails.
	 *
	 * @var boolean
	 */
	private bool $readFails = false;

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
		$counter = 0;
		$random  = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return substr(str_repeat('K'.$counter.'abcdefgh', 10), 0, $length);
			}
		);
		$this->credentials = new ConsumerCredentials(appConfig: $this->defaultConfig(), random: $random);

		$this->stored = [
			'app-a' => [
				'name'      => 'Case reader',
				'ownerKind' => 'developer',
				'userId'    => 'developer-a',
				ConsumerCredentials::FIELD => [
					['id' => 'key-old', 'label' => 'old', 'type' => 'apiKey', 'keyPrefix' => 'oldkey', 'keyHash' => 'h-old', 'lastUsedAt' => null],
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

		$created = $this->controller()->create(consumerId: 'app-a');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $created->getStatus());
		$key = $created->getData()['key'];
		$this->assertNotSame(expected: '', actual: $key);
		$this->assertSame(expected: 'Production', actual: $created->getData()['credential']['label']);
		$this->assertArrayNotHasKey(key: 'keyHash', array: $created->getData()['credential']);

		$stored = end($this->stored['app-a'][ConsumerCredentials::FIELD]);
		$this->assertSame(expected: $this->credentials->hashKey(key: $key), actual: $stored['keyHash']);

		$listed = $this->controller()->index(consumerId: 'app-a')->getData()['results'];
		$this->assertSame(expected: ['key-old', 'cert-1', $stored['id']], actual: array_column($listed, 'id'));
		$this->assertStringNotContainsString(needle: $key, haystack: (string)json_encode($listed));
		$this->assertStringNotContainsString(needle: 'h-old', haystack: (string)json_encode($listed));
	}//end testAGeneratedKeyIsShownOnceAndListedWithoutItsHash()

	/**
	 * REQ-DEVP-003: a developer replaces a leaked key; the new key matches and the revoked one is gone.
	 *
	 * @return void
	 */
	public function testARevokedKeyIsGoneAndTheNewOneMatches(): void {
		$this->params = ['label' => 'new'];
		$key = $this->controller()->create(consumerId: 'app-a')->getData()['key'];

		$revoked = $this->controller()->destroy(consumerId: 'app-a', credentialId: 'key-old');

		$this->assertSame(expected: ['revoked' => 'key-old'], actual: $revoked->getData());
		$list = $this->stored['app-a'][ConsumerCredentials::FIELD];
		$this->assertNotContains(needle: 'key-old', haystack: array_column($list, 'id'));
		$this->assertNotNull(actual: $this->credentials->matchingApiKey(credentials: $list, key: $key, now: new DateTimeImmutable()));
	}//end testARevokedKeyIsGoneAndTheNewOneMatches()

	/**
	 * The portal revokes API keys only: a pinned client certificate is the administrator's.
	 *
	 * @return void
	 */
	public function testAPinnedCertificateIsNotRevokedFromThePortal(): void {
		$response = $this->controller()->destroy(consumerId: 'app-a', credentialId: 'cert-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: 0, actual: $this->saves);
		$this->assertContains(needle: 'cert-1', haystack: array_column($this->stored['app-a'][ConsumerCredentials::FIELD], 'id'));
		$unknown = $this->controller()->destroy(consumerId: 'app-a', credentialId: 'no-such-key');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $unknown->getStatus());
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
			$this->assertSame(
				expected: [Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND],
				actual: $this->statuses(controller: $this->controller(), consumerId: $id),
				message: $id
			);
		}

		$this->assertSame(expected: 0, actual: $this->saves);
		$this->assertSame(expected: ['key-b'], actual: array_column($this->stored['app-b'][ConsumerCredentials::FIELD], 'id'));
	}//end testAnApplicationTheDeveloperDoesNotOwnIsNotFound()

	/**
	 * A signed-out caller gets 401 and an account outside the developer group 403, before anything is read.
	 *
	 * @return void
	 */
	public function testOnlyASignedInDeveloperReachesTheKeys(): void {
		$this->readFails = true;
		$this->params    = ['label' => 'x'];

		$signedOut = $this->statuses(controller: $this->controller(uid: null), consumerId: 'app-a');
		$this->assertSame(expected: array_fill(0, 3, Http::STATUS_UNAUTHORIZED), actual: $signedOut);

		$outsider = $this->statuses(controller: $this->controller(isDeveloper: false), consumerId: 'app-a');
		$this->assertSame(expected: array_fill(0, 3, Http::STATUS_FORBIDDEN), actual: $outsider);
		$this->assertSame(expected: 0, actual: $this->saves);
	}//end testOnlyASignedInDeveloperReachesTheKeys()

	/**
	 * An application that cannot be read answers 503, not 404: an outage does not read as a missing application.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationIsAnOutage(): void {
		$this->readFails = true;
		$this->params    = ['label' => 'x'];

		$statuses = $this->statuses(controller: $this->controller(), consumerId: 'app-a');

		$this->assertSame(expected: array_fill(0, 3, Http::STATUS_SERVICE_UNAVAILABLE), actual: $statuses);
		$this->assertSame(expected: 0, actual: $this->saves);
	}//end testAnUnreadableApplicationIsAnOutage()

	/**
	 * An empty or too long label is a 400, and a failed save a 500; neither changes the list.
	 *
	 * @return void
	 */
	public function testABadLabelOrAFailedSaveChangesNothing(): void {
		foreach (['', '   ', str_repeat('x', 101)] as $label) {
			$this->params = ['label' => $label];
			$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller()->create(consumerId: 'app-a')->getStatus());
		}

		$this->params    = ['label' => 'ok'];
		$this->saveFails = true;
		$failedCreate    = $this->controller()->create(consumerId: 'app-a');
		$failedRevoke    = $this->controller()->destroy(consumerId: 'app-a', credentialId: 'key-old');
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $failedCreate->getStatus());
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $failedRevoke->getStatus());
		$this->assertCount(expectedCount: 2, haystack: $this->stored['app-a'][ConsumerCredentials::FIELD]);
	}//end testABadLabelOrAFailedSaveChangesNothing()

	/**
	 * The statuses of list, generate and revoke on one application.
	 *
	 * @param PortalKeysController $controller The controller.
	 * @param string               $consumerId The application.
	 *
	 * @return list<int> The three statuses.
	 */
	private function statuses(PortalKeysController $controller, string $consumerId): array {
		return [
			$controller->index(consumerId: $consumerId)->getStatus(),
			$controller->create(consumerId: $consumerId)->getStatus(),
			$controller->destroy(consumerId: $consumerId, credentialId: 'key-b')->getStatus(),
		];
	}//end statuses()

	/**
	 * The controller for an account.
	 *
	 * @param string|null $uid         The signed-in account, or null when signed out.
	 * @param bool        $isDeveloper Whether it is in the developer group.
	 *
	 * @return PortalKeysController The controller.
	 */
	private function controller(?string $uid='developer-a', bool $isDeveloper=true): PortalKeysController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $member, string $group): bool => $isDeveloper === true && $group === 'integriq-developers'
		);

		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf(str_replace('%1$s', '%s', $text), $parameters)
		);

		$applications = new DeveloperApplications(
			userSession: $session,
			access: new DeveloperPortalAccess(appConfig: $this->defaultConfig(), groupManager: $groups),
			consumers: new ConsumerCredentialList(objectService: $this->objects())
		);

		return new PortalKeysController(request: $request, applications: $applications, credentials: $this->credentials, l: $l);
	}//end controller()

	/**
	 * An app config that answers every key with its default.
	 *
	 * @return IAppConfig The config.
	 */
	private function defaultConfig(): IAppConfig {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);

		return $config;
	}//end defaultConfig()

	/**
	 * An object service over the stored consumers.
	 *
	 * @return ObjectService The service.
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?ObjectFindOptions $options=null): ObjectEntity {
				$this->assertSame(expected: ['integriq', 'consumer'], actual: [$options?->register, $options?->schema]);
				if ($this->readFails === true) {
					throw new RuntimeException('down');
				}

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
