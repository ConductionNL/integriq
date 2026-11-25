<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Middleware;

use OCA\Integriq\Middleware\ActionRefusalMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An action refusal answers its own status on a plain controller.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
 */
final class ActionRefusalMiddlewareTest extends TestCase {

	/**
	 * A refusal from a plain controller answers 403 with its message.
	 *
	 * @return void
	 */
	public function testARefusalFromAPlainControllerAnswers403(): void {
		$response = (new ActionRefusalMiddleware())->afterException(
			$this->createMock(originalClassName: Controller::class),
			'outbound',
			new OCSForbiddenException(message: "Action 'stuf-zkn.push' not allowed for your groups")
		);

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => "Action 'stuf-zkn.push' not allowed for your groups"], actual: $response->getData());
	}//end testARefusalFromAPlainControllerAnswers403()

	/**
	 * An OCS controller keeps Nextcloud's own handling.
	 *
	 * @return void
	 */
	public function testAnOcsControllerKeepsNextcloudsHandling(): void {
		$refusal = new OCSForbiddenException(message: 'refused');

		$this->expectExceptionObject(exception: $refusal);
		(new ActionRefusalMiddleware())->afterException($this->createMock(originalClassName: OCSController::class), 'index', $refusal);
	}//end testAnOcsControllerKeepsNextcloudsHandling()

	/**
	 * Any other exception is thrown on unchanged.
	 *
	 * @return void
	 */
	public function testAnyOtherExceptionIsThrownOn(): void {
		$failure = new RuntimeException(message: 'database gone');

		$this->expectExceptionObject(exception: $failure);
		(new ActionRefusalMiddleware())->afterException($this->createMock(originalClassName: Controller::class), 'index', $failure);
	}//end testAnyOtherExceptionIsThrownOn()

	/**
	 * An OCSException whose code is not an HTTP error status is thrown on unchanged.
	 *
	 * @return void
	 */
	public function testAnOcsCodeThatIsNoHttpErrorIsThrownOn(): void {
		$ocsOnly = new OCSException(message: 'ocs v1 failure', code: 997);

		$this->expectExceptionObject(exception: $ocsOnly);
		(new ActionRefusalMiddleware())->afterException($this->createMock(originalClassName: Controller::class), 'index', $ocsOnly);
	}//end testAnOcsCodeThatIsNoHttpErrorIsThrownOn()
}//end class
