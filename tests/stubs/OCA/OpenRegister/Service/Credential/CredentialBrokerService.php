<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialBrokerService.
 *
 * Signature verified against openregister origin/development
 * (lib/Service/Credential/CredentialBrokerService.php): request() takes
 * credentialId, appId, method, path, headers, body, actingUserId — the
 * optional acting-user parameter shipped with OR change
 * `credential-doriath-leaf` and is now on origin/development, so the stub
 * mirrors it (the adapter tests assert the full 7-argument invocation).
 * BrokeredCallService's reflection-based acting-user feature detection is
 * exercised against dedicated Fake*Broker classes inside its own test, so
 * both broker generations stay covered regardless of this stub's shape.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Stub of the constrained, host-locked, secret-injecting outbound broker.
 */
class CredentialBrokerService {

	/**
	 * Register slug holding `credential` metadata objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'credential-broker';

	/**
	 * Schema slug for `credential` metadata objects.
	 *
	 * @var string
	 */
	public const SCHEMA = 'brokeredcredential';

	/**
	 * Broker a single constrained outbound call on a credential's behalf.
	 *
	 * @param string $credentialId The `credential` object UUID.
	 * @param string $appId The authenticated calling app id.
	 * @param string $method The HTTP method (e.g. `GET`).
	 * @param string $path The provider-relative path.
	 * @param array<string, string> $headers Optional extra request headers.
	 * @param string|null $body Optional raw request body.
	 * @param string|null $actingUserId Optional acting user for in-process trusted callers (credential-doriath-leaf).
	 *
	 * @return array{status: int, headers: array<string, mixed>, body: string} The upstream response.
	 */
	public function request(
		string $credentialId,
		string $appId,
		string $method,
		string $path,
		array $headers = [],
		?string $body = null,
		?string $actingUserId = null,
	): array {
		return [
			'status' => 200,
			'headers' => [],
			'body' => '',
		];

	}//end request()

	/**
	 * Mint a credential (sessionless; openregister#440).
	 *
	 * Signature verified against openregister origin/development
	 * (lib/Service/Credential/CredentialBrokerService.php::mint): name, provider,
	 * owner, allowedApps, secret, scope, organisation. InlineSecretMigrationExecutor
	 * feature-detects mint() via method_exists() and calls it POSITIONALLY, so the
	 * parameter names are not load-bearing here — only the arity/order is.
	 *
	 * @param string $name The human-readable credential name.
	 * @param string $provider The provider identifier.
	 * @param string $owner The owning user's UID.
	 * @param array<int, string> $allowedApps The app ids permitted to use this credential.
	 * @param string|null $secret The raw secret (or null to mint metadata only).
	 * @param string $scope The resolved scope (`personal`|`organisation`).
	 * @param string|null $organisation The owning organisation UUID (required for organisation scope).
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The persisted credential entity.
	 */
	public function mint(
		string $name,
		string $provider,
		string $owner,
		array $allowedApps = [],
		?string $secret = null,
		string $scope = 'personal',
		?string $organisation = null,
	): \OCA\OpenRegister\Db\ObjectEntity {
		return new \OCA\OpenRegister\Db\ObjectEntity();
	}//end mint()

	/**
	 * Resolve an inject-only credential's raw secret (openregister#450 arity).
	 *
	 * The 4th parameter `actingOrganisationId` is what makes sessionless
	 * organisation-scoped resolution work; InlineSecretMigrationExecutor
	 * feature-detects it by reflection on the parameter NAME, so this stub must
	 * carry the exact name.
	 *
	 * @param string $credentialId The `credential` object UUID.
	 * @param string $appId The authenticated calling app id.
	 * @param string|null $actingUserId Optional asserted user for sessionless in-process callers.
	 * @param string|null $actingOrganisationId Optional asserted organisation for sessionless organisation resolution.
	 *
	 * @return string|null The raw secret, or null when the credential is a proxy credential.
	 */
	public function resolveInjectable(
		string $credentialId,
		string $appId,
		?string $actingUserId = null,
		?string $actingOrganisationId = null,
	): ?string {
		return null;
	}//end resolveInjectable()

}//end class
