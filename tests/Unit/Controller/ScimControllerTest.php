<?php

/**
 * Unit tests for ScimController — the credential gate that runs before any
 * account is read, and the deprovision that disables rather than deletes.
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
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ScimController;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCA\Integriq\Service\AuthorizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SCIM endpoint.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */
class ScimControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var ScimProvisioningService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $provisioningService;

	/**
	 * @var AuthorizationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $authorizationService;

	/**
	 * Build the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->provisioningService = $this->createMock(ScimProvisioningService::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);

		// The default is an authenticated call that resolves to a named consumer,
		// which is what every pre-existing test assumed implicitly. REQ-DS-007
		// makes the resolution explicit, so the double has to answer it.
		$consumer = $this->createMock(ObjectEntity::class);
		$consumer->method('getUuid')->willReturn('consumer-1');
		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ScimController The controller.
	 */
	private function controller(): ScimController {
		return new ScimController(
			appName: 'integriq',
			request: $this->request,
			provisioningService: $this->provisioningService,
			authorizationService: $this->authorizationService,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A call with no credential is rejected before any account is read.
	 *
	 * The assertion that matters is the second one: the provisioning service is
	 * never touched, so "rejected" means rejected before the read, not after it.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallReadsNothing(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->provisioningService->expects($this->never())->method('listUsers');
		$this->authorizationService->expects($this->never())->method('authorizeApiKey');

		$response = $this->controller()->listUsers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Unauthorized', $response->getData()['detail']);

	}//end testAnUnauthenticatedCallReadsNothing()

	/**
	 * A call with an invalid credential is rejected before any account is read.
	 *
	 * @return void
	 */
	public function testAnInvalidCredentialReadsNothing(): void {
		$this->request->method('getHeader')->willReturn('Bearer wrong-key');
		$this->authorizationService->method('authorizeApiKey')->willThrowException(
			new AuthenticationException(message: 'Invalid API key', details: [])
		);
		$this->provisioningService->expects($this->never())->method('listUsers');

		$response = $this->controller()->listUsers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnInvalidCredentialReadsNothing()

	/**
	 * Every SCIM route rejects an unauthenticated call before it reads.
	 *
	 * One test per route rather than one for the surface: a route that quietly
	 * lost its gate would otherwise be covered by a test that never called it.
	 *
	 * @return void
	 */
	public function testEverySCIMRouteRejectsAnUnauthenticatedCall(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParams')->willReturn([]);
		$this->provisioningService->expects($this->never())->method('listUsers');
		$this->provisioningService->expects($this->never())->method('getUser');
		$this->provisioningService->expects($this->never())->method('upsertUser');
		$this->provisioningService->expects($this->never())->method('deactivateUser');
		$this->provisioningService->expects($this->never())->method('listGroups');
		$this->provisioningService->expects($this->never())->method('setGroupMembers');

		$controller = $this->controller();

		$responses = [
			'listUsers' => $controller->listUsers(),
			'getUser' => $controller->getUser(id: 'admin'),
			'createUser' => $controller->createUser(),
			'updateUser' => $controller->updateUser(id: 'admin'),
			'deleteUser' => $controller->deleteUser(id: 'admin'),
			'listGroups' => $controller->listGroups(),
			'updateGroup' => $controller->updateGroup(id: 'behandelaars'),
		];

		foreach ($responses as $route => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$route . ' must reject an unauthenticated call'
			);
			$this->assertArrayNotHasKey('Resources', $response->getData());
		}

	}//end testEverySCIMRouteRejectsAnUnauthenticatedCall()

	/**
	 * A blank or malformed `count` takes the default, it does not become 0.
	 *
	 * `(int)$request->getParam('count', 100)` looked safe and was not: NC's
	 * `getParam()` defaults only when the key is ABSENT, so `?count=` yields `''`,
	 * and PHP maps `''`, `'abc'`, `'undefined'` and `'null'` all to `0`. Harmless
	 * until the page-size clamp moved above the exact-filter branch — after which
	 * a `0` short-circuited the lookup an identity system runs before deciding to
	 * create an account, so a client sending a blank `count` was told the account
	 * does not exist and would create a duplicate, silently (integriq#2104 review
	 * 5276350047).
	 *
	 * @param string $raw The raw `count` the caller sent.
	 *
	 * @return void
	 *
	 * @dataProvider unreadableCountProvider
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	public function testAnUnreadableCountTakesTheDefault(string $raw): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->request->method('getParams')->willReturn(['count' => $raw]);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($raw) {
				return ($key === 'count') ? $raw : $default;
			}
		);

		$this->provisioningService->expects($this->once())
			->method('listUsers')
			->with($this->anything(), 100)
			->willReturn([]);

		$this->controller()->listUsers();

	}//end testAnUnreadableCountTakesTheDefault()

	/**
	 * Values PHP would silently coerce to 0.
	 *
	 * @return array<string, array{string}> The cases.
	 */
	public static function unreadableCountProvider(): array {
		return [
			'empty string' => [''],
			'alphabetic' => ['abc'],
			'javascript undefined' => ['undefined'],
			'javascript null' => ['null'],
		];

	}//end unreadableCountProvider()

	/**
	 * A numeric `count` is passed through untouched, including 0 and negatives.
	 *
	 * Those two are deliberate caller choices RFC 7644 §3.4.2.4 defines — a
	 * negative SHALL be read as 0, and 0 returns no resources — so the controller
	 * must not second-guess them. The clamp belongs in the service.
	 *
	 * @param string  $raw      The raw `count` the caller sent.
	 * @param integer $expected What the service must receive.
	 *
	 * @return void
	 *
	 * @dataProvider numericCountProvider
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	public function testANumericCountIsPassedThrough(string $raw, int $expected): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->request->method('getParams')->willReturn(['count' => $raw]);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($raw) {
				return ($key === 'count') ? $raw : $default;
			}
		);

		$this->provisioningService->expects($this->once())
			->method('listGroups')
			->with($this->anything(), $expected)
			->willReturn([]);

		$this->controller()->listGroups();

	}//end testANumericCountIsPassedThrough()

	/**
	 * Numeric values the controller must not alter.
	 *
	 * @return array<string, array{string, int}> The cases.
	 */
	public static function numericCountProvider(): array {
		return [
			'zero is a deliberate probe' => ['0', 0],
			'negative is read as 0 by the service' => ['-1', -1],
			'an ordinary page size' => ['25', 25],
		];

	}//end numericCountProvider()

	/**
	 * A valid credential reads the users and answers a SCIM ListResponse.
	 *
	 * @return void
	 */
	public function testAValidCredentialListsUsers(): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->request->method('getParam')->willReturn('');
		$this->provisioningService->method('listUsers')->willReturn(
			[['id' => 'anja', 'userName' => 'anja', 'active' => true]]
		);

		$response = $this->controller()->listUsers();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['totalResults']);
		$this->assertSame('anja', $response->getData()['Resources'][0]['userName']);

	}//end testAValidCredentialListsUsers()

	/**
	 * A deprovision disables the account and never deletes it.
	 *
	 * @return void
	 */
	public function testADeprovisionDisablesAndDoesNotDelete(): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->provisioningService->expects($this->once())
			->method('deactivateUser')
			->with('dana')
			->willReturn(['dossiq' => 4]);

		$response = $this->controller()->deleteUser(id: 'dana');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['active']);
		$this->assertSame(['dossiq' => 4], $response->getData()['openWork']);

	}//end testADeprovisionDisablesAndDoesNotDelete()

	/**
	 * A SCIM patch setting `active` false reaches the provisioning service as a
	 * deactivation on the named account.
	 *
	 * @return void
	 */
	public function testAPatchDeactivationFlattensOntoTheAccount(): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->request->method('getParams')->willReturn(
			[
				'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
				'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
			]
		);

		$captured = null;
		$this->provisioningService->method('upsertUser')->willReturnCallback(
			static function (array $resource) use (&$captured): array {
				$captured = $resource;

				return ['id' => 'dana', 'active' => false];
			}
		);

		$this->controller()->updateUser(id: 'dana');

		$this->assertSame('dana', $captured['userName']);
		$this->assertFalse($captured['active']);

	}//end testAPatchDeactivationFlattensOntoTheAccount()
	/**
	 * A credential that authenticates but names no consumer is refused, because
	 * a SCIM write nobody can be held to is a write nobody can investigate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	public function testACallThatNamesNoConsumerIsRefused(): void {
		$unattributable = $this->createMock(AuthorizationService::class);
		$unattributable->method('getResolvedConsumer')->willReturn(null);
		$this->authorizationService = $unattributable;

		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->provisioningService->expects($this->never())->method('listUsers');

		$response = $this->controller()->listUsers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testACallThatNamesNoConsumerIsRefused()

	/**
	 * A refused group write answers 403, distinct from the 404 that means the
	 * group does not exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	public function testARefusedGroupWriteAnswersForbidden(): void {
		$this->request->method('getHeader')->willReturn('Bearer right-key');
		$this->request->method('getParam')->willReturn([]);
		$this->provisioningService->method('setGroupMembers')->willThrowException(
			new DirectorySyncRefusalException(
				message: 'the group is privileged and is never writable over SCIM',
				context: [
					'group' => 'admin',
					'consumer' => 'consumer-1',
					'detail' => 'This group cannot be managed over SCIM.',
				]
			)
		);

		$response = $this->controller()->updateGroup(id: 'admin');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		// The body must not disclose why the group is special.
		$this->assertSame('This group cannot be managed over SCIM.', $response->getData()['detail']);
		$this->assertStringNotContainsString('privileged', (string)json_encode($response->getData()));

	}//end testARefusedGroupWriteAnswersForbidden()
}//end class
