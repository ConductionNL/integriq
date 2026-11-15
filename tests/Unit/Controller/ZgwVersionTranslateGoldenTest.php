<?php

/**
 * Golden tests for ZgwVersionTranslateController::translate(): who may call it,
 * what it asks the translator, and how each failure is answered.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ZgwVersionTranslateController;
use OCA\Integriq\Exception\ZgwLiteralLeakException;
use OCA\Integriq\Exception\ZgwUnknownResourceException;
use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Exception\ZgwVersionNotImplementedException;
use OCA\Integriq\Exception\ZgwVersionTranslationException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ZgwVersionNegotiationService;
use OCA\Integriq\Service\ZgwVersionTranslationServiceInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the allowed and refused outcomes of the translate route.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-rest-surface-for-sibling-apps-and-external-consumers-req-003
 */
class ZgwVersionTranslateGoldenTest extends TestCase {

	/**
	 * @var ZgwVersionTranslationServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $translator;

	/**
	 * @var ActionAuthServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Build the controller for a user (or none) and request parameters.
	 *
	 * @param IUser|null          $user   The session user.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return ZgwVersionTranslateController
	 */
	private function controller(?IUser $user, array $params): ZgwVersionTranslateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturn('');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->translator = $this->createMock(ZgwVersionTranslationServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new ZgwVersionTranslateController(
			'integriq',
			$request,
			$this->translator,
			new ZgwVersionNegotiationService(),
			$session,
			$this->actionAuth,
			$l,
			$this->logger
		);

	}//end controller()

	/**
	 * No session user: 401, and neither the action check nor the translator runs.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefusedBeforeTheActionCheck(): void {
		$controller = $this->controller(null, ['resource' => 'zaak', 'payload' => []]);
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->translator->expects($this->never())->method('translate');

		$response = $controller->translate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnonymousIsRefusedBeforeTheActionCheck()

	/**
	 * A refused action check propagates before the body is read or the translator runs.
	 *
	 * @return void
	 */
	public function testRefusedActionStopsTheTranslation(): void {
		$user = $this->createMock(IUser::class);
		$controller = $this->controller($user, ['resource' => 'zaak', 'payload' => []]);
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($user, 'zgw-version.translate')
			->willThrowException(new RuntimeException('forbidden'));
		$this->translator->expects($this->never())->method('translate');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('forbidden');
		$controller->translate();

	}//end testRefusedActionStopsTheTranslation()

	/**
	 * The fields a request must carry.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function incompleteBodies(): array {
		return [
			'no resource' => [['payload' => []]],
			'empty resource' => [['resource' => '', 'payload' => []]],
			'no payload' => [['resource' => 'zaak']],
			'string payload' => [['resource' => 'zaak', 'payload' => 'x']],
		];

	}//end incompleteBodies()

	/**
	 * An allowed user without resource or array payload gets 400 and no translation.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return void
	 */
	#[DataProvider('incompleteBodies')]
	public function testIncompleteBodyIs400(array $params): void {
		$controller = $this->controller($this->createMock(IUser::class), $params);
		$this->actionAuth->expects($this->once())->method('requireAction');
		$this->translator->expects($this->never())->method('translate');

		$response = $controller->translate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'missing_fields', 'message' => 'The "resource" and "payload" fields are required'],
			$response->getData()
		);

	}//end testIncompleteBodyIs400()

	/**
	 * An allowed user gets the translation of the explicit versions.
	 *
	 * @return void
	 */
	public function testAllowedUserGetsTheTranslation(): void {
		$controller = $this->controller(
			$this->createMock(IUser::class),
			['resource' => 'zaak', 'fromVersion' => '1.0', 'toVersion' => '1.5', 'payload' => ['a' => 1]]
		);
		$this->translator->expects($this->once())->method('translate')
			->with('zaak', '1.0', '1.5', ['a' => 1])
			->willReturn(['b' => 2]);

		$response = $controller->translate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['resource' => 'zaak', 'fromVersion' => '1.0', 'toVersion' => '1.5', 'payload' => ['b' => 2]],
			$response->getData()
		);

	}//end testAllowedUserGetsTheTranslation()

	/**
	 * Each translation failure and its answer.
	 *
	 * @return array<string,array{0:\Exception,1:string,2:int,3:bool}>
	 */
	public static function failures(): array {
		return [
			'unknown resource' => [new ZgwUnknownResourceException('r'), 'unknown_resource', Http::STATUS_BAD_REQUEST, false],
			'unknown version' => [new ZgwUnknownVersionException('v'), 'unknown_version', Http::STATUS_BAD_REQUEST, false],
			'not implemented' => [new ZgwVersionNotImplementedException('n'), 'not_implemented', Http::STATUS_NOT_IMPLEMENTED, false],
			'literal leak' => [new ZgwLiteralLeakException('l'), 'literal_leak', Http::STATUS_UNPROCESSABLE_ENTITY, false],
			'other' => [new ZgwVersionTranslationException('o'), 'translation_failed', Http::STATUS_BAD_REQUEST, true],
		];

	}//end failures()

	/**
	 * A translation failure maps to its error code and status; only the generic one is logged.
	 *
	 * @param \Exception $failure The thrown failure.
	 * @param string     $error   The error code.
	 * @param int        $status  The HTTP status.
	 * @param bool       $logged  Whether a warning is logged.
	 *
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testFailureIsMapped(\Exception $failure, string $error, int $status, bool $logged): void {
		$controller = $this->controller($this->createMock(IUser::class), ['resource' => 'zaak', 'payload' => []]);
		$this->translator->method('translate')->willThrowException($failure);
		$this->logger->expects($logged ? $this->once() : $this->never())->method('warning')
			->with('[ZgwVersionTranslateController] translate failed: ' . $failure->getMessage());

		$response = $controller->translate();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => $error, 'message' => $failure->getMessage()], $response->getData());

	}//end testFailureIsMapped()

	/**
	 * A failure outside the translation family is not caught.
	 *
	 * @return void
	 */
	public function testOtherExceptionsPropagate(): void {
		$controller = $this->controller($this->createMock(IUser::class), ['resource' => 'zaak', 'payload' => []]);
		$this->translator->method('translate')->willThrowException(new RuntimeException('boom'));

		$this->expectException(RuntimeException::class);
		$controller->translate();

	}//end testOtherExceptionsPropagate()
}//end class
