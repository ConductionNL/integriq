<?php

/**
 * Unit tests: the data residency endpoints answer administrators only.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\EgressDestinationController;
use OCA\Integriq\Service\Residency\EgressDestinationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admin gate, refusals and answers of EgressDestinationController.
 */
class EgressDestinationControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var EgressDestinationService&MockObject
	 */
	private EgressDestinationService $destinations;

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->destinations = $this->createMock(EgressDestinationService::class);
	}

	/**
	 * The controller for a signed-in user who is, or is not, an administrator.
	 *
	 * @param bool $admin Whether the user is an administrator.
	 *
	 * @return EgressDestinationController
	 */
	private function controller(bool $admin): EgressDestinationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new EgressDestinationController(
			appName: 'integriq',
			request: $request,
			destinations: $this->destinations,
			userSession: $session,
			groupManager: $groups,
			l10n: $l10n
		);
	}//end controller()

	/**
	 * A non-administrator gets 403 from every endpoint and nothing is read or written.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->destinations->expects($this->never())->method('list');
		$this->destinations->expects($this->never())->method('setEuOnly');
		$this->destinations->expects($this->never())->method('setSourceResidency');
		$controller = $this->controller(admin: false);

		foreach ([$controller->index(), $controller->setEuOnly(), $controller->setSourceResidency(id: 's1')] as $response) {
			$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
			$this->assertSame(expected: ['error' => 'Only an administrator can manage data residency.'], actual: $response->getData());
		}
	}//end testANonAdminIsRefused()

	/**
	 * Without a signed-in user the list is refused too.
	 *
	 * @return void
	 */
	public function testNoUserIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new EgressDestinationController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			destinations: $this->destinations,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->index()->getStatus());
	}//end testNoUserIsRefused()

	/**
	 * An administrator reads the list as the service gives it.
	 *
	 * @return void
	 */
	public function testAnAdminReadsTheList(): void {
		$list = ['destinations' => [], 'verdict' => 'eu-only', 'counts' => ['eu' => 0, 'eea' => 0, 'outside' => 0, 'unknown' => 0], 'euOnly' => false];
		$this->destinations->method('list')->willReturn($list);

		$response = $this->controller(admin: true)->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $list, actual: $response->getData());
	}//end testAnAdminReadsTheList()

	/**
	 * Switching on over a gap answers 409 naming the blocking destinations.
	 *
	 * @return void
	 */
	public function testSwitchingOnOverAGapIsAConflict(): void {
		$this->params = ['enabled' => 'true'];
		$blocking = [['name' => 'zgw-zaken', 'host' => 'zaken.example.org'], ['name' => '', 'host' => 'api.example.com']];
		$this->destinations->expects($this->once())->method('setEuOnly')->with(true)
			->willReturn(['euOnly' => false, 'blocking' => $blocking]);

		$response = $this->controller(admin: true)->setEuOnly();

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(
			expected: 'EU only mode stays off. These destinations are not shown to be in the EU: zgw-zaken, api.example.com',
			actual: $response->getData()['error']
		);
		$this->assertSame(expected: $blocking, actual: $response->getData()['blocking']);
	}//end testSwitchingOnOverAGapIsAConflict()

	/**
	 * Switching off is stored and answered.
	 *
	 * @return void
	 */
	public function testSwitchingOffIsStored(): void {
		$this->params = ['enabled' => false];
		$this->destinations->expects($this->once())->method('setEuOnly')->with(false)
			->willReturn(['euOnly' => false, 'blocking' => []]);

		$this->assertSame(expected: ['euOnly' => false], actual: $this->controller(admin: true)->setEuOnly()->getData());
	}//end testSwitchingOffIsStored()

	/**
	 * An administrator sets residency; the country is normalised to upper case.
	 *
	 * @return void
	 */
	public function testAnAdminSetsResidency(): void {
		$this->params = ['country' => ' nl ', 'evidence' => ' verwerkersovereenkomst '];
		$stored = ['country' => 'NL', 'basis' => 'set-by-admin'];
		$this->destinations->expects($this->once())->method('setSourceResidency')
			->with('s1', 'NL', 'verwerkersovereenkomst', 'alice')
			->willReturn($stored);

		$response = $this->controller(admin: true)->setSourceResidency(id: 's1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['residency' => $stored], actual: $response->getData());
	}//end testAnAdminSetsResidency()

	/**
	 * An invalid country answers 400 and a missing source 404.
	 *
	 * @return void
	 */
	public function testAnInvalidCountryAndAMissingSource(): void {
		$this->params = ['country' => 'XX'];
		$this->destinations->method('setSourceResidency')->willReturnCallback(
			static function (string $sourceId): ?array {
				if ($sourceId === 'gone') {
					return null;
				}

				throw new InvalidArgumentException('bad country');
			}
		);
		$controller = $this->controller(admin: true);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $controller->setSourceResidency(id: 's1')->getStatus());
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->setSourceResidency(id: 'gone')->getStatus());
	}//end testAnInvalidCountryAndAMissingSource()
}//end class
