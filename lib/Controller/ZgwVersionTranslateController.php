<?php

/**
 * Integriq ZGW Version Translate Controller.
 *
 * REST controller for the zgw-version-translation change: `translate()`
 * lets a sibling app or an external municipality integration translate one
 * ZGW resource payload between the fleet's current shape (`1.0`) and VNG's
 * incremental stability line (`1.6`) — mirrors `FscController::call()` /
 * `KissController::createCustomerContact()` in shape (thin HTTP/auth shell
 * delegating to a service).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\ZgwLiteralLeakException;
use OCA\Integriq\Exception\ZgwUnknownResourceException;
use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Exception\ZgwVersionNotImplementedException;
use OCA\Integriq\Exception\ZgwVersionTranslationException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ApiVersionNegotiationInterface;
use OCA\Integriq\Service\ZgwVersionTranslationServiceInterface;
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
class ZgwVersionTranslateController extends Controller {

	/**
	 * The classified translation failures: error code and HTTP status, checked in this order.
	 *
	 * @var array<class-string<ZgwVersionTranslationException>, array{0: string, 1: int}>
	 */
	private const TRANSLATION_FAILURES = [
		ZgwUnknownResourceException::class => ['unknown_resource', Http::STATUS_BAD_REQUEST],
		ZgwUnknownVersionException::class => ['unknown_version', Http::STATUS_BAD_REQUEST],
		ZgwVersionNotImplementedException::class => ['not_implemented', Http::STATUS_NOT_IMPLEMENTED],
		ZgwLiteralLeakException::class => ['literal_leak', Http::STATUS_UNPROCESSABLE_ENTITY],
	];
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param ZgwVersionTranslationServiceInterface $translationService Translator dispatch + persistence.
	 * @param ApiVersionNegotiationInterface $negotiationService Version resolution from headers.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwVersionTranslationServiceInterface $translationService,
		private readonly ApiVersionNegotiationInterface $negotiationService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
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
	public function translate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'zgw-version.translate');

		$params = $this->request->getParams();
		if ($this->hasResourceAndPayload(params: $params) === false) {
			return $this->missingFields();
		}

		return $this->translateParams(params: $params);

	}//end translate()

	/**
	 * The 400 answer for a body without a resource or an array payload.
	 *
	 * @return JSONResponse
	 */
	private function missingFields(): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'missing_fields',
				'message' => $this->l->t('The "resource" and "payload" fields are required'),
			],
			Http::STATUS_BAD_REQUEST
		);

	}//end missingFields()

	/**
	 * Whether the body names a resource and carries an array payload.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return bool
	 */
	private function hasResourceAndPayload(array $params): bool {
		$resource = (string)($params['resource'] ?? '');
		$payload = ($params['payload'] ?? null);

		return $resource !== '' && is_array($payload) === true;

	}//end hasResourceAndPayload()

	/**
	 * Resolve both versions and translate, answering a translation failure with its error envelope.
	 *
	 * @param array<string, mixed> $params The request parameters, with a resource and an array payload.
	 *
	 * @return JSONResponse The translation, or a 400/422/501 error envelope.
	 */
	private function translateParams(array $params): JSONResponse {
		$versions = new ZgwVersionRange(
			fromVersion: $this->resolveVersion(params: $params, key: 'fromVersion'),
			toVersion: $this->resolveVersion(params: $params, key: 'toVersion')
		);

		try {
			return $this->translatedResponse(params: $params, versions: $versions);
		} catch (ZgwVersionTranslationException $exception) {
			return $this->translationFailure(exception: $exception);
		}

	}//end translateParams()

	/**
	 * The version a request parameter names, else the header or default one.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $key    `fromVersion` or `toVersion`.
	 *
	 * @return string The resolved version.
	 */
	private function resolveVersion(array $params, string $key): string {
		return $this->negotiationService->resolveVersion(
			request: $this->request,
			explicit: $this->stringParamOrNull(params: $params, key: $key)
		);

	}//end resolveVersion()

	/**
	 * Translate the payload and answer it with the resource and both versions.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param ZgwVersionRange      $versions The resolved versions.
	 *
	 * @return JSONResponse `{resource, fromVersion, toVersion, payload}`.
	 *
	 * @throws ZgwVersionTranslationException When the translator refuses.
	 */
	private function translatedResponse(array $params, ZgwVersionRange $versions): JSONResponse {
		$resource = (string)($params['resource'] ?? '');
		$translated = $this->translationService->translate(
			resource: $resource,
			fromVersion: $versions->fromVersion,
			toVersion: $versions->toVersion,
			payload: $params['payload']
		);

		return new JSONResponse(['resource' => $resource] + $versions->asResponseFields() + ['payload' => $translated]);

	}//end translatedResponse()

	/**
	 * The error envelope for a translation failure; only an unclassified one is logged.
	 *
	 * @param ZgwVersionTranslationException $exception The failure.
	 *
	 * @return JSONResponse A 400, 422 or 501 error envelope.
	 */
	private function translationFailure(ZgwVersionTranslationException $exception): JSONResponse {
		foreach (self::TRANSLATION_FAILURES as $class => [$error, $status]) {
			if ($exception instanceof $class) {
				return new JSONResponse(['error' => $error, 'message' => $exception->getMessage()], $status);
			}
		}

		$this->logger->warning('[ZgwVersionTranslateController] translate failed: ' . $exception->getMessage());

		return new JSONResponse(
			['error' => 'translation_failed', 'message' => $exception->getMessage()],
			Http::STATUS_BAD_REQUEST
		);

	}//end translationFailure()

	/**
	 * Return `$params[$key]` cast to string, or null when absent — lets the
	 * negotiation service fall back to header/default resolution.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string $key The parameter key to read.
	 *
	 * @return string|null The string value, or null when the key is absent.
	 */
	private function stringParamOrNull(array $params, string $key): ?string {
		if (isset($params[$key]) === false) {
			return null;
		}

		return (string)$params[$key];
	}//end stringParamOrNull()
}//end class
