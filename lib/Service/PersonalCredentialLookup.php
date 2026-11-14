<?php

/**
 * PersonalCredentialLookup
 *
 * Finds a user's own connected accounts at a provider in OpenRegister's
 * credential broker: the enabled, personal credentials that user owns. Never
 * another user's and never an organisation credential (sources-per-user-oauth
 * D2). A caller that needs one account decides what zero or several mean.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * A user's own enabled personal credentials at a provider.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
class PersonalCredentialLookup {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service (credential metadata reads).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The UUIDs of the user's own enabled personal credentials at a provider.
	 *
	 * @param string $provider The provider slug.
	 * @param string $userId   The user.
	 *
	 * @return list<string> The credential UUIDs; empty without a user or a provider.
	 *
	 * @throws \Throwable When the broker's credentials cannot be read.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function credentialIds(string $provider, string $userId): array {
		if (trim($provider) === '' || trim($userId) === '') {
			return [];
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => BrokeredCallService::CREDENTIAL_REGISTER,
					'schema' => BrokeredCallService::CREDENTIAL_SCHEMA,
					'provider' => $provider,
				],
			]
		);

		$rows = ($matches['results'] ?? $matches);
		if (is_array($rows) === false) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			if (self::isUsable(row: $row, uid: $userId) === true) {
				$ids[] = (string)$row->getUuid();
			}
		}

		return $ids;
	}//end credentialIds()

	/**
	 * Whether a credential row is the user's own, personal, enabled credential.
	 *
	 * @param mixed  $row The row.
	 * @param string $uid The user.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001
	 */
	public static function isUsable(mixed $row, string $uid): bool {
		if ($row instanceof ObjectEntity === false || $row->getOwner() !== $uid) {
			return false;
		}

		$data = $row->getObject();

		return (string)($data['scope'] ?? 'personal') === 'personal' && (string)($data['status'] ?? '') !== 'disabled';
	}//end isUsable()
}//end class
