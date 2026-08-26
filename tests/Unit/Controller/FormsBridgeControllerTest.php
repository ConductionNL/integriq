<?php

/**
 * Contract tests for FormsBridgeController's discovery endpoints.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\FormsBridgeController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `forms` and `questions` are `#[NoAdminRequired]` — any authenticated user can
 * reach them — and they read a Source's credentials on the caller's behalf. The
 * order of the checks is therefore the contract: authentication, then the
 * action authorization, and only then the parameters. These tests assert that
 * order by proving the adapter is NEVER touched on a rejected request.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */
class FormsBridgeControllerTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|FormsSyncAdapter
	 */
	private $formsSyncAdapter;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IUserSession
	 */
	private $userSession;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|ActionAuthService
	 */
	private $actionAuth;

	/**
	 * @var FormsBridgeController
	 */
	private FormsBridgeController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->formsSyncAdapter = $this->createMock(FormsSyncAdapter::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->controller = new FormsBridgeController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->formsSyncAdapter,
			$this->createMock(OrObjectService::class),
			$l,
			$this->createMock(LoggerInterface::class),
			$this->userSession,
			$this->actionAuth
		);

	}//end setUp()

	/**
	 * An unauthenticated caller is 401 and the Forms adapter is never asked
	 * anything — the discovery call runs against a Source's credentials, so it
	 * must not begin before the caller is known.
	 *
	 * @return void
	 */
	public function testFormsWithoutAUserIs401AndNeverTouchesTheAdapter(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formsSyncAdapter->expects($this->never())->method('assertEnabled');
		$this->formsSyncAdapter->expects($this->never())->method('listFormsForEditor');

		$response = $this->controller->forms('source-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);

	}//end testFormsWithoutAUserIs401AndNeverTouchesTheAdapter()

	/**
	 * The action authorization runs BEFORE parameter validation, so a caller
	 * without the discover action is stopped even on a request that would have
	 * been rejected as malformed anyway.
	 *
	 * @return void
	 */
	public function testFormsRequiresTheDiscoverActionBeforeValidatingParameters(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($user, 'synchronization.formsBridge.discover');

		$this->formsSyncAdapter->expects($this->never())->method('listFormsForEditor');

		$response = $this->controller->forms(null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('sourceId is required', $response->getData()['error']);

	}//end testFormsRequiresTheDiscoverActionBeforeValidatingParameters()

	/**
	 * An empty-string sourceId is treated as absent, not as a Source named ''.
	 *
	 * @return void
	 */
	public function testFormsRejectsAnEmptyStringSourceId(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->formsSyncAdapter->expects($this->never())->method('listFormsForEditor');

		$response = $this->controller->forms('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('sourceId is required', $response->getData()['error']);

	}//end testFormsRejectsAnEmptyStringSourceId()

	/**
	 * A non-positive formId is rejected before any discovery happens. Forms ids
	 * are positive integers; `0` is what a non-numeric path segment casts to.
	 *
	 * @return void
	 */
	public function testQuestionsRejectsANonPositiveFormId(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->formsSyncAdapter->expects($this->never())->method('listQuestionsForEditor');

		$response = $this->controller->questions(0, 'source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('formId must be numeric', $response->getData()['error']);

	}//end testQuestionsRejectsANonPositiveFormId()

	/**
	 * `questions` checks authentication before the formId, so an
	 * unauthenticated caller gets 401 rather than a validation error that would
	 * disclose which ids the endpoint considers well-formed.
	 *
	 * @return void
	 */
	public function testQuestionsWithoutAUserIs401NotAValidationError(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formsSyncAdapter->expects($this->never())->method('listQuestionsForEditor');

		$response = $this->controller->questions(0, null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);

	}//end testQuestionsWithoutAUserIs401NotAValidationError()
}//end class
