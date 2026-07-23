<?php

/**
 * OpenConnector ZGW Version Translate Controller.
 *
 * REST controller for the zgw-version-translation change: `translate()`
 * lets a sibling app or an external municipality integration translate one
 * ZGW resource payload between the fleet's current shape (`1.0`) and VNG's
 * incremental stability line (`1.6`) — mirrors `FscController::call()` /
 * `KissController::createKlantcontact()` in shape (thin HTTP/auth shell
 * delegating to a service).
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
 * @spec openspec/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\ZgwLiteralLeakException;
use OCA\OpenConnector\Exception\ZgwUnknownResourceException;
use OCA\OpenConnector\Exception\ZgwUnknownVersionException;
use OCA\OpenConnector\Exception\ZgwVersionNotImplementedException;
use OCA\OpenConnector\Exception\ZgwVersionTranslationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ZgwVersionNegotiationService;
use OCA\OpenConnector\Service\ZgwVersionTranslationService;
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
 * Translate one ZGW resource payload between `1.0` and `1.6`.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */
class ZgwVersionTranslateController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                       $appName            App identifier ("openconnector").
     * @param IRequest                     $request            Current request.
     * @param ZgwVersionTranslationService $translationService Translator dispatch + persistence.
     * @param ZgwVersionNegotiationService $negotiationService Version resolution from headers.
     * @param IUserSession                 $userSession        The user session.
     * @param ActionAuthService            $actionAuth         The action authorization service.
     * @param IL10N                        $l                  The localization service.
     * @param LoggerInterface              $logger             Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwVersionTranslationService $translationService,
        private readonly ZgwVersionNegotiationService $negotiationService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Translate one ZGW resource payload.
     *
     * Expected JSON body: `{resource, fromVersion?, toVersion?, payload}`.
     * `fromVersion`/`toVersion` fall back to the `X-ZGW-Version` header,
     * then the `Accept` header's `version=` parameter, then default `1.0`
     * (full passthrough) — see {@see ZgwVersionNegotiationService::resolveVersion()}.
     *
     * @return JSONResponse `{resource, fromVersion, toVersion, payload}` on success,
     *                      or a 400/401/422/501 error envelope.
     *
     * @spec openspec/specs/zgw-version-translation/spec.md#requirement-rest-surface-for-sibling-apps-and-external-consumers-req-003
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function translate(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'zgw-version.translate');

        $params   = $this->request->getParams();
        $resource = (string) ($params['resource'] ?? '');
        $payload  = ($params['payload'] ?? null);

        if ($resource === '' || is_array($payload) === false) {
            return new JSONResponse(
                [
                    'error'   => 'missing_fields',
                    'message' => $this->l->t('The "resource" and "payload" fields are required'),
                ],
                Http::STATUS_BAD_REQUEST
            );
        }

        $fromVersion = $this->negotiationService->resolveVersion(
            request: $this->request,
            explicit: $this->stringParamOrNull(params: $params, key: 'fromVersion')
        );
        $toVersion   = $this->negotiationService->resolveVersion(
            request: $this->request,
            explicit: $this->stringParamOrNull(params: $params, key: 'toVersion')
        );

        try {
            $translated = $this->translationService->translate(
                resource: $resource,
                fromVersion: $fromVersion,
                toVersion: $toVersion,
                payload: $payload
            );

            return new JSONResponse(
                [
                    'resource'    => $resource,
                    'fromVersion' => $fromVersion,
                    'toVersion'   => $toVersion,
                    'payload'     => $translated,
                ]
            );
        } catch (ZgwUnknownResourceException $exception) {
            return new JSONResponse(
                ['error' => 'unknown_resource', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (ZgwUnknownVersionException $exception) {
            return new JSONResponse(
                ['error' => 'unknown_version', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (ZgwVersionNotImplementedException $exception) {
            return new JSONResponse(
                ['error' => 'not_implemented', 'message' => $exception->getMessage()],
                Http::STATUS_NOT_IMPLEMENTED
            );
        } catch (ZgwLiteralLeakException $exception) {
            return new JSONResponse(
                ['error' => 'literal_leak', 'message' => $exception->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (ZgwVersionTranslationException $exception) {
            $this->logger->warning('[ZgwVersionTranslateController] translate failed: '.$exception->getMessage());

            return new JSONResponse(
                ['error' => 'translation_failed', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try

    }//end translate()

    /**
     * Return `$params[$key]` cast to string, or null when absent — lets the
     * negotiation service fall back to header/default resolution.
     *
     * @param array<string, mixed> $params The request parameters.
     * @param string               $key    The parameter key to read.
     *
     * @return string|null The string value, or null when the key is absent.
     */
    private function stringParamOrNull(array $params, string $key): ?string
    {
        if (isset($params[$key]) === false) {
            return null;
        }

        return (string) $params[$key];

    }//end stringParamOrNull()
}//end class
