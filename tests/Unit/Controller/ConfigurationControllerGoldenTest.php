<?php

/**
 * Golden tests for ConfigurationController: who may export, preview and import,
 * where the document comes from, and how every failure is answered.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#scenario-import-without-confirmation-is-rejected
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ConfigurationController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the allowed and refused outcomes of the four configuration routes.
 */
class ConfigurationControllerGoldenTest extends TestCase {

	/**
	 * @var ConfigurationServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $configService;

	/**
	 * @var ConfigurationImportPreviewServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $previewService;

	/**
	 * @var ActionAuthServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * Actions the gate was asked for.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed>      $params    Request parameters.
	 * @param bool                     $user      Whether a user is logged in.
	 * @param string|null              $forbidden An action the gate refuses, or null.
	 * @param array<string,mixed>|null $upload    The uploaded `file`, or null.
	 *
	 * @return ConfigurationController
	 */
	private function controller(array $params = [], bool $user = true, ?string $forbidden = null, ?array $upload = null): ConfigurationController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getUploadedFile')->willReturn($upload);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));
		$this->configService = $this->createMock(ConfigurationServiceInterface::class);
		$this->previewService = $this->createMock(ConfigurationImportPreviewServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->actionAuth->method('requireAction')->willReturnCallback(
			function (IUser $who, string $action) use ($forbidden): void {
				$this->asked[] = $action;
				if ($action === $forbidden) {
					throw new OCSForbiddenException('no ' . $action);
				}
			}
		);

		return new ConfigurationController('integriq', $request, $this->configService, $this->previewService, $l10n, $session, $this->actionAuth);

	}//end controller()

	/**
	 * The Content-Disposition header, read from the raw headers (getHeaders() needs the server).
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return string
	 */
	private function disposition(\OCP\AppFramework\Http\Response $response): string {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');

		return $property->getValue($response)['Content-Disposition'];

	}//end disposition()

	/**
	 * Each route and the action it is gated on.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function routes(): array {
		return [
			'export' => ['export', 'configuration.export'],
			'export register' => ['exportRegister', 'configuration.export'],
			'preview' => ['previewImport', 'configuration.import'],
			'import' => ['import', 'configuration.import'],
		];

	}//end routes()

	/**
	 * Call a route.
	 *
	 * @param ConfigurationController $controller The controller.
	 * @param string                  $route      The route method.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function call(ConfigurationController $controller, string $route) {
		if ($route === 'export' || $route === 'exportRegister') {
			return $controller->$route('g 1');
		}

		return $controller->$route();

	}//end call()

	/**
	 * Without a user every route is 401 and nothing is asked or touched.
	 *
	 * @param string $route  The route method.
	 * @param string $action The gated action.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testAnonymousIsRefused(string $route, string $action): void {
		$controller = $this->controller(['confirmed' => true, 'document' => ['openapi' => '3']], false);
		$this->configService->expects($this->never())->method($this->anything());
		$this->previewService->expects($this->never())->method($this->anything());

		$response = $this->call($controller, $route);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		$this->assertSame([], $this->asked);

	}//end testAnonymousIsRefused()

	/**
	 * A refused action is 403 with the gate's message, before anything else runs.
	 *
	 * @param string $route  The route method.
	 * @param string $action The gated action.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testRefusedActionIsForbidden(string $route, string $action): void {
		$controller = $this->controller(['confirmed' => true, 'document' => ['openapi' => '3']], true, $action);
		$this->configService->expects($this->never())->method($this->anything());
		$this->previewService->expects($this->never())->method($this->anything());

		$response = $this->call($controller, $route);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'no ' . $action], $response->getData());
		$this->assertSame([$action], $this->asked);

	}//end testRefusedActionIsForbidden()

	/**
	 * Both exports answer an attachment, or 500 when the service fails.
	 *
	 * @return void
	 */
	public function testExportsAnswerAttachmentsOrFailures(): void {
		$controller = $this->controller();
		$this->configService->method('exportConfiguration')->with('g 1')->willReturn(['openapi' => 'c']);
		$this->configService->method('exportRegister')->with('g 1')->willThrowException(new RuntimeException('gone'));

		$export = $controller->export('g 1');
		$this->assertSame(Http::STATUS_OK, $export->getStatus());
		$this->assertSame(['openapi' => 'c'], $export->getData());
		$this->assertSame('attachment; filename="configuration-g%201.json"', $this->disposition($export));

		$register = $controller->exportRegister('g 1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $register->getStatus());
		$this->assertSame(['error' => 'Export failed: gone'], $register->getData());


	}//end testExportsAnswerAttachmentsOrFailures()

	/**
	 * The other way round: the configuration export fails, the register export answers.
	 *
	 * @return void
	 */
	public function testExportFailureAndRegisterAttachment(): void {
		$controller = $this->controller();
		$this->configService->method('exportConfiguration')->willThrowException(new RuntimeException('x'));
		$this->configService->method('exportRegister')->willReturn(['b' => 1]);

		$this->assertSame(['error' => 'Export failed: x'], $controller->export('a')->getData());
		$this->assertSame('attachment; filename="register-a.json"', $this->disposition($controller->exportRegister('a')));

	}//end testExportFailureAndRegisterAttachment()

	/**
	 * Where the document comes from, for the preview route.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>|null,2:array<string,mixed>|null}>
	 */
	public static function documents(): array {
		$file = tempnam(sys_get_temp_dir(), 'cfg');
		file_put_contents($file, '{"from":"file"}');
		$bad = tempnam(sys_get_temp_dir(), 'cfg');
		file_put_contents($bad, 'not json');
		return [
			'array param' => [['document' => ['a' => 1]], null, ['a' => 1]],
			'json string param' => [['document' => '{"b":2}'], null, ['b' => 2]],
			'bad json string param, file ignored' => [['document' => 'nope'], ['tmp_name' => $file], null],
			'empty string falls to the file' => [['document' => ''], ['tmp_name' => $file], ['from' => 'file']],
			'no param, file' => [[], ['tmp_name' => $file], ['from' => 'file']],
			'file without json' => [[], ['tmp_name' => $bad], null],
			'file without tmp name' => [[], ['name' => 'x'], null],
			'file with non-string tmp name' => [[], ['tmp_name' => 5], null],
			'number param, no file' => [['document' => 5], null, null],
		];

	}//end documents()

	/**
	 * The preview reads the document from the param or the upload, else answers 400.
	 *
	 * @param array<string,mixed>      $params   Request parameters.
	 * @param array<string,mixed>|null $upload   The uploaded file.
	 * @param array<string,mixed>|null $expected The document the preview gets, or null for 400.
	 *
	 * @return void
	 */
	#[DataProvider('documents')]
	public function testPreviewDocumentSource(array $params, ?array $upload, ?array $expected): void {
		$controller = $this->controller($params, true, null, $upload);
		if ($expected === null) {
			$this->previewService->expects($this->never())->method('preview');
		} else {
			$this->previewService->expects($this->once())->method('preview')->with($expected)->willReturn(['creates' => []]);
		}

		$response = $controller->previewImport();

		if ($expected === null) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'Request must carry a JSON configuration document under "document"'], $response->getData());
			return;
		}

		$this->assertSame(['creates' => []], $response->getData());

	}//end testPreviewDocumentSource()

	/**
	 * Preview failures: an invalid document is 400 with its message, anything else 500.
	 *
	 * @return void
	 */
	public function testPreviewFailures(): void {
		$controller = $this->controller(['document' => ['a' => 1]]);
		$this->previewService->method('preview')->willReturnOnConsecutiveCalls(
			$this->throwException(new \InvalidArgumentException('bad oas')),
			$this->throwException(new RuntimeException('db'))
		);

		$first = $controller->previewImport();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $first->getStatus());
		$this->assertSame(['error' => 'bad oas'], $first->getData());

		$second = $controller->previewImport();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $second->getStatus());
		$this->assertSame(['error' => 'Preview failed: db'], $second->getData());

	}//end testPreviewFailures()

	/**
	 * Each import outcome after the gate.
	 *
	 * @return array<string,array{0:mixed,1:\Throwable|null,2:\Throwable|null,3:int,4:array<string,mixed>}>
	 */
	public static function imports(): array {
		return [
			'not confirmed' => [null, null, null, Http::STATUS_BAD_REQUEST, ['error' => 'Import requires explicit confirmation — preview first, then send confirmed: true']],
			'confirmed false' => [false, null, null, Http::STATUS_BAD_REQUEST, ['error' => 'Import requires explicit confirmation — preview first, then send confirmed: true']],
			'confirmed 1' => [1, null, null, Http::STATUS_BAD_REQUEST, ['error' => 'Import requires explicit confirmation — preview first, then send confirmed: true']],
			'preview invalid' => [true, new \InvalidArgumentException('bad'), null, Http::STATUS_BAD_REQUEST, ['error' => 'bad']],
			'preview failed' => ['true', new RuntimeException('down'), null, Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Preview failed: down']],
			'import invalid' => [true, null, new \InvalidArgumentException('ref'), Http::STATUS_BAD_REQUEST, ['error' => 'ref']],
			'import failed' => [true, null, new RuntimeException('disk'), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Import failed: disk']],
			'imported' => [true, null, null, Http::STATUS_OK, ['creates' => ['x'], 'written' => ['source' => ['s1', 's2'], 'rule' => [0]]]],
		];

	}//end imports()

	/**
	 * The import answers each outcome as before, previewing before it writes.
	 *
	 * @param mixed           $confirmed     The confirmed param.
	 * @param \Throwable|null $previewFails  What the preview throws.
	 * @param \Throwable|null $importFails   What the import throws.
	 * @param int             $status        The answered status.
	 * @param array<string,mixed> $data      The answered data.
	 *
	 * @return void
	 */
	#[DataProvider('imports')]
	public function testImportOutcome(mixed $confirmed, ?\Throwable $previewFails, ?\Throwable $importFails, int $status, array $data): void {
		$calls = [];
		$controller = $this->controller(['confirmed' => $confirmed, 'document' => ['d' => 1]]);
		$preview = $this->previewService->method('preview')->with(['d' => 1]);
		if ($previewFails !== null) {
			$preview->willThrowException($previewFails);
		} else {
			$preview->willReturnCallback(
				function () use (&$calls): array {
					$calls[] = 'preview';
					return ['creates' => ['x']];
				}
			);
		}

		$import = $this->configService->method('importConfiguration')->with(['d' => 1]);
		if ($importFails !== null) {
			$import->willThrowException($importFails);
		} else {
			$import->willReturnCallback(
				function () use (&$calls): array {
					$calls[] = 'import';
					return ['source' => ['s1' => 1, 's2' => 2], 'rule' => 'r'];
				}
			);
		}

		$response = $controller->import();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($data, $response->getData());
		if ($status === Http::STATUS_OK) {
			$this->assertSame(['preview', 'import'], $calls);
		}

	}//end testImportOutcome()
}//end class
