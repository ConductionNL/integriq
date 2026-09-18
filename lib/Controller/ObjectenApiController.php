<?php

/**
 * The VNG Objecten and Objecttypen APIs, over one token check.
 *
 * 🔴 EVERY ROUTE IS `#[PublicPage]` AND THAT IS THE POINT, not an oversight.
 * The standard's consumers are other suppliers' systems presenting
 * `Authorization: Token <key>`; they have no Nextcloud session, and the
 * requirement says so — "the request MUST NOT depend on a Nextcloud session".
 * So Nextcloud's own session guard is stood down and {@see ObjectenTokenService}
 * is the guard, resolved FIRST on every method, before any register is read.
 * A route here without that call is an unauthenticated read of a register.
 *
 * 🔴 AND CSRF IS OFF FOR THE SAME REASON. A server-to-server caller has no
 * CSRF token to send. That is safe only because the token check does not
 * depend on a cookie: a request authenticated by an `Authorization` header is
 * not a request a browser can be tricked into making on somebody's behalf.
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
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves both standards' read routes.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectenApiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request The request.
	 * @param ObjectenTokenService        $tokens  The one guard.
	 * @param ObjecttypeEndpointHandler   $types   The Objecttypen API.
	 * @param ObjectEndpointHandler       $objects The Objecten API read paths.
	 * @param ObjectWriteHandler          $writes  The Objecten API write paths.
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectenTokenService $tokens,
		private readonly ObjecttypeEndpointHandler $types,
		private readonly ObjectEndpointHandler $objects,
		private readonly ObjectWriteHandler $writes,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/v2/objecttypes`.
	 *
	 * The list names no objecttype, so the token is checked for authentication
	 * only — and the list it returns is still every PUBLISHED objecttype, not
	 * every one this token may use. That is the standard's shape and the same
	 * enumeration property the 404/403 split has.
	 *
	 * @return JSONResponse The list.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function objecttypes(): JSONResponse {
		$refusal = $this->refuse(objecttype: '');
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer: $this->types->index(baseUrl: $this->baseUrl()));
	}//end objecttypes()

	/**
	 * `GET /api/v2/objecttypes/{uuid}`.
	 *
	 * @param string $uuid The objecttype.
	 *
	 * @return JSONResponse The objecttype.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function objecttype(string $uuid): JSONResponse {
		$refusal = $this->refuse(objecttype: $uuid);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer: $this->types->show(uuid: $uuid, baseUrl: $this->baseUrl()));
	}//end objecttype()

	/**
	 * `GET /api/v2/objecttypes/{uuid}/versions/{version}`.
	 *
	 * @param string $uuid    The objecttype.
	 * @param string $version The version.
	 *
	 * @return JSONResponse The version.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function objecttypeVersion(string $uuid, string $version): JSONResponse {
		$refusal = $this->refuse(objecttype: $uuid);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer: $this->types->version(uuid: $uuid, version: $version, baseUrl: $this->baseUrl()));
	}//end objecttypeVersion()

	/**
	 * `GET /api/v2/objects`.
	 *
	 * @return JSONResponse The list.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function objects(): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer:
			$this->objects->index(query: $this->queryParameters(), baseUrl: $this->baseUrl())
		);
	}//end objects()

	/**
	 * `GET /api/v2/objects/{uuid}`.
	 *
	 * 🔑 THE TYPE IS A QUERY PARAMETER HERE, and required, because the token
	 * carries a permission PER OBJECTTYPE: without knowing which type the
	 * object is, the guard cannot be applied before the register is read — and
	 * reading first to find out is exactly the ordering the requirement
	 * forbids.
	 *
	 * @param string $uuid The object.
	 *
	 * @return JSONResponse The object.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function object(string $uuid): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer: $this->objects->show(type: $type, uuid: $uuid, baseUrl: $this->baseUrl()));
	}//end object()

	/**
	 * `POST /api/v2/objects/search`.
	 *
	 * @return JSONResponse The matches.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function search(): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer:
			$this->objects->search(
				type: $type,
				body: ['geometry' => (array)$this->request->getParam('geometry', [])],
				baseUrl: $this->baseUrl()
			)
		);
	}//end search()

	/**
	 * `POST /api/v2/objects`.
	 *
	 * @return JSONResponse The created object.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function createObject(): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type, writing: true);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer:
			$this->writes->create(
				type: $type,
				body: ['record' => (array)$this->request->getParam('record', [])],
				principal: $this->principalFor(objecttype: $type),
				baseUrl: $this->baseUrl()
			)
		);
	}//end createObject()

	/**
	 * `PUT /api/v2/objects/{uuid}`.
	 *
	 * @param string $uuid The object.
	 *
	 * @return JSONResponse The replaced object.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function replaceObject(string $uuid): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type, writing: true);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(answer:
			$this->writes->replace(
				type: $type,
				uuid: $uuid,
				body: ['record' => (array)$this->request->getParam('record', [])],
				principal: $this->principalFor(objecttype: $type),
				baseUrl: $this->baseUrl()
			)
		);
	}//end replaceObject()

	/**
	 * `PATCH /api/v2/objects/{uuid}`.
	 *
	 * 🔴 IT READS THE CURRENT OBJECT FIRST, and that read happens AFTER the
	 * token check, not before it. A merge needs what is stored; fetching it to
	 * decide the merge before knowing whether the caller may touch the type
	 * would be the ordering the requirement forbids.
	 *
	 * @param string $uuid The object.
	 *
	 * @return JSONResponse The updated object.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function updateObject(string $uuid): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type, writing: true);
		if ($refusal !== null) {
			return $refusal;
		}

		$current = $this->objects->show(type: $type, uuid: $uuid);
		if ($current['status'] !== 200) {
			return $this->answer(answer: $current);
		}

		return $this->answer(answer:
			$this->writes->update(
				type: $type,
				uuid: $uuid,
				body: ['record' => (array)$this->request->getParam('record', [])],
				current: (array)($current['body']['record']['data'] ?? []),
				principal: $this->principalFor(objecttype: $type),
				baseUrl: $this->baseUrl()
			)
		);
	}//end updateObject()

	/**
	 * `DELETE /api/v2/objects/{uuid}`.
	 *
	 * The uuid is resolved under the objecttype the token was approved for,
	 * before the delete, for the same reason the replace and the partial update
	 * do it: the uuid comes from the caller and the token grants a permission
	 * per objecttype, so a uuid belonging to another type has to answer the
	 * read path's 404 rather than reach a delete that was never authorised for
	 * it. It also makes the two write shapes one shape — an endpoint that
	 * refuses differently from its siblings is the one somebody probes.
	 *
	 * @param string $uuid The object.
	 *
	 * @return JSONResponse Nothing, or a refusal.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function deleteObject(string $uuid): JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		$refusal = $this->refuse(objecttype: $type, writing: true);
		if ($refusal !== null) {
			return $refusal;
		}

		$principal = $this->principalFor(objecttype: $type);

		$current = $this->objects->show(type: $type, uuid: $uuid);
		if ($current['status'] !== 200) {
			return $this->answer(answer: $current);
		}

		return $this->answer(answer:
			$this->writes->delete(type: $type, uuid: $uuid, principal: $principal)
		);
	}//end deleteObject()

	/**
	 * The principal this token's writes are attributed to.
	 *
	 * @param string $objecttype The objecttype, so the verdict is the same one.
	 *
	 * @return string The principal.
	 */
	private function principalFor(string $objecttype): string {
		$verdict = $this->tokens->verdictFor(
			authorization: $this->request->getHeader('Authorization'),
			objecttype: $objecttype,
			writing: true
		);

		return (string)$verdict['principal'];
	}//end principalFor()

	/**
	 * The token check, run before anything reads a register.
	 *
	 * @param string $objecttype The objecttype, empty when the route names none.
	 * @param bool   $writing    Whether the request writes.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function refuse(string $objecttype, bool $writing = false): ?JSONResponse {
		$verdict = $this->tokens->verdictFor(
			authorization: $this->request->getHeader('Authorization'),
			objecttype: $objecttype,
			writing: $writing
		);

		if ($verdict['verdict'] === ObjectenTokenService::ALLOWED) {
			return null;
		}

		// The verdict word is NOT returned. It distinguishes "no token" from
		// "unknown token", which is a fact about somebody else's key, and the
		// status already says everything the caller may act on.
		return new JSONResponse(
			[
				'type' => 'about:blank',
				'title' => 'Refused',
				'status' => $verdict['status'],
				'detail' => $this->detailFor(verdict: (string)$verdict['verdict']),
			],
			$verdict['status']
		);
	}//end refuse()

	/**
	 * The sentence a refused caller reads.
	 *
	 * @param string $verdict The verdict.
	 *
	 * @return string The detail.
	 */
	private function detailFor(string $verdict): string {
		return match ($verdict) {
			ObjectenTokenService::NO_TOKEN,
			ObjectenTokenService::UNKNOWN_TOKEN => 'This API is reached with an "Authorization: Token <key>" header.',
			ObjectenTokenService::UNKNOWN_OBJECTTYPE => 'No such objecttype is published here.',
			ObjectenTokenService::REFUSED_OBJECTTYPE => 'This token does not carry a permission for that objecttype.',
			ObjectenTokenService::READ_ONLY => 'This token may read that objecttype but not write it.',
			default => 'Refused.',
		};
	}//end detailFor()

	/**
	 * One handler answer as a response.
	 *
	 * @param array{status: int, body: array<string, mixed>} $answer The answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $answer): JSONResponse {
		return new JSONResponse($answer['body'], $answer['status']);
	}//end answer()

	/**
	 * The query parameters a list route reads.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function queryParameters(): array {
		$parameters = [];
		foreach (['type', 'data_attrs', 'date', 'registrationDate', 'ordering', 'page', 'pageSize'] as $name) {
			$value = $this->request->getParam($name, null);
			if ($value !== null) {
				$parameters[$name] = $value;
			}
		}

		return $parameters;
	}//end queryParameters()

	/**
	 * The base a returned `url` is built from.
	 *
	 * @return string The base.
	 */
	private function baseUrl(): string {
		return rtrim((string)$this->request->getServerHost(), '/');
	}//end baseUrl()
}//end class
