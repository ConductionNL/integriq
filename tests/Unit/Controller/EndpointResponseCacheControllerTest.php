<?php

/**
 * EndpointResponseCacheController unit tests.
 *
 * Clearing an endpoint's response cache needs a signed-in user holding the
 * `endpoint.cache.clear` action and an endpoint that exists; only then is the
 * endpoint's cache cleared.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EndpointResponseCacheController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Endpoint\EndpointResponseCache;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may clear an endpoint's cache, and what is cleared.
 */
#[CoversClass(EndpointResponseCacheController::class)]
class EndpointResponseCacheControllerTest extends TestCase {

	/**
	 * The response cache double.
	 *
	 * @var EndpointResponseCache&MockObject
	 */
	private $cache;

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $objects;

	/**
	 * The doubles every case shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = $this->createMock(EndpointResponseCache::class);
		$this->objects = ObjectServiceMockBuilder::make($this);
	}//end setUp()

	/**
	 * The controller for a principal.
	 *
	 * @param bool $signedIn Whether someone is signed in.
	 * @param bool $mayClear Whether they hold the clear action.
	 *
	 * @return EndpointResponseCacheController
	 */
	private function controller(bool $signedIn, bool $mayClear): EndpointResponseCacheController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('beheerder');
		}

		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('can')->willReturnCallback(
			static fn (IUser $who, string $action): bool => ($action === EndpointResponseCacheController::ACTION_CLEAR && $mayClear)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new EndpointResponseCacheController('integriq', $this->createMock(IRequest::class), $session, $auth, $this->objects, $this->cache, $l10n);
	}//end controller()

	/**
	 * An anonymous caller is refused and nothing is cleared.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->cache->expects($this->never())->method('clear');

		$response = $this->controller(signedIn: false, mayClear: true)->clear('endpoint-1');

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A user without the clear action is refused and nothing is cleared.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionIsRefused(): void {
		$this->cache->expects($this->never())->method('clear');
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller(signedIn: true, mayClear: false)->clear('endpoint-1');

		$this->assertSame(403, $response->getStatus());
	}//end testAUserWithoutTheActionIsRefused()

	/**
	 * An endpoint that cannot be found is 404 and nothing is cleared.
	 *
	 * @return void
	 */
	public function testAnUnknownEndpointIsNotFound(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('Object not found'));
		$this->cache->expects($this->never())->method('clear');

		$response = $this->controller(signedIn: true, mayClear: true)->clear('nope');

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnknownEndpointIsNotFound()

	/**
	 * A permitted clear empties that endpoint's cache, read within the caller's organisation.
	 *
	 * @return void
	 */
	public function testAPermittedClearEmptiesThatEndpointsCache(): void {
		$this->objects->expects($this->once())->method('find')
			->with('endpoint-slug', 'integriq', 'endpoint', false)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Adressen'], 'endpoint-uuid'));
		$this->cache->expects($this->once())->method('clear')->with('endpoint-uuid');

		$response = $this->controller(signedIn: true, mayClear: true)->clear('endpoint-slug');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['endpoint' => 'endpoint-uuid', 'cleared' => true], $response->getData());
	}//end testAPermittedClearEmptiesThatEndpointsCache()
}//end class
