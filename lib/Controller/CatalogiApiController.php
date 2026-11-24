<?php

/**
 * The VNG Catalogi API 1.3, read half: catalogussen and informatieobjecttypen.
 *
 * 🔴 EVERY ROUTE IS `#[PublicPage]` AND CSRF-FREE ON PURPOSE. The consumers are
 * Documenten APIs presenting a ZGW JWT with no Nextcloud session. The handlers'
 * {@see \OCA\Integriq\Service\Catalogi\CatalogiGate} is the guard: binding,
 * token and the catalogi.lezen scope are checked before any document type is
 * read. Every write method answers 405.
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
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Catalogi\CatalogusEndpointHandler;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the catalogue and its document types.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
class CatalogiApiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                            $request    The request.
	 * @param IURLGenerator                       $urls       Builds the absolute URLs served here.
	 * @param CatalogusEndpointHandler            $catalogues The catalogussen.
	 * @param InformatieobjecttypeEndpointHandler $types      The informatieobjecttypen.
	 */
	public function __construct(
		IRequest $request,
		private readonly IURLGenerator $urls,
		private readonly CatalogusEndpointHandler $catalogues,
		private readonly InformatieobjecttypeEndpointHandler $types,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /catalogi/api/v1/catalogussen`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function catalogussen(): JSONResponse {
		$query = $this->query(names: ['domein', 'rsin', 'page']);

		return $this->answer(answer: $this->catalogues->index(authorization: $this->authorization(), baseUrl: $this->baseUrl(), query: $query));
	}//end catalogussen()

	/**
	 * `GET /catalogi/api/v1/catalogussen/{uuid}`.
	 *
	 * @param string $uuid The catalogue.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function catalogus(string $uuid): JSONResponse {
		return $this->answer(answer: $this->catalogues->show(authorization: $this->authorization(), uuid: $uuid, baseUrl: $this->baseUrl()));
	}//end catalogus()

	/**
	 * `GET /catalogi/api/v1/informatieobjecttypen`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function informatieobjecttypen(): JSONResponse {
		$query = $this->query(names: ['catalogus', 'status', 'page']);

		return $this->answer(answer: $this->types->index(authorization: $this->authorization(), baseUrl: $this->baseUrl(), query: $query));
	}//end informatieobjecttypen()

	/**
	 * `GET /catalogi/api/v1/informatieobjecttypen/{uuid}`.
	 *
	 * @param string $uuid The type.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function informatieobjecttype(string $uuid): JSONResponse {
		$status = (string)($this->query(names: ['status'])['status'] ?? 'definitief');

		return $this->answer(answer: $this->types->show(authorization: $this->authorization(), uuid: $uuid, baseUrl: $this->baseUrl(), status: $status));
	}//end informatieobjecttype()

	/**
	 * Every `POST`, `PUT`, `PATCH` and `DELETE` on the facade: 405.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function write(): JSONResponse {
		return $this->answer(answer: $this->types->write());
	}//end write()

	/**
	 * The handler's answer as a response.
	 *
	 * @param array{status: int, body: array<string, mixed>} $answer The answer.
	 *
	 * @return JSONResponse
	 */
	private function answer(array $answer): JSONResponse {
		return new JSONResponse($answer['body'], $answer['status']);
	}//end answer()

	/**
	 * The `Authorization` header.
	 *
	 * @return string
	 */
	private function authorization(): string {
		return (string)$this->request->getHeader('Authorization');
	}//end authorization()

	/**
	 * The given query parameters, when present.
	 *
	 * @param array<int, string> $names The parameter names.
	 *
	 * @return array<string, string>
	 */
	private function query(array $names): array {
		$query = [];
		foreach ($names as $name) {
			$value = $this->request->getParam($name, null);
			if (is_scalar($value) === true) {
				$query[$name] = (string)$value;
			}
		}

		return $query;
	}//end query()

	/**
	 * The absolute URL of `/catalogi/api/v1`.
	 *
	 * @return string
	 */
	private function baseUrl(): string {
		$list = $this->urls->linkToRouteAbsolute(AppIdentity::APP_ID . '.catalogiApi.catalogussen');

		return substr($list, 0, (strlen($list) - strlen('/catalogussen')));
	}//end baseUrl()

}//end class
