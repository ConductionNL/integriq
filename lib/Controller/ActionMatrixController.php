<?php

/**
 * ActionMatrixController
 *
 * Admin-only API for reading and writing the ADR-023 action-authorization
 * matrix. Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Admin-only controller exposing the action-authorization matrix (ADR-023).
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
 */
class ActionMatrixController extends Controller {
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		IRequest $request,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(
			appName: AppIdentity::APP_ID,
			request: $request
		);
	}//end __construct()

	/**
	 * Get the full action matrix, the complete action key list, and all groups.
	 *
	 * The action key list is the union of the keys currently in the matrix and
	 * the keys declared in the seed file, so the admin sees every declared
	 * action even before any customization.
	 *
	 * The seed's `labels` and `areas` (action name to text) come with them, so
	 * the screen can show one section per area and a label per action.
	 *
	 * @return JSONResponse The matrix, action keys, group IDs, labels and areas.
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getMatrix(): JSONResponse {
		$matrix = $this->actionAuth->getMatrix();

		$actionKeys = array_keys($matrix);
		foreach ($this->seedActionKeys() as $key) {
			if (in_array($key, $actionKeys, true) === false) {
				$actionKeys[] = $key;
			}
		}

		sort($actionKeys);

		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = $group->getGID();
		}

		$seed = $this->readSeed();

		return new JSONResponse(
			[
				'matrix' => $matrix,
				'actions' => $actionKeys,
				'groups' => $groups,
				'labels' => $this->seedTexts(seed: $seed, member: 'labels'),
				'areas' => $this->seedTexts(seed: $seed, member: 'areas'),
			]
		);

	}//end getMatrix()

	/**
	 * Persist the action matrix.
	 *
	 * Reads the `matrix` parameter from the request body and writes it through
	 * the action authorization service (which normalizes the shape).
	 *
	 * @return JSONResponse The normalized matrix after the write.
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-matrix-is-editable-by-an-administrator-and-only-by-one
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setMatrix(): JSONResponse {
		$matrix = $this->request->getParam('matrix');
		if (is_array($matrix) === false) {
			$matrix = [];
		}

		try {
			$this->actionAuth->setMatrix($matrix);
		} catch (\JsonException $e) {
			return new JSONResponse(
				['error' => 'Could not encode the action matrix: ' . $e->getMessage()],
				\OCP\AppFramework\Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['matrix' => $this->actionAuth->getMatrix()]);
	}//end setMatrix()

	/**
	 * Read the action keys declared in the seed file.
	 *
	 * @return array<int, string>
	 */
	private function seedActionKeys(): array {
		$actions = ($this->readSeed()['actions'] ?? null);
		if (is_array($actions) === false) {
			return [];
		}

		$keys = [];
		foreach (array_keys($actions) as $key) {
			if (is_string($key) === true) {
				$keys[] = $key;
			}
		}

		return $keys;
	}//end seedActionKeys()

	/**
	 * A seed member that maps action names to text, such as `labels` or `areas`.
	 * Entries that are not text are left out.
	 *
	 * @param array<string, mixed> $seed   The decoded seed.
	 * @param string               $member The member.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
	 */
	private function seedTexts(array $seed, string $member): array {
		$texts = ($seed[$member] ?? null);
		if (is_array($texts) === false) {
			return [];
		}

		$result = [];
		foreach ($texts as $action => $text) {
			if (is_string($action) === true && is_string($text) === true && $text !== '') {
				$result[$action] = $text;
			}
		}

		return $result;
	}//end seedTexts()

	/**
	 * The decoded seed file; empty when it is missing or unreadable.
	 *
	 * @return array<string, mixed>
	 */
	private function readSeed(): array {
		if (file_exists(self::SEED_PATH) === false) {
			return [];
		}

		$raw = file_get_contents(self::SEED_PATH);
		if ($raw === false) {
			return [];
		}

		try {
			$parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return [];
		}

		if (is_array($parsed) === false) {
			return [];
		}

		return $parsed;
	}//end readSeed()
}//end class
