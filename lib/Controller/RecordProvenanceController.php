<?php

/**
 * Integriq RecordProvenanceController: where an OpenRegister record came from.
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
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/synchronization/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\RecordProvenanceService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Throwable;

/**
 * Answers only for a record the caller may read: the read is checked first, under OpenRegister's rights.
 *
 * @spec openspec/specs/synchronization/spec.md
 */
class RecordProvenanceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName       The app id.
	 * @param IRequest                $request       The request.
	 * @param RecordProvenanceService $provenance    Reads the provenance.
	 * @param OrObjectService         $objectService Checks the caller may read the record.
	 * @param IL10N                   $l             Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly RecordProvenanceService $provenance,
		private readonly OrObjectService $objectService,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The provenance of `?register=&schema=&id=`.
	 *
	 * @return JSONResponse The provenance, 400 without the three, 404 for a record the caller cannot read.
	 *
	 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md#requirement-a-record-says-where-it-was-synchronized-from
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$register = trim((string)$this->request->getParam('register', ''));
		$schema = trim((string)$this->request->getParam('schema', ''));
		$id = trim((string)$this->request->getParam('id', ''));
		if ($register === '' || $schema === '' || $id === '') {
			return new JSONResponse(['error' => $this->l->t('Name the register, the schema and the record.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$record = $this->objectService->find(id: $id, register: $register, schema: $schema, _rbac: true, _multitenancy: true);
		} catch (Throwable) {
			$record = null;
		}

		if ($record === null) {
			return new JSONResponse(['error' => $this->l->t('There is no such record.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->provenance->provenance(recordId: (string)$record->getUuid()));
	}//end show()
}//end class
