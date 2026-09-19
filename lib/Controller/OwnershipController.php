<?php

/**
 * The read that answers who owns a record, and the guarded delete.
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
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * A consuming app reads ownership here rather than reading a contract, a
 * synchronisation or a source. A delete of a source-owned record is refused
 * here rather than by convention.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-the-consuming-app-reads-ownership-through-one-contract-req-sor-006
 */
class OwnershipController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param RecordOwnershipService $ownership The ownership read.
	 * @param LocalDeleteGuard $deleteGuard The delete guard.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param IUserSession $userSession The current session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RecordOwnershipService $ownership,
		private readonly LocalDeleteGuard $deleteGuard,
		private readonly OrObjectService $objectService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}//end __construct()

	/**
	 * Who owns the record behind this reference.
	 *
	 * An object no synchronisation maintains answers `local` and the call
	 * succeeds. It is not an error to ask about a record nobody follows.
	 *
	 * @param string $id The object's id.
	 *
	 * @return JSONResponse The ownership answer.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#scenario-an-unknown-object-answers-local-rather-than-failing
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		return new JSONResponse($this->ownership->forObject($id)->toArray());
	}//end show()

	/**
	 * Delete a record, refusing when an external source owns it.
	 *
	 * `#[NoAdminRequired]` with the authorization decision in the method body:
	 * the refusal is about who maintains the record, not about who is asking,
	 * and an anonymous request is refused outright.
	 *
	 * @param string $id The object's id.
	 * @param string $register The register the object lives in.
	 * @param string $schema The schema the object lives in.
	 * @param string|null $reason The typed override reason, when overriding.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $id, string $register = 'integriq', string $schema = '', ?string $reason = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ownership = $this->ownership->forObject($id);

		try {
			$override = $this->deleteGuard->guard($ownership, $reason, $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'ownership' => $ownership->toArray(),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		if ($schema === '') {
			return new JSONResponse(['error' => 'A schema is required to delete an object.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			if ($override !== null) {
				// Written onto the object before it goes, so the statement
				// survives in the audit trail rather than only in a log line.
				$this->objectService->saveObject(
					object: ([LocalDeleteGuard::OVERRIDE_KEY => $override] + $this->readObject(id: $id, register: $register, schema: $schema)),
					register: $register,
					schema: $schema,
					uuid: $id
				);
			}

			$this->objectService->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['deleted' => true, 'override' => $override]);
	}//end destroy()

	/**
	 * Check a synchronisation's disappearance policy before it is saved.
	 *
	 * The synchronisation itself is written through OpenRegister's objects
	 * API, so this is the refusal integriq can own: the edit screen asks here
	 * first, and a value this engine does not know is named as such rather
	 * than stored and silently read as `delete`.
	 *
	 * @param array<string,mixed> $sourceConfig The sourceConfig about to be saved.
	 *
	 * @return JSONResponse The verdict.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#scenario-a-misspelled-policy-is-refused-at-save
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function validatePolicy(array $sourceConfig = []): JSONResponse {
		try {
			$policy = DisappearancePolicy::fromSourceConfig($sourceConfig);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				[
					'valid' => false,
					'error' => $e->getMessage(),
					'key' => DisappearancePolicy::CONFIG_KEY,
					'accepted' => DisappearancePolicy::ACCEPTED,
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['valid' => true, 'policy' => $policy]);
	}//end validatePolicy()

	/**
	 * Read an object's data, or an empty array when it cannot be read.
	 *
	 * @param string $id The object's id.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string,mixed> The object's data.
	 */
	private function readObject(string $id, string $register, string $schema): array {
		try {
			$entity = $this->objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return [];
		}

		if ($entity === null) {
			return [];
		}

		$data = $entity->getObject();

		if (is_array($data) === true) {
			return $data;
		}

		return [];
	}//end readObject()
}//end class
