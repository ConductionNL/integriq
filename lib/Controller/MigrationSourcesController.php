<?php

/**
 * HTTP surface for the migration source adapters.
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
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Migration\ColumnMapping;
use OCA\Integriq\Migration\ColumnMappingValidator;
use OCA\Integriq\Migration\MigrationPreviewReader;
use OCA\Integriq\Migration\MigrationSourceRegistry;
use OCA\Integriq\Migration\UnknownMigrationSourceException;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads only. Nothing here writes to an incumbent system, and nothing here
 * writes to a target: that is the import engine's half.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
 */
class MigrationSourcesController extends Controller {
	/**
	 * Reading an incumbent system's contents. Unset, this resolves to admin
	 * only, which is what the spec scenario asks for: "an administrator sees
	 * the size before committing". An operator can widen it in the action
	 * matrix.
	 *
	 * @var string
	 */
	public const ACTION_PREVIEW = 'migration.preview';

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param MigrationSourceRegistry $registry The adapters.
	 * @param MigrationPreviewReader $previewReader The read-only pass.
	 * @param ColumnMappingValidator $validator The column mapping validator.
	 * @param IUserSession $userSession Who is asking.
	 * @param ActionAuthService $actionAuth Whether they may.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MigrationSourceRegistry $registry,
		private readonly MigrationPreviewReader $previewReader,
		private readonly ColumnMappingValidator $validator,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct($appName, $request);
	}//end __construct()

	/**
	 * Every registered adapter and the record kinds it yields.
	 *
	 * @return JSONResponse The adapter inventory.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-describe-says-what-an-adapter-can-yield
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->registry->describeAll()]);
	}//end index()

	/**
	 * The read-only pass: counts, a sample and whether the read was complete.
	 *
	 * @param string $source The migration source id.
	 * @param array<string,mixed> $config The migration's configuration.
	 * @param int $sampleSize How many records to sample per kind.
	 *
	 * @return JSONResponse The preview.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-sees-the-size-before-committing
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(string $source, array $config = [], int $sampleSize = MigrationPreviewReader::DEFAULT_SAMPLE_SIZE): JSONResponse {
		// 🔴 THIS RETURNS A SAMPLE OF AN INCUMBENT SYSTEM'S RECORDS. Counts
		// alone would be revealing; the sample is the records themselves. With
		// #[NoAdminRequired] and nothing else, every authenticated account on
		// the instance could read any configured migration source by naming
		// it. The spec scenario is "an administrator sees the size before
		// committing", and an unset action resolves to admin only, so this
		// restores the posture the spec already described.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_PREVIEW);

		try {
			return new JSONResponse($this->previewReader->preview($source, $config, $sampleSize));
		} catch (UnknownMigrationSourceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end preview()

	/**
	 * Check a column mapping before it is stored.
	 *
	 * @param array<string,mixed> $mapping The mapping about to be saved.
	 * @param array<int,string> $schemaFields Every field the target schema has.
	 * @param array<int,string> $requiredFields The schema's required fields.
	 *
	 * @return JSONResponse The verdict.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
	 *
	 * @no-admin-idor-exempt Pure computation over the caller's own arguments. It reads no
	 *   storage and names no object: the mapping, the schema fields and the required fields
	 *   all arrive in the request, and the answer is derived from them alone. There is no
	 *   object here to scope to a caller.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function validateMapping(array $mapping = [], array $schemaFields = [], array $requiredFields = []): JSONResponse {
		try {
			$columnMapping = ColumnMapping::fromArray($mapping);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['valid' => false, 'errors' => [$e->getMessage()]], Http::STATUS_BAD_REQUEST);
		}

		$errors = array_merge(
			$this->validator->validateTargets($columnMapping, $schemaFields),
			$this->validator->validateRequired($columnMapping, $requiredFields)
		);

		if ($errors !== []) {
			return new JSONResponse(['valid' => false, 'errors' => $errors], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['valid' => true, 'mapping' => $columnMapping->toArray()]);
	}//end validateMapping()
}//end class
