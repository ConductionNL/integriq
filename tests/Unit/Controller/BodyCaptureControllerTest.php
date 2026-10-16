<?php

/**
 * Unit tests for BodyCaptureController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Integriq\Controller\BodyCaptureController;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionMethod;

/**
 * Opening and closing a source's investigation window.
 */
class BodyCaptureControllerTest extends TestCase {

	/**
	 * The source uuid.
	 *
	 * @var string
	 */
	private const SOURCE = '7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f';

	/**
	 * Every save handed to ObjectService::saveObject(), with its named arguments.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saves = [];

	/**
	 * The stored source.
	 *
	 * @var array<string,mixed>
	 */
	private array $source = [];

	/**
	 * Whether the save throws.
	 *
	 * @var bool
	 */
	private bool $failSave = false;

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param bool $admin Whether the principal is an administrator.
	 *
	 * @return BodyCaptureController
	 */
	private function controller(array $params, bool $admin = true): BodyCaptureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('beheerder')->willReturn($admin);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->source);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null, $_rbac = true, $_multitenancy = true, $silent = false) {
				if ($this->failSave === true) {
					throw new \RuntimeException('database unavailable');
				}

				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'silent' => $silent];
				$this->source = $object;
				$entity = new ObjectEntity();
				$entity->setUuid((string)$uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-09T12:00:00+00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []) => vsprintf(str_replace('%1$s', '%s', $text), $parameters)
		);

		return new BodyCaptureController(
			'integriq',
			$request,
			$session,
			$groups,
			$objectService,
			new BodyCapturePolicy($appConfig, $clock),
			$l10n
		);
	}//end controller()

	/**
	 * Set up a source without a window.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saves = [];
		$this->source = ['name' => 'zgw-zaken', 'location' => 'https://zaken.example.invalid', 'isEnabled' => true];
	}//end setUp()

	/**
	 * An administrator opens a window of 24 hours with a reason, through the audited save.
	 *
	 * @return void
	 */
	public function testAnAdministratorOpensAWindowWithAReason(): void {
		$response = $this->controller(['hours' => 24, 'reason' => 'melding 4711: verkeerde zaaktypen'])->open(self::SOURCE);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->saves);
		$saved = $this->saves[0];
		$this->assertSame('source', $saved['schema']);
		$this->assertSame(self::SOURCE, $saved['uuid']);
		// Not silent: OpenRegister's audit trail entry records the principal and the reason.
		$this->assertFalse($saved['silent']);
		$this->assertSame('2026-10-10T12:00:00+00:00', $saved['object']['bodyCaptureUntil']);
		$this->assertSame('melding 4711: verkeerde zaaktypen', $saved['object']['bodyCaptureReason']);
		$this->assertSame('beheerder', $saved['object']['bodyCaptureBy']);
		$this->assertSame('zgw-zaken', $saved['object']['name']);
		$this->assertSame('2026-10-10T12:00:00+00:00', $response->getData()['bodyCaptureUntil']);
		$this->assertSame([], RegisterSchemaValidator::errors('source', $saved['object']), 'the register accepts the window fields');
	}//end testAnAdministratorOpensAWindowWithAReason()

	/**
	 * More hours than the maximum is refused with the maximum in the message, not shortened.
	 *
	 * @return void
	 */
	public function testMoreHoursThanTheMaximumIsRefused(): void {
		$response = $this->controller(['hours' => 200, 'reason' => 'onderzoek'])->open(self::SOURCE);

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('72', $response->getData()['error']);
		$this->assertSame(72, $response->getData()['maxHours']);
		$this->assertSame([], $this->saves);
	}//end testMoreHoursThanTheMaximumIsRefused()

	/**
	 * An empty reason, zero hours or hours that are not a number are refused.
	 *
	 * @return void
	 */
	public function testAnEmptyReasonOrNoHoursIsRefused(): void {
		$this->assertSame(400, $this->controller(['hours' => 24, 'reason' => '  '])->open(self::SOURCE)->getStatus());
		$this->assertSame(400, $this->controller(['hours' => 0, 'reason' => 'onderzoek'])->open(self::SOURCE)->getStatus());
		$this->assertSame(400, $this->controller(['hours' => '1.5', 'reason' => 'onderzoek'])->open(self::SOURCE)->getStatus());
		$this->assertSame(400, $this->controller(['reason' => 'onderzoek'])->open(self::SOURCE)->getStatus());
		$this->assertSame([], $this->saves);
	}//end testAnEmptyReasonOrNoHoursIsRefused()

	/**
	 * A principal who is not an administrator cannot open or close a window.
	 *
	 * @return void
	 */
	public function testAReaderCannotOpenAWindow(): void {
		$controller = $this->controller(['hours' => 24, 'reason' => 'onderzoek'], false);

		$this->assertSame(403, $controller->open(self::SOURCE)->getStatus());
		$this->assertSame(403, $controller->close(self::SOURCE)->getStatus());
		$this->assertSame([], $this->saves);
		$this->assertArrayNotHasKey('bodyCaptureUntil', $this->source);
	}//end testAReaderCannotOpenAWindow()

	/**
	 * Closing early sets the end to now through the audited save.
	 *
	 * @return void
	 */
	public function testClosingEarlyIsAudited(): void {
		$this->source['bodyCaptureUntil'] = '2026-10-10T08:00:00+00:00';
		$this->source['bodyCaptureReason'] = 'onderzoek';
		$this->source['bodyCaptureBy'] = 'collega';

		$response = $this->controller([])->close(self::SOURCE);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->saves);
		$this->assertFalse($this->saves[0]['silent']);
		$this->assertSame('2026-10-09T12:00:00+00:00', $this->saves[0]['object']['bodyCaptureUntil']);
		$this->assertSame('beheerder', $this->saves[0]['object']['bodyCaptureBy']);
	}//end testClosingEarlyIsAudited()

	/**
	 * Closing a source without an open window changes nothing.
	 *
	 * @return void
	 */
	public function testClosingWithoutAWindowChangesNothing(): void {
		$this->source['bodyCaptureUntil'] = '2026-10-09T11:00:00+00:00';

		$this->assertSame(409, $this->controller([])->close(self::SOURCE)->getStatus());
		$this->assertSame([], $this->saves);
	}//end testClosingWithoutAWindowChangesNothing()

	/**
	 * A save that fails answers 500 and claims nothing.
	 *
	 * @return void
	 */
	public function testAFailedSaveAnswersAnError(): void {
		$this->failSave = true;

		$response = $this->controller(['hours' => 24, 'reason' => 'onderzoek'])->open(self::SOURCE);

		$this->assertSame(500, $response->getStatus());
		$this->assertArrayNotHasKey('bodyCaptureUntil', $response->getData());
	}//end testAFailedSaveAnswersAnError()

	/**
	 * Both actions carry the admin setting attribute and never NoAdminRequired.
	 *
	 * @return void
	 */
	public function testBothActionsAreAdminOnlyOnTheRoute(): void {
		foreach (['open', 'close'] as $method) {
			$reflection = new ReflectionMethod(BodyCaptureController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method);
			$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class), $method);
		}
	}//end testBothActionsAreAdminOnlyOnTheRoute()

	/**
	 * Both routes resolve to existing controller methods.
	 *
	 * @return void
	 */
	public function testBothRoutesResolveToTheController(): void {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$found = [];
		foreach ($routes['routes'] as $route) {
			if ($route['url'] === '/api/sources/{id}/body-capture') {
				$found[$route['verb']] = $route['name'];
			}
		}

		$this->assertSame(['POST' => 'bodyCapture#open', 'DELETE' => 'bodyCapture#close'], $found);
		$this->assertTrue(method_exists(BodyCaptureController::class, 'open'));
		$this->assertTrue(method_exists(BodyCaptureController::class, 'close'));
	}//end testBothRoutesResolveToTheController()
}//end class
