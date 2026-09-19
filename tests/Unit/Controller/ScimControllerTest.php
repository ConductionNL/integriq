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
use OCA\Integriq\Service\AuthorizationService;
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
}//end class
