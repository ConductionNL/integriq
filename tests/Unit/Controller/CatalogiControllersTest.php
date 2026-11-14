<?php

/**
 * CatalogiApiController and CatalogiAdminController unit tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CatalogiAdminController;
use OCA\Integriq\Controller\CatalogiApiController;
use OCA\Integriq\Service\Catalogi\CatalogusEndpointHandler;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use OCA\Integriq\Service\Catalogi\WooStarterSet;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Catalogi routes hand the request to their handlers; the admin route refuses non-admins.
 */
#[CoversClass(CatalogiApiController::class)]
#[CoversClass(CatalogiAdminController::class)]
class CatalogiControllersTest extends TestCase {

	/**
	 * The API routes pass the header, the served base URL and the query to the handlers.
	 *
	 * @return void
	 */
	public function testTheApiRoutesHandTheRequestToTheHandlers(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer t');
		$request->method('getParam')->willReturnCallback(static fn (string $name): ?string => ['status' => 'alles', 'catalogus' => 'c'][$name] ?? null);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->with('integriq.catalogiApi.catalogussen')->willReturn('https://h/index.php/apps/integriq/catalogi/api/v1/catalogussen');

		$types = $this->createMock(InformatieobjecttypeEndpointHandler::class);
		$types->expects(self::once())->method('index')
			->with('Bearer t', 'https://h/index.php/apps/integriq/catalogi/api/v1', ['catalogus' => 'c', 'status' => 'alles'])
			->willReturn(['status' => 200, 'body' => ['count' => 0]]);
		$types->expects(self::once())->method('show')
			->with('Bearer t', 'type-1', 'https://h/index.php/apps/integriq/catalogi/api/v1', 'alles')
			->willReturn(['status' => 404, 'body' => ['code' => 'not-found']]);
		$types->method('write')->willReturn(['status' => 405, 'body' => ['code' => 'method-not-allowed']]);
		$catalogues = $this->createMock(CatalogusEndpointHandler::class);
		$catalogues->method('index')->willReturn(['status' => 403, 'body' => ['code' => 'permission-denied']]);
		$catalogues->method('show')->with('Bearer t', 'cat-1')->willReturn(['status' => 200, 'body' => ['naam' => 'X']]);

		$controller = new CatalogiApiController(request: $request, urls: $urls, catalogues: $catalogues, types: $types);

		self::assertSame(200, $controller->informatieobjecttypen()->getStatus());
		self::assertSame(['code' => 'not-found'], $controller->informatieobjecttype(uuid: 'type-1')->getData());
		self::assertSame(405, $controller->write()->getStatus());
		self::assertSame(403, $controller->catalogussen()->getStatus());
		self::assertSame(['naam' => 'X'], $controller->catalogus(uuid: 'cat-1')->getData());
	}//end testTheApiRoutesHandTheRequestToTheHandlers()

	/**
	 * The admin controller over a caller.
	 *
	 * @param string|null   $uid     The caller, or none.
	 * @param bool          $isAdmin Whether the caller is an administrator.
	 * @param WooStarterSet $set     The starter set.
	 *
	 * @return CatalogiAdminController
	 */
	private function admin(?string $uid, bool $isAdmin, WooStarterSet $set): CatalogiAdminController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new CatalogiAdminController(request: $this->createMock(IRequest::class), userSession: $session, groupManager: $groups, starterSet: $set);
	}//end admin()

	/**
	 * A non-administrator, or nobody, is refused and nothing runs.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$set = $this->createMock(WooStarterSet::class);
		$set->expects(self::never())->method('run');

		self::assertSame(403, $this->admin(uid: 'jansen', isAdmin: false, set: $set)->wooStarterSet()->getStatus());
		self::assertSame(401, $this->admin(uid: null, isAdmin: false, set: $set)->wooStarterSet()->getStatus());
	}//end testANonAdminIsRefused()

	/**
	 * An administrator runs the set and gets its counts.
	 *
	 * @return void
	 */
	public function testAnAdministratorRunsTheSet(): void {
		$set = $this->createMock(WooStarterSet::class);
		$set->expects(self::once())->method('run')->willReturn(['status' => 200, 'body' => ['created' => 16, 'skipped' => 1]]);

		$response = $this->admin(uid: 'admin', isAdmin: true, set: $set)->wooStarterSet();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['created' => 16, 'skipped' => 1], $response->getData());
	}//end testAnAdministratorRunsTheSet()

}//end class
