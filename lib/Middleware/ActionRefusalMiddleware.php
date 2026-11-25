<?php

/**
 * Integriq ActionRefusalMiddleware.
 *
 * Answers an OCS refusal thrown by a plain controller with its own status.
 *
 * @category Middleware
 * @package  OCA\Integriq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Middleware;

use Exception;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;

/**
 * An action refusal answers 403, not 500, on every controller.
 *
 * ActionAuthService::requireAction() refuses with an OCSForbiddenException.
 * Nextcloud turns an OCSException into its status only for an OCSController;
 * thrown from a plain Controller it escapes as a 500 error page. Most of
 * integriq's controllers are plain controllers, so a user outside the groups
 * an action is granted to got a 500 where the spec promises a 403. This
 * middleware answers such an exception with its own HTTP status and its
 * message as JSON. An OCS controller keeps Nextcloud's own handling, and any
 * other exception, or an OCSException whose code is not an HTTP error status,
 * is thrown on unchanged.
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
 */
class ActionRefusalMiddleware extends Middleware {

	/**
	 * Answer an OCS refusal from a plain controller with its status.
	 *
	 * @param mixed     $controller The controller that threw.
	 * @param string    $methodName The controller method.
	 * @param Exception $exception  What it threw.
	 *
	 * @return Response The JSON refusal.
	 *
	 * @throws Exception The exception, unchanged, when it is not an OCS refusal from a plain controller.
	 *
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if (($exception instanceof OCSException) === false || $controller instanceof OCSController) {
			throw $exception;
		}

		$status = (int)$exception->getCode();
		if ($status < 400 || $status > 599) {
			throw $exception;
		}

		return new JSONResponse(['message' => $exception->getMessage()], $status);
	}//end afterException()
}//end class
