<?php

/**
 * OpenConnector FSC Controller.
 *
 * REST controller for the fsc-connectivity change: `listServices()` lets
 * sibling apps discover what is currently resolvable, and `call()` lets a
 * sibling app invoke another organisation's published service through the
 * FSC (Federatieve Service Connectiviteit) provider seam — mirrors
 * `IwmoIjwController::createBericht()` / `KissController::createKlantcontact()`.
 * There is no inbound leg in this change (see design.md "Architecture
 * Overview"), so — unlike those two controllers — there is no signed
 * webhook receiver here.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/fsc-connectivity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\FscConnectivityException;
use OCA\OpenConnector\Exception\FscDirectoryException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\FscCallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * List resolvable FSC services + invoke one through the provider seam.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/fsc-connectivity/spec.md
 */
class FscController extends Controller
{
    /**
     * Constructor.
     *
     * @param string            $appName     App identifier ("openconnector").
     * @param IRequest          $request     Current request.
     * @param FscCallService    $callService Directory-resolve + call orchestration logic.
     * @param IUserSession      $userSession The user session.
     * @param ActionAuthService $actionAuth  The action authorization service.
     * @param IL10N             $l           The localization service.
     * @param LoggerInterface   $logger      Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FscCallService $callService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List the FSC services currently resolvable (the `fsc_service` cache).
     *
     * Returns an empty list — never an error — when no active FSC source is
     * configured; listing an empty catalogue is not a failure.
     *
     * @return JSONResponse `{services: [...]}`.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#scenario-listing-services-returns-the-current-cache
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listServices(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'fsc.list');

        return new JSONResponse(['services' => $this->callService->listResolvableServices()]);

    }//end listServices()

    /**
     * Resolve an organisation+service via the directory and invoke it.
     *
     * Expected JSON body: `{organisation, service, method?, payload?}`.
     *
     * @return JSONResponse `{ref, statusCode, body}` on success, or a 400/404/502/503 error envelope.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#requirement-call-routing-through-the-provider-seam-req-003
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function call(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'fsc.call');

        $params       = $this->request->getParams();
        $organisation = (string) ($params['organisation'] ?? '');
        $service      = (string) ($params['service'] ?? '');
        if ($organisation === '' || $service === '') {
            return new JSONResponse(
                [
                    'error'   => 'missing_fields',
                    'message' => $this->l->t('The "organisation" and "service" fields are required'),
                ],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->callService->callService(input: $params);
            return new JSONResponse($result);
        } catch (FscDirectoryException $exception) {
            return new JSONResponse(
                ['error' => 'unknown_service', 'message' => $exception->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (FscConnectivityException $exception) {
            $this->logger->warning('[FscController] call failed: '.$exception->getMessage());

            $status = Http::STATUS_BAD_GATEWAY;
            $code   = 'fsc_call_failed';
            if (str_contains($exception->getMessage(), 'No active FSC source') === true) {
                $status = Http::STATUS_SERVICE_UNAVAILABLE;
                $code   = 'not_configured';
            }

            return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
        }//end try

    }//end call()
}//end class
