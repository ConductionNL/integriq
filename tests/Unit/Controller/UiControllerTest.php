<?php

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class UiControllerTest extends TestCase {
	/**
	 * Build a controller with a mocked request.
	 */
	private function controller(): UiController {
		return new UiController('openconnector', $this->createMock(IRequest::class));
	}

	/**
	 * Every SPA route returns the same shell: the `index` template of the
	 * `openconnector` app, with no server-rendered parameters.
	 */
	private function assertSpaShell(TemplateResponse $response, string $route): void {
		$this->assertInstanceOf(TemplateResponse::class, $response, $route . ' should return a TemplateResponse');
		$this->assertSame('openconnector', $response->getApp(), $route . ' should render the openconnector app');
		$this->assertSame('index', $response->getTemplateName(), $route . ' should render the index template');
		$this->assertSame([], $response->getParams(), $route . ' should render no server-side parameters');
	}

	public function testDashboardReturnsTemplateResponse(): void {
		$this->assertSpaShell($this->controller()->dashboard(), 'ui#dashboard');
	}

	/**
	 * Every parameterless SPA list/log route, called by NAME.
	 *
	 * WHY EACH CALL IS WRITTEN OUT rather than looped over a `$methods` array.
	 * This test used to be `foreach ($methods as $m) { $controller->$m(); }`.
	 * A variable method call is invisible to every static reader of this file:
	 * gate-25 (contract-coverage) looks for a literal `->consumers(` before it
	 * will believe the endpoint has a contract test, and it reported
	 * `ui#consumers`, `ui#webhooks`, `ui#cloudEvents`, `ui#cloudEventsEvents`
	 * and `ui#cloudEventsLogs` as untested while this file was in fact calling
	 * all five. The dynamic form also collapses the failure message — a broken
	 * route reported as "line 38" rather than as its own name.
	 *
	 * `testEveryUiRouteInRoutesPhpIsExercisedHere` below keeps this list from
	 * going stale, which is the failure mode that let five routes ship
	 * uncovered in the first place.
	 */
	public function testEveryParameterlessSpaRouteReturnsTheShell(): void {
		$c = $this->controller();

		$this->assertSpaShell($c->sources(), 'ui#sources');
		$this->assertSpaShell($c->sourcesLogs(), 'ui#sourcesLogs');
		$this->assertSpaShell($c->endpoints(), 'ui#endpoints');
		$this->assertSpaShell($c->endpointsLogs(), 'ui#endpointsLogs');
		$this->assertSpaShell($c->consumers(), 'ui#consumers');
		$this->assertSpaShell($c->webhooks(), 'ui#webhooks');
		$this->assertSpaShell($c->jobs(), 'ui#jobs');
		$this->assertSpaShell($c->jobsLogs(), 'ui#jobsLogs');
		$this->assertSpaShell($c->mappings(), 'ui#mappings');
		$this->assertSpaShell($c->rules(), 'ui#rules');
		$this->assertSpaShell($c->synchronizations(), 'ui#synchronizations');
		$this->assertSpaShell($c->synchronizationsContracts(), 'ui#synchronizationsContracts');
		$this->assertSpaShell($c->synchronizationsLogs(), 'ui#synchronizationsLogs');
		$this->assertSpaShell($c->cloudEvents(), 'ui#cloudEvents');
		$this->assertSpaShell($c->cloudEventsEvents(), 'ui#cloudEventsEvents');
		$this->assertSpaShell($c->cloudEventsLogs(), 'ui#cloudEventsLogs');
		$this->assertSpaShell($c->approvals(), 'ui#approvals');
		$this->assertSpaShell($c->catalog(), 'ui#catalog');
		$this->assertSpaShell($c->products(), 'ui#products');
	}

	/**
	 * Every detail route takes an id, and the id is deliberately NOT rendered
	 * server-side — it is handed to the client router by the URL.
	 *
	 * Asserting the template params stay empty is the real contract here: if an
	 * id ever started being interpolated into the shell it would become an
	 * unescaped reflection point, and "returns a TemplateResponse" would not
	 * notice. Each route is therefore driven with a script payload as its id.
	 */
	public function testEveryDetailSpaRouteReturnsTheShellWithoutReflectingTheId(): void {
		$c = $this->controller();
		$payload = '<script>alert(1)</script>';

		$this->assertSpaShell($c->endpointsId($payload), 'ui#endpointsId');
		$this->assertSpaShell($c->consumersId($payload), 'ui#consumersId');
		$this->assertSpaShell($c->mappingsId($payload), 'ui#mappingsId');
		$this->assertSpaShell($c->rulesId($payload), 'ui#rulesId');
		$this->assertSpaShell($c->cloudEventsEventsId($payload), 'ui#cloudEventsEventsId');
		$this->assertSpaShell($c->approvalsId($payload), 'ui#approvalsId');
		$this->assertSpaShell($c->productsId($payload), 'ui#productsId');
	}

	/**
	 * The anti-staleness guard.
	 *
	 * The two tests above name their routes by hand, which is exactly how five
	 * SPA routes came to ship with no assertion at all: `appinfo/routes.php`
	 * grew, the hand-written list did not, and nothing compared them. This test
	 * reads the route table and asserts that every `ui#<method>` route it
	 * declares is called literally somewhere in THIS file.
	 *
	 * It fails the moment a new SPA route is registered without a call here, so
	 * the list cannot silently fall behind the routes again.
	 */
	public function testEveryUiRouteInRoutesPhpIsExercisedHere(): void {
		$routesPath = __DIR__ . '/../../../appinfo/routes.php';
		$this->assertFileExists($routesPath, 'appinfo/routes.php must exist for this guard to mean anything');

		$routes = file_get_contents($routesPath);
		$this->assertNotFalse($routes);

		preg_match_all("/'ui#([A-Za-z0-9_]+)'/", $routes, $matches);
		$declared = array_values(array_unique($matches[1]));
		sort($declared);

		// POSITIVE CONTROL: a guard that finds nothing to check is not a guard.
		$this->assertGreaterThan(
			20,
			count($declared),
			'appinfo/routes.php should declare more than 20 ui# routes — if it does not, this parse is broken and the guard below is vacuous'
		);

		$ownSource = file_get_contents(__FILE__);
		$this->assertNotFalse($ownSource);

		$uncalled = [];
		foreach ($declared as $method) {
			// A literal `->method(` call. The literal form is the point: it is
			// what both a human reader and gate-25 can see.
			if (preg_match('/->' . preg_quote($method, '/') . '\(/', $ownSource) !== 1) {
				$uncalled[] = 'ui#' . $method;
			}
		}

		$this->assertSame(
			[],
			$uncalled,
			'These ui# routes are registered in appinfo/routes.php but never called in UiControllerTest: '
			. implode(', ', $uncalled)
			. '. Add an assertSpaShell() call for each — a registered route with no assertion is an untested endpoint.'
		);
	}
}
