<?php

/**
 * Unit tests for SharePointOnlineAdapter's setup lookups, metadata and list refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
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

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The site / library / folder lookups of the SharePoint setup dialog, and the
 * listing's answers to an unusable Graph response.
 */
#[CoversClass(SharePointOnlineAdapter::class)]
#[UsesClass(AbstractCategoryAdapterProvider::class)]
class SharePointOnlineAdapterLookupTest extends TestCase {

	/**
	 * @var CredentialBrokerService&MockObject
	 */
	private $credentialBroker;

	/**
	 * @var SharePointOnlineAdapter
	 */
	private SharePointOnlineAdapter $adapter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->credentialBroker = $this->createMock(CredentialBrokerService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('cred-default');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);

		$this->adapter = new SharePointOnlineAdapter(
			credentialBroker: $this->credentialBroker,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			rootFolder: $this->createMock(IRootFolder::class),
			userSession: $this->createMock(IUserSession::class)
		);

	}//end setUp()

	/**
	 * Expect exactly one brokered GET with the given credential and path.
	 *
	 * @param string $credentialId The credential.
	 * @param string $path The Graph path.
	 * @param array|null $answer The broker's answer; null makes the broker refuse.
	 *
	 * @return void
	 */
	private function expectGraphGet(string $credentialId, string $path, ?array $answer): void {
		$call = $this->credentialBroker->expects($this->once())
			->method('request')
			->with($credentialId, 'openconnector', 'GET', $path, [], null);
		if ($answer === null) {
			$call->willThrowException(new CredentialUpstreamException('upstream unreachable'));
			return;
		}

		$call->willReturn($answer);

	}//end expectGraphGet()

	/**
	 * The adapter's identity: id, translated label, icon, no required app.
	 *
	 * @return void
	 */
	public function testMetadata(): void {
		$this->assertSame('sharepoint-online', $this->adapter->getId());
		$this->assertSame('nl:SharePoint Online', $this->adapter->getLabel());
		$this->assertSame('FileDocumentMultiple', $this->adapter->getIcon());
		$this->assertNull($this->adapter->getRequiredApp());

	}//end testMetadata()

	/**
	 * Each lookup kind maps onto its URL-encoded Graph path, and items are normalised.
	 *
	 * @param string $kind The lookup kind.
	 * @param array<string, mixed> $params The lookup parameters.
	 * @param string $path The Graph path expected.
	 *
	 * @return void
	 */
	#[DataProvider('lookupPaths')]
	public function testLookupCallsTheGraphPathOfItsKind(string $kind, array $params, string $path): void {
		$value = [
			['id' => 's-1', 'displayName' => 'Intranet', 'name' => 'intranet', 'webUrl' => 'https://x/sites/intranet'],
			['id' => 'f-1', 'name' => 'Besluiten', 'folder' => ['childCount' => 2]],
			'not-an-item',
		];
		$this->expectGraphGet('cred-picked', $path, ['status' => 200, 'headers' => [], 'body' => json_encode(['value' => $value])]);

		$result = $this->adapter->lookup('cred-picked', $kind, $params);

		$this->assertSame(
			[
				'status' => 200,
				'items' => [
					['id' => 's-1', 'name' => 'Intranet', 'webUrl' => 'https://x/sites/intranet', 'isFolder' => false],
					['id' => 'f-1', 'name' => 'Besluiten', 'webUrl' => null, 'isFolder' => true],
				],
			],
			$result
		);

	}//end testLookupCallsTheGraphPathOfItsKind()

	/**
	 * The lookup kinds and the Graph paths they call.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function lookupPaths(): array {
		return [
			'sites search, trimmed and encoded' => ['sites', ['search' => '  raad & co '], '/v1.0/sites?search=raad%20%26%20co'],
			'drives of a site' => ['drives', ['siteId' => 'host,a/b,c'], '/v1.0/sites/host%2Ca%2Fb%2Cc/drives'],
			'children of a drive root' => ['children', ['driveId' => 'drv 1'], '/v1.0/drives/drv%201/root/children'],
			'children of a folder' => ['children', ['driveId' => 'drv', 'itemId' => '../x'], '/v1.0/drives/drv/items/..%2Fx/children'],
		];

	}//end lookupPaths()

	/**
	 * A kind that is unknown or lacks the value it needs is refused before Graph is called.
	 *
	 * @param string $kind The lookup kind.
	 * @param array<string, mixed> $params The lookup parameters.
	 *
	 * @return void
	 */
	#[DataProvider('invalidLookups')]
	public function testAnInvalidLookupIsRefusedWithoutCallingGraph(string $kind, array $params): void {
		$this->credentialBroker->expects($this->never())->method('request');

		$this->assertSame(
			['status' => 400, 'items' => [], 'error' => 'invalid_lookup'],
			$this->adapter->lookup('cred-picked', $kind, $params)
		);

	}//end testAnInvalidLookupIsRefusedWithoutCallingGraph()

	/**
	 * Lookups that name no Graph path.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function invalidLookups(): array {
		return [
			'sites without search' => ['sites', ['search' => '   ']],
			'drives without site' => ['drives', []],
			'children without drive' => ['children', ['itemId' => 'x']],
			'unknown kind' => ['users', ['search' => 'a', 'siteId' => 'b', 'driveId' => 'c']],
		];

	}//end invalidLookups()

	/**
	 * A lookup needs the credential the administrator picked.
	 *
	 * @return void
	 */
	public function testALookupWithoutACredentialIsRefused(): void {
		$this->credentialBroker->expects($this->never())->method('request');

		$this->assertSame(
			['status' => 400, 'items' => [], 'error' => 'credential_required'],
			$this->adapter->lookup('', 'sites', ['search' => 'raad'])
		);

	}//end testALookupWithoutACredentialIsRefused()

	/**
	 * When the broker cannot use the credential the lookup answers 502.
	 *
	 * @return void
	 */
	public function testAnUnusableCredentialAnswers502(): void {
		$this->expectGraphGet('cred-picked', '/v1.0/sites/s/drives', null);

		$this->assertSame(
			['status' => 502, 'items' => [], 'error' => 'credential_unusable'],
			$this->adapter->lookup('cred-picked', 'drives', ['siteId' => 's'])
		);

	}//end testAnUnusableCredentialAnswers502()

	/**
	 * A Graph 401/403 becomes a 403 naming the permission the step needs and Graph's own error.
	 *
	 * @return void
	 */
	public function testAGraphRefusalNamesThePermissionTheStepNeeds(): void {
		$body = json_encode(['error' => ['code' => 'accessDenied', 'message' => 'Either scp or roles claim need to be present']]);
		$this->expectGraphGet('cred-picked', '/v1.0/drives/d/root/children', ['status' => 403, 'headers' => [], 'body' => $body]);

		$this->assertSame(
			[
				'status' => 403,
				'items' => [],
				'error' => 'graph_refused',
				'permission' => 'Files.Read.All',
				'graphCode' => 'accessDenied',
				'graphMessage' => 'Either scp or roles claim need to be present',
			],
			$this->adapter->lookup('cred-picked', 'children', ['driveId' => 'd'])
		);

	}//end testAGraphRefusalNamesThePermissionTheStepNeeds()

	/**
	 * A 401 without an error body still reports the permission, with empty Graph fields.
	 *
	 * @return void
	 */
	public function testAGraph401WithoutBodyStillNamesThePermission(): void {
		$this->expectGraphGet('cred-picked', '/v1.0/sites?search=a', ['status' => 401, 'headers' => [], 'body' => '']);

		$result = $this->adapter->lookup('cred-picked', 'sites', ['search' => 'a']);

		$this->assertSame(403, $result['status']);
		$this->assertSame('Sites.Read.All', $result['permission']);
		$this->assertSame('', $result['graphCode']);
		$this->assertSame('', $result['graphMessage']);

	}//end testAGraph401WithoutBodyStillNamesThePermission()

	/**
	 * Any other non-2xx status, or a 2xx without a `value` list, is a Graph error.
	 *
	 * @param array<string, mixed> $answer The broker's answer.
	 *
	 * @return void
	 */
	#[DataProvider('graphErrors')]
	public function testAnUnusableGraphAnswerIsAGraphError(array $answer): void {
		$this->expectGraphGet('cred-picked', '/v1.0/sites/s/drives', $answer);

		$this->assertSame(
			['status' => 502, 'items' => [], 'error' => 'graph_error'],
			$this->adapter->lookup('cred-picked', 'drives', ['siteId' => 's'])
		);

	}//end testAnUnusableGraphAnswerIsAGraphError()

	/**
	 * Graph answers that carry no usable item list.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function graphErrors(): array {
		return [
			'server error' => [['status' => 500, 'headers' => [], 'body' => json_encode(['value' => []])]],
			'redirect' => [['status' => 302, 'headers' => [], 'body' => '']],
			'ok without value' => [['status' => 200, 'headers' => [], 'body' => json_encode(['items' => []])]],
			'ok with scalar value' => [['status' => 200, 'headers' => [], 'body' => json_encode(['value' => 'x'])]],
		];

	}//end graphErrors()

	/**
	 * listDocuments() answers an empty list when Graph fails or answers without a value list.
	 *
	 * @param array<string, mixed>|null $answer The broker's answer.
	 *
	 * @return void
	 */
	#[DataProvider('unusableListings')]
	public function testListDocumentsIsEmptyOnAnUnusableAnswer(?array $answer): void {
		$this->expectGraphGet('cred-default', '/v1.0/sites/site-1/drive/root/children', $answer);

		$this->assertSame([], $this->adapter->listDocuments('site-1'));

	}//end testListDocumentsIsEmptyOnAnUnusableAnswer()

	/**
	 * Listing answers that yield no documents.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function unusableListings(): array {
		return [
			'broker failed' => [null],
			'not found' => [['status' => 404, 'headers' => [], 'body' => '{}']],
			'not json' => [['status' => 200, 'headers' => [], 'body' => 'oops']],
			'value not a list' => [['status' => 200, 'headers' => [], 'body' => json_encode(['value' => 3])]],
		];

	}//end unusableListings()

	/**
	 * list() hands `siteId` to listDocuments() and normalises the folder flag.
	 *
	 * @return void
	 */
	public function testListPassesTheSiteIdFilter(): void {
		$body = json_encode(['value' => [['id' => 'i', 'name' => 'Map', 'folder' => []]]]);
		$this->expectGraphGet('cred-default', '/v1.0/sites/site%202/drive/root/children', ['status' => 200, 'headers' => [], 'body' => $body]);

		$this->assertSame(
			[['id' => 'i', 'name' => 'Map', 'size' => null, 'lastModified' => null, 'webUrl' => null, 'isFolder' => true]],
			$this->adapter->list('register', 'schema', 'object', ['siteId' => 'site 2'])
		);

	}//end testListPassesTheSiteIdFilter()
}//end class
