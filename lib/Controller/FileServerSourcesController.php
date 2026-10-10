<?php

/**
 * Host key confirmation for SFTP and FTPS sources.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Adapter\DataInfra\AbstractFileTransferAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\FtpsAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\SftpAdapter;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The first connection test shows the server's fingerprint; the administrator confirms it to pin it.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class FileServerSourcesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName         The app id.
	 * @param IRequest        $request         The request.
	 * @param OrObjectService $orObjectService Loads and saves the source.
	 * @param SftpAdapter     $sftpAdapter     The SFTP adapter.
	 * @param FtpsAdapter     $ftpsAdapter     The FTPS adapter.
	 * @param IL10N           $l               Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly OrObjectService $orObjectService,
		private readonly SftpAdapter $sftpAdapter,
		private readonly FtpsAdapter $ftpsAdapter,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Test the connection: what the server presents, and whether it matches the pin.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return JSONResponse The fingerprint, the pin, and whether it connected.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function test(string $id): JSONResponse {
		$source = $this->fileServerSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		return new JSONResponse($this->adapterFor(source: $source->getObject())->testConnection(source: $source->getObject()));

	}//end test()

	/**
	 * Pin the fingerprint the administrator confirmed, if the server still presents it.
	 *
	 * @param string $id          The source uuid.
	 * @param string $fingerprint The fingerprint the administrator saw and confirmed.
	 *
	 * @return JSONResponse The saved pin, or why it was not saved.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function pin(string $id, string $fingerprint = ''): JSONResponse {
		$source = $this->fileServerSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$data = $source->getObject();
		$presented = $this->adapterFor(source: $data)->testConnection(source: array_merge($data, ['hostKeyFingerprint' => '']))['fingerprint'];
		if ($fingerprint === '' || $presented === '' || hash_equals($presented, $fingerprint) === false) {
			return new JSONResponse(
				['error' => $this->l->t('The server no longer presents this fingerprint. Test the connection again.'), 'fingerprint' => $presented],
				Http::STATUS_CONFLICT
			);
		}

		$data['hostKeyFingerprint'] = $fingerprint;
		$this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'source', uuid: $id);

		return new JSONResponse(['hostKeyFingerprint' => $fingerprint]);

	}//end pin()

	/**
	 * The source, when it exists and is a file server.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return ObjectEntity|JSONResponse The source, or a 404 / 400 response.
	 */
	private function fileServerSource(string $id): ObjectEntity|JSONResponse {
		try {
			$source = $this->orObjectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			$source = null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		if (in_array(($source->getObject()['type'] ?? ''), ['sftp', 'ftps'], true) === false) {
			return new JSONResponse(['error' => $this->l->t('This source is not an SFTP or FTPS server.')], Http::STATUS_BAD_REQUEST);
		}

		return $source;

	}//end fileServerSource()

	/**
	 * The adapter for the source's type.
	 *
	 * @param array<string,mixed> $source The source.
	 *
	 * @return AbstractFileTransferAdapter The adapter.
	 */
	private function adapterFor(array $source): AbstractFileTransferAdapter {
		if (($source['type'] ?? '') === 'ftps') {
			return $this->ftpsAdapter;
		}

		return $this->sftpAdapter;

	}//end adapterFor()
}//end class
