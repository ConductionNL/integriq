<?php

/**
 * PersonalCredentialLookup: the one broker credential a person holds at a provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds a person's own delegated grant: their personal, enabled credential at a provider.
 *
 * The rule is the one a per-user source applies (sources-per-user-oauth): the
 * credential's owner is the person, its scope is `personal` and it is not
 * disabled. Exactly one such credential is the grant. None, more than one, or a
 * lookup that fails answers null, so a caller refuses instead of guessing or
 * falling back to a shared credential.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
class PersonalCredentialLookup {

	/**
	 * The credential broker's register.
	 */
	public const CREDENTIAL_REGISTER = 'credential-broker';

	/**
	 * The credential broker's credential schema.
	 */
	public const CREDENTIAL_SCHEMA = 'brokeredcredential';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger        Logs a failed lookup.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The id of the person's one personal credential at the provider.
	 *
	 * @param string $provider The provider slug, e.g. `microsoft-entra`.
	 * @param string $userId   The person.
	 *
	 * @return string|null The credential id, or null when the person has none or more than one.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function credentialIdFor(string $provider, string $userId): ?string {
		if (trim($provider) === '' || trim($userId) === '') {
			return null;
		}

		$owned = array_values(
			array_filter(
				$this->candidates(provider: $provider),
				static fn (mixed $row): bool => self::isUsable(row: $row, userId: $userId)
			)
		);
		if (count($owned) !== 1) {
			return null;
		}

		$uuid = (string)$owned[0]->getUuid();
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end credentialIdFor()

	/**
	 * The broker's credentials at a provider, before the owner filter.
	 *
	 * @param string $provider The provider slug.
	 *
	 * @return array<int, mixed> The rows; empty when the lookup fails.
	 */
	private function candidates(string $provider): array {
		$filters = ['register' => self::CREDENTIAL_REGISTER, 'schema' => self::CREDENTIAL_SCHEMA, 'provider' => $provider];
		try {
			$matches = $this->objectService->findAll(config: ['filters' => $filters]);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: 'The personal credentials at {provider} could not be read: {error}',
				context: ['app' => 'integriq', 'provider' => $provider, 'error' => $exception->getMessage()]
			);
			return [];
		}

		if (isset($matches['results']) === true && is_array($matches['results']) === true) {
			return array_values($matches['results']);
		}

		return array_values($matches);
	}//end candidates()

	/**
	 * Whether a credential row is the person's own, personal, enabled credential.
	 *
	 * @param mixed  $row    The row.
	 * @param string $userId The person.
	 *
	 * @return bool
	 */
	private static function isUsable(mixed $row, string $userId): bool {
		if ($row instanceof ObjectEntity === false || $row->getOwner() !== $userId) {
			return false;
		}

		$data = $row->getObject();

		return (string)($data['scope'] ?? 'personal') === 'personal' && (string)($data['status'] ?? '') !== 'disabled';
	}//end isUsable()
}//end class
