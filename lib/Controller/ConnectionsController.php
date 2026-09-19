<?php

/**
 * Integriq ConnectionsController.
 *
 * The one endpoint the "Add integration" dialog needs beyond OpenRegister's
 * object API: link a source to a declared connection and probe it straight
 * away (umbrella design D9). Listing connections and sources goes through
 * `/api/objects/integriq/{schema}` like every other page (ADR-022).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\ConnectionLinkException;
use OCA\Integriq\Service\ConnectionProbeService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only link-and-probe endpoint for connection rows.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
 */
class ConnectionsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ConnectionProbeService $probeService Links and probes.
	 * @param IL10N $l10n Translates error messages.
	 * @param LoggerInterface $logger Logs an unexpected failure.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConnectionProbeService $probeService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Link a source to a connection that has none, probe it, and return both.
	 *
	 * Admin-only and CSRF-protected: a link decides which credentials a
	 * health probe calls with. The body carries either `source` (an existing
	 * source uuid) or `fromTemplate: true` (make or reuse the source named by
	 * the connection's `sourceTemplate`).
	 *
	 * @param string $id The connection row uuid.
	 *
	 * @return JSONResponse `{connection, probe}` on success; an `error` with 400, 404, 409 or 500 otherwise.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-linking-a-source-probes-it-straight-away
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-connection-that-already-has-a-source-is-refused
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function link(string $id): JSONResponse {
		$sourceId = trim((string)$this->request->getParam('source', ''));
		$fromTemplate = filter_var($this->request->getParam('fromTemplate', false), FILTER_VALIDATE_BOOLEAN);

		if ($sourceId === '' && $fromTemplate === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Pick a source or create one from the template.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$row = $this->linkRow(connectionId: $id, sourceId: $sourceId);
		} catch (ConnectionLinkException $e) {
			return $this->refusal(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Integriq could not link a source to connection {uuid}: {reason}',
				['app' => 'integriq', 'uuid' => $id, 'reason' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(
				['error' => $this->l10n->t('The source could not be linked: %s', [$e->getMessage()])],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			[
				'connection' => array_merge(['id' => $row['uuid']], $row['data']),
				'probe' => $row['data']['lastProbe'] ?? null,
			]
		);
	}//end link()

	/**
	 * Link an existing source when one is given, otherwise the template.
	 *
	 * @param string $connectionId The connection row uuid.
	 * @param string $sourceId The source uuid, or '' for the template.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}
	 *
	 * @throws ConnectionLinkException When the link is refused.
	 */
	private function linkRow(string $connectionId, string $sourceId): array {
		if ($sourceId !== '') {
			return $this->probeService->linkSource(connectionId: $connectionId, sourceId: $sourceId);
		}

		return $this->probeService->linkTemplate(connectionId: $connectionId);
	}//end linkRow()

	/**
	 * Translate a refused link into a response.
	 *
	 * @param ConnectionLinkException $exception The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusal(ConnectionLinkException $exception): JSONResponse {
		return match ($exception->getReason()) {
			ConnectionLinkException::CONNECTION_NOT_FOUND => new JSONResponse(
				['error' => $this->l10n->t('This connection does not exist.')],
				Http::STATUS_NOT_FOUND
			),
			ConnectionLinkException::SOURCE_NOT_FOUND => new JSONResponse(
				['error' => $this->l10n->t('This source does not exist.')],
				Http::STATUS_NOT_FOUND
			),
			ConnectionLinkException::ALREADY_LINKED => new JSONResponse(
				['error' => $this->l10n->t('This connection already has a source.')],
				Http::STATUS_CONFLICT
			),
			ConnectionLinkException::NO_TEMPLATE => new JSONResponse(
				['error' => $this->l10n->t('This connection declares no source template.')],
				Http::STATUS_CONFLICT
			),
			default => new JSONResponse(
				['error' => $this->l10n->t('The source template for this connection was not found.')],
				Http::STATUS_NOT_FOUND
			),
		};
	}//end refusal()
}//end class
