<?php

/**
 * Unit tests for the Graph lookup route behind the SharePoint setup dialog.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SharePointSetupController;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The real adapter over a stubbed credential broker: which Graph path is
 * asked for with which credential, and what the dialog gets back.
 */
class SharePointSetupControllerTest extends TestCase {

	/**
	 * The broker double.
	 *
	 * @var CredentialBrokerService&MockObject
	 */
	private CredentialBrokerService $broker;

	/**
	 * Set up the broker double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->broker = $this->createMock(CredentialBrokerService::class);
	}//end setUp()

	/**
	 * The controller over the real adapter, for a request with these query parameters.
	 *
	 * The configured credential is `configured-cred`, so a test can tell it from the one the dialog picked.
	 *
	 * @param array<string, string> $query The query parameters.
	 *
	 * @return SharePointSetupController
	 */
	private function controller(array $query): SharePointSetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('configured-cred');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$adapter = new SharePointOnlineAdapter(
			credentialBroker: $this->broker,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			rootFolder: $this->createMock(IRootFolder::class),
			userSession: $this->createMock(IUserSession::class)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($query[$key] ?? $default)
		);

		return new SharePointSetupController(request: $request, adapter: $adapter);
	}//end controller()

	/**
	 * Make the broker answer one Graph call with this status and body, and expect this path and credential.
	 *
	 * @param string               $path       The expected Graph path.
	 * @param int                  $status     The status Graph answers.
	 * @param array<string, mixed> $body       The body Graph answers.
	 * @param string               $credential The expected credential.
	 *
	 * @return void
	 */
	private function graphAnswers(string $path, int $status, array $body, string $credential = 'picked-cred'): void {
		$this->broker->expects($this->once())
			->method('request')
			->with($credential, 'openconnector', 'GET', $path, [], null)
			->willReturn(['status' => $status, 'headers' => [], 'body' => json_encode($body)]);
	}//end graphAnswers()

	/**
	 * Only an integriq administrator reaches the route.
	 *
	 * @return void
	 */
	public function testTheRouteIsForIntegriqAdministratorsOnly(): void {
		$attributes = (new ReflectionMethod(SharePointSetupController::class, 'lookup'))->getAttributes(AuthorizedAdminSetting::class);

		$this->assertCount(1, $attributes);
	}//end testTheRouteIsForIntegriqAdministratorsOnly()

	/**
	 * A site search with the picked credential returns the Graph matches with id and name.
	 *
	 * @return void
	 */
	public function testASiteSearchReturnsTheMatchesWithTheirIdAndName(): void {
		$this->graphAnswers(
			path: '/v1.0/sites?search=Woo%20verzoeken',
			status: 200,
			body: ['value' => [
				['id' => 'contoso.sharepoint.com,aaa,bbb', 'displayName' => 'Woo verzoeken', 'name' => 'woo', 'webUrl' => 'https://contoso.sharepoint.com/sites/woo'],
				['id' => 'contoso.sharepoint.com,ccc,ddd', 'name' => 'Woo archief'],
			]]
		);

		$response = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'sites', 'search' => 'Woo verzoeken'])->lookup();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['items' => [
				['id' => 'contoso.sharepoint.com,aaa,bbb', 'name' => 'Woo verzoeken', 'webUrl' => 'https://contoso.sharepoint.com/sites/woo', 'isFolder' => false],
				['id' => 'contoso.sharepoint.com,ccc,ddd', 'name' => 'Woo archief', 'webUrl' => null, 'isFolder' => false],
			]],
			$response->getData()
		);
	}//end testASiteSearchReturnsTheMatchesWithTheirIdAndName()

	/**
	 * A site's libraries and a folder's children are read from their own Graph paths, ids encoded.
	 *
	 * @return void
	 */
	public function testLibrariesAndFolderChildrenAreReadFromTheirPaths(): void {
		$this->graphAnswers(path: '/v1.0/sites/contoso.sharepoint.com%2Caaa%2Cbbb/drives', status: 200, body: ['value' => [['id' => 'drv-1', 'name' => 'Dossiers']]]);
		$drives = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'drives', 'siteId' => 'contoso.sharepoint.com,aaa,bbb'])->lookup();
		$this->assertSame('Dossiers', $drives->getData()['items'][0]['name']);
	}//end testLibrariesAndFolderChildrenAreReadFromTheirPaths()

	/**
	 * A folder's children name which items are folders; without an item the library root is read.
	 *
	 * @return void
	 */
	public function testFolderChildrenSayWhichAreFolders(): void {
		$this->broker->expects($this->exactly(2))
			->method('request')
			->willReturnCallback(
				function (string $credential, string $app, string $method, string $path): array {
					$this->assertContains($path, ['/v1.0/drives/drv-1/root/children', '/v1.0/drives/drv-1/items/fld%2F1/children']);
					return ['status' => 200, 'headers' => [], 'body' => json_encode(['value' => [['id' => 'f', 'name' => 'Gereed', 'folder' => ['childCount' => 2]], ['id' => 'd', 'name' => 'besluit.pdf']]])];
				}
			);

		$root = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'children', 'driveId' => 'drv-1'])->lookup();
		$folder = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'children', 'driveId' => 'drv-1', 'itemId' => 'fld/1'])->lookup();

		$this->assertSame([true, false], array_column($root->getData()['items'], 'isFolder'));
		$this->assertSame(200, $folder->getStatus());
	}//end testFolderChildrenSayWhichAreFolders()

	/**
	 * A Graph 403 answers 403 naming the permission the step needs and Graph's own code and message.
	 *
	 * @return void
	 */
	public function testAGraphRefusalNamesThePermission(): void {
		$this->graphAnswers(
			path: '/v1.0/sites?search=Woo',
			status: 403,
			body: ['error' => ['code' => 'accessDenied', 'message' => 'Either scp or roles claim need to be present in the token.']]
		);

		$response = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'sites', 'search' => 'Woo'])->lookup();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			[
				'items' => [],
				'error' => 'graph_refused',
				'permission' => 'Sites.Read.All',
				'graphCode' => 'accessDenied',
				'graphMessage' => 'Either scp or roles claim need to be present in the token.',
			],
			$response->getData()
		);
	}//end testAGraphRefusalNamesThePermission()

	/**
	 * Reading a library's folders needs Files.Read.All, and a 401 is named the same way.
	 *
	 * @return void
	 */
	public function testAFolderRefusalNamesFilesReadAll(): void {
		$this->graphAnswers(path: '/v1.0/drives/drv-1/root/children', status: 401, body: []);

		$response = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'children', 'driveId' => 'drv-1'])->lookup();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Files.Read.All', $response->getData()['permission']);
	}//end testAFolderRefusalNamesFilesReadAll()

	/**
	 * A lookup that is malformed or names no credential reaches neither the broker nor the configured credential.
	 *
	 * @return void
	 */
	public function testAMalformedLookupNeverReachesTheBroker(): void {
		$this->broker->expects($this->never())->method('request');

		$cases = [
			[['credential' => 'picked-cred', 'kind' => 'sites'], 'invalid_lookup'],
			[['credential' => 'picked-cred', 'kind' => 'drives'], 'invalid_lookup'],
			[['credential' => 'picked-cred', 'kind' => 'children'], 'invalid_lookup'],
			[['credential' => 'picked-cred', 'kind' => 'users', 'search' => 'x'], 'invalid_lookup'],
			[['kind' => 'sites', 'search' => 'Woo'], 'credential_required'],
		];
		foreach ($cases as [$query, $error]) {
			$response = $this->controller(query: $query)->lookup();
			$this->assertSame(400, $response->getStatus());
			$this->assertSame($error, $response->getData()['error']);
		}
	}//end testAMalformedLookupNeverReachesTheBroker()

	/**
	 * A credential the broker refuses, and a Graph failure, answer 502 without items.
	 *
	 * @return void
	 */
	public function testABrokerRefusalOrGraphFailureIs502(): void {
		$this->broker->method('request')->willReturnOnConsecutiveCalls(
			$this->throwException(new CredentialAccessDeniedException('not allowed for this app')),
			['status' => 500, 'headers' => [], 'body' => 'oops']
		);

		$refused = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'sites', 'search' => 'Woo'])->lookup();
		$failed = $this->controller(query: ['credential' => 'picked-cred', 'kind' => 'sites', 'search' => 'Woo'])->lookup();

		$this->assertSame([502, 'credential_unusable'], [$refused->getStatus(), $refused->getData()['error']]);
		$this->assertSame([502, 'graph_error'], [$failed->getStatus(), $failed->getData()['error']]);
	}//end testABrokerRefusalOrGraphFailureIs502()
}//end class
