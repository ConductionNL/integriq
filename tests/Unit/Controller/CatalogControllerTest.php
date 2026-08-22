<?php

/**
 * Unit tests for CatalogController (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CatalogController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the status + instantiate endpoints' auth gating, mechanism
 * dispatch and idempotency guards.
 */
class CatalogControllerTest extends TestCase {
	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appConfig;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @param bool $isAdmin Whether the session user passes the admin break-glass.
	 * @param bool $authenticated Whether a session user exists at all.
	 *
	 * @return CatalogController
	 */
	private function makeController(bool $isAdmin = true, bool $authenticated = true): CatalogController {
		$user = null;
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('tester');
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$authConfig = $this->createMock(IAppConfig::class);
		$authConfig->method('getValueString')->willReturn('{}');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$actionAuth = new ActionAuthService($authConfig, $groupManager);

		$registryService = new CatalogRegistryService(
			new \OCA\OpenRegister\Service\Integration\IntegrationRegistry(),
			$this->orObjectService,
			$this->appConfig,
			new NullLogger()
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CatalogController(
			'openconnector',
			$this->createMock(IRequest::class),
			$registryService,
			$this->orObjectService,
			$this->appConfig,
			$l10n,
			$userSession,
			$actionAuth
		);
	}//end makeController()

	/**
	 * status() reflects the LIVE flag value for a flag-gated item.
	 *
	 * @return void
	 */
	public function testStatusReflectsLiveFlagValue(): void {
		$item = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'adapter:pdok',
				'mechanism' => 'flag-gated',
				'flagKey' => 'pdok.feature_flag',
			],
			'item-uuid'
		);
		$this->orObjectService->method('find')->willReturn($item);
		$this->appConfig->method('getValueString')->willReturn('1');

		$response = $this->makeController()->status('item-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('available', $data['status']);
		$this->assertSame('flag-gated', $data['mechanism']);
		$this->assertSame('pdok.feature_flag', $data['flagKey']);
	}//end testStatusReflectsLiveFlagValue()

	/**
	 * status() returns 404 for an unknown catalog item.
	 *
	 * @return void
	 */
	public function testStatusUnknownItemReturns404(): void {
		$this->orObjectService->method('find')
			->willThrowException(new DoesNotExistException('nope'));

		$response = $this->makeController()->status('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testStatusUnknownItemReturns404()

	/**
	 * REQ-002 scenario: a non-admin whose groups are not mapped to
	 * catalog.instantiate is rejected with 403 BEFORE any write.
	 *
	 * @return void
	 */
	public function testInstantiateDeniedForUnmappedNonAdmin(): void {
		$this->orObjectService->expects($this->never())->method('saveObject');
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->makeController(isAdmin: false)->instantiate('item-uuid');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testInstantiateDeniedForUnmappedNonAdmin()

	/**
	 * REQ-002 scenario: enabling a flag-gated item writes '1' to its flag.
	 *
	 * @return void
	 */
	public function testInstantiateFlagGatedFlipsFlag(): void {
		$item = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'adapter:pdok',
				'mechanism' => 'flag-gated',
				'flagKey' => 'pdok.feature_flag',
			],
			'item-uuid'
		);
		$this->orObjectService->method('find')->willReturn($item);
		$this->appConfig->method('getValueString')->willReturn('0');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openconnector', 'pdok.feature_flag', '1');

		$response = $this->makeController()->instantiate('item-uuid');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('flag', $data['type']);
		$this->assertSame('enabled', $data['action']);
	}//end testInstantiateFlagGatedFlipsFlag()

	/**
	 * Idempotency guard: an already-enabled flag returns 409, no write.
	 *
	 * @return void
	 */
	public function testInstantiateFlagAlreadyEnabledReturns409(): void {
		$item = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'adapter:pdok',
				'mechanism' => 'flag-gated',
				'flagKey' => 'pdok.feature_flag',
			],
			'item-uuid'
		);
		$this->orObjectService->method('find')->willReturn($item);
		$this->appConfig->method('getValueString')->willReturn('1');
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->makeController()->instantiate('item-uuid');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testInstantiateFlagAlreadyEnabledReturns409()

	/**
	 * REQ-002 scenario: instantiating a mock-seeded item whose Source exists
	 * but is disabled enables it in place (no duplicate creation).
	 *
	 * @return void
	 */
	public function testInstantiateMockSeededEnablesExistingSource(): void {
		$item = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'source-template:xwiki',
				'mechanism' => 'mock-seeded',
				'sourceTemplateSlug' => 'xwiki',
			],
			'item-uuid'
		);
		$existingSource = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'xwiki',
				'isEnabled' => false,
			],
			'source-uuid'
		);
		$savedSource = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'xwiki',
				'isEnabled' => true,
			],
			'source-uuid'
		);

		$this->orObjectService->method('find')->willReturn($item);
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$existingSource], 'total' => 1]);
		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register = null, $schema = null, $uuid = null) use ($savedSource) {
					$this->assertTrue($object['isEnabled']);
					$this->assertSame('source', $schema);
					$this->assertSame('source-uuid', $uuid);
					return $savedSource;
				}
			);

		$response = $this->makeController()->instantiate('item-uuid');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('source', $data['type']);
		$this->assertSame('source-uuid', $data['id']);
		$this->assertFalse($data['created']);
	}//end testInstantiateMockSeededEnablesExistingSource()

	/**
	 * Instantiating a mock-seeded item with NO live Source creates one from
	 * the real register.d seed payload.
	 *
	 * @return void
	 */
	public function testInstantiateMockSeededCreatesFromSeedTemplate(): void {
		$item = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'source-template:brp-haalcentraal',
				'mechanism' => 'mock-seeded',
				'sourceTemplateSlug' => 'brp-haalcentraal',
			],
			'item-uuid'
		);
		$createdSource = ObjectServiceMockBuilder::objectEntity(
			$this,
			['slug' => 'brp-haalcentraal', 'isEnabled' => true],
			'new-source-uuid'
		);

		$this->orObjectService->method('find')->willReturn($item);
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);
		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register = null, $schema = null, $uuid = null) use ($createdSource) {
					// The payload is the REAL seed fragment body.
					$this->assertSame('brp-haalcentraal', $object['slug']);
					$this->assertSame('BRP HaalCentraal Personen', $object['name']);
					$this->assertTrue($object['isEnabled']);
					$this->assertSame('source', $schema);
					$this->assertNull($uuid);
					return $createdSource;
				}
			);

		$response = $this->makeController()->instantiate('item-uuid');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['created']);
		$this->assertSame('new-source-uuid', $data['id']);
	}//end testInstantiateMockSeededCreatesFromSeedTemplate()

	/**
	 * Unauthenticated requests get 401 on both endpoints.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$controller = $this->makeController(authenticated: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->status('x')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->instantiate('x')->getStatus());
	}//end testUnauthenticatedReturns401()
}//end class
