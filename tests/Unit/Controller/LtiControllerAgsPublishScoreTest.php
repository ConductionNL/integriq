<?php

/**
 * Contract tests for LtiController::agsPublishScore() — the Tool-role AGS
 * outbound seam (REQ-LTI-008).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\LtiController;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiNrpsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Tool-role half of REQ-LTI-008 had no route at all until
 * openconnector#1192: `LtiAgsService::publishScore()` was implemented and
 * unit-tested by calling the service directly, while every `lti#*` route was
 * the Platform (inbound) role — so nothing in production could reach it.
 *
 * These tests assert the controller seam itself: that a malformed request is
 * rejected BEFORE the service is touched, that a well-formed one reaches
 * `publishScore()` with the exact arguments the spec names, and that a
 * validation rejection is surfaced with the status the exception declares
 * rather than being flattened.
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
 */
class LtiControllerAgsPublishScoreTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IRequest
	 */
	private $request;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LtiAgsService
	 */
	private $agsService;

	/**
	 * @var LtiController
	 */
	private LtiController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->agsService = $this->createMock(LtiAgsService::class);

		$this->controller = new LtiController(
			'openconnector',
			$this->request,
			$this->createMock(LtiLaunchService::class),
			$this->agsService,
			$this->createMock(LtiNrpsService::class),
			$this->createMock(LtiKeyService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A request with no `lineItemUrl` is a 400, and the outbound call is never
	 * attempted — publishing a score spends this instance's signing key against
	 * a remote Platform, so a malformed request must not reach the wire.
	 *
	 * @return void
	 */
	public function testMissingLineItemUrlIs400AndNeverCallsTheService(): void {
		$this->request->method('getParams')->willReturn(['score' => ['scoreGiven' => 1]]);
		$this->agsService->expects($this->never())->method('publishScore');

		$response = $this->controller->agsPublishScore('deployment-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('lineItemUrl is required', $response->getData()['error']);

	}//end testMissingLineItemUrlIs400AndNeverCallsTheService()

	/**
	 * A `score` that is not an object is a 400, and again never reaches the
	 * service. A scalar here would otherwise be forwarded into the IMS AGS
	 * payload position.
	 *
	 * @return void
	 */
	public function testNonObjectScoreIs400AndNeverCallsTheService(): void {
		$this->request->method('getParams')->willReturn(
			[
				'lineItemUrl' => 'https://platform.test/lineitems/7',
				'score' => 'not-an-object',
			]
		);
		$this->agsService->expects($this->never())->method('publishScore');

		$response = $this->controller->agsPublishScore('deployment-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('score must be an object', $response->getData()['error']);

	}//end testNonObjectScoreIs400AndNeverCallsTheService()

	/**
	 * The happy path: the route parameter becomes the deployment, the body's
	 * `lineItemUrl` and `score` are passed through unchanged, and the upstream
	 * status is returned to the caller.
	 *
	 * @return void
	 */
	public function testWellFormedRequestReachesPublishScoreWithTheDeclaredArguments(): void {
		$score = [
			'userId' => 'user-42',
			'scoreGiven' => 8.5,
			'scoreMaximum' => 10,
		];

		$this->request->method('getParams')->willReturn(
			[
				'lineItemUrl' => 'https://platform.test/lineitems/7',
				'score' => $score,
			]
		);

		$this->agsService->expects($this->once())
			->method('publishScore')
			->with(
				$this->identicalTo('deployment-1'),
				$this->identicalTo('https://platform.test/lineitems/7'),
				$this->identicalTo($score)
			)
			->willReturn(['statusCode' => 200]);

		$response = $this->controller->agsPublishScore('deployment-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(200, $response->getData()['statusCode']);

	}//end testWellFormedRequestReachesPublishScoreWithTheDeclaredArguments()

	/**
	 * REQ-LTI-008 scenario 2: a token-endpoint failure must surface, not be
	 * silently discarded. `publishScore()` raises `LtiValidationException` with
	 * a 502 for that case; the controller must render THAT status, not a
	 * generic one.
	 *
	 * @return void
	 */
	public function testValidationRejectionKeepsTheStatusTheExceptionDeclares(): void {
		$this->request->method('getParams')->willReturn(
			[
				'lineItemUrl' => 'https://platform.test/lineitems/7',
				'score' => ['scoreGiven' => 1],
			]
		);

		$this->agsService->method('publishScore')->willThrowException(
			new LtiValidationException(
				message: 'Failed to obtain AGS access token from platform',
				details: [],
				httpStatus: 502
			)
		);

		$response = $this->controller->agsPublishScore('deployment-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(502, $response->getStatus());
		$this->assertSame(
			'Failed to obtain AGS access token from platform',
			$response->getData()['error']
		);

	}//end testValidationRejectionKeepsTheStatusTheExceptionDeclares()
}//end class
