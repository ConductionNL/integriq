<?php

/**
 * Shared base of the endpoint/workspace adapters that list devices or sessions.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\EndpointWorkspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Lists what a workspace manager reports, as one entry shape for every vendor.
 *
 * Every call goes through the credential broker; without a configured
 * credential nothing is called and {@see self::listEntries()} answers null.
 * An entry is `id`, `kind` (`device` or `session`), `name`, `remoteUser`,
 * `email`, `lastSeen` and `state`, with null for what the vendor did not say.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
abstract class AbstractWorkspaceAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * Entry kind of a managed device.
	 */
	public const KIND_DEVICE = 'device';

	/**
	 * Entry kind of a user session.
	 */
	public const KIND_SESSION = 'session';

	/**
	 * The kind of entry this adapter lists; a subclass sets its own.
	 */
	protected const ENTRY_KIND = self::KIND_DEVICE;

	/**
	 * The key of the decoded answer that holds the vendor rows.
	 */
	protected const ROWS_KEY = 'value';

	/**
	 * The provider icon.
	 */
	protected const ICON = 'Laptop';

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $credentialBroker The credential broker every call goes through.
	 * @param IAppConfig              $appConfig        Holds the credential id.
	 * @param LoggerInterface         $logger           Logs broker failures.
	 * @param IL10N                   $l10n             Translations.
	 */
	public function __construct(
		CredentialBrokerService $credentialBroker,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		protected readonly IL10N $l10n,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
	}//end __construct()

	/**
	 * The kind of entry this adapter lists.
	 *
	 * @return string `device` or `session`.
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function entryKind(): string {
		return static::ENTRY_KIND;
	}//end entryKind()

	/**
	 * The provider icon.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getIcon(): string {
		return static::ICON;
	}//end getIcon()

	/**
	 * No app is required.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * The broker request that lists the entries.
	 *
	 * @param array<string, mixed> $settings The source's settings for this vendor.
	 *
	 * @return array{path: string, headers: array<string, string>}|null Null when the settings lack what the call needs.
	 */
	abstract protected function listRequest(array $settings): ?array;

	/**
	 * The vendor rows in a decoded answer: the list under {@see self::ROWS_KEY}.
	 *
	 * @param array<string, mixed> $decoded The decoded answer.
	 *
	 * @return array<int, mixed>
	 */
	protected function rowsOf(array $decoded): array {
		return array_values(self::arrayOf(value: ($decoded[static::ROWS_KEY] ?? [])));
	}//end rowsOf()

	/**
	 * One vendor row as an entry.
	 *
	 * @param array<string, mixed> $row The vendor row.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function entryOf(array $row): array;

	/**
	 * What the vendor reports, or null when no credential is configured.
	 *
	 * An answer the broker could not get, a non-2xx status or a body that is
	 * not the expected list gives an empty list.
	 *
	 * @param array<string, mixed> $settings The source's settings for this vendor.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function listEntries(array $settings = []): ?array {
		if ($this->getCredentialId() === null) {
			return null;
		}

		$request = $this->listRequest(settings: $settings);
		if ($request === null) {
			return [];
		}

		$response = $this->brokeredRequest(method: 'GET', path: $request['path'], headers: $request['headers']);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return [];
		}

		$decoded = json_decode((string)$response['body'], true);
		if (is_array($decoded) === false) {
			return [];
		}

		$entries = [];
		foreach ($this->rowsOf(decoded: $decoded) as $row) {
			if (is_array($row) === true) {
				$entries[] = ['kind' => $this->entryKind()] + $this->entryOf(row: $row);
			}
		}

		return $entries;
	}//end listEntries()

	/**
	 * The integration-registry listing: the entries, or none without a credential.
	 *
	 * @param string               $register The register (unused).
	 * @param string               $schema   The schema (unused).
	 * @param string               $objectId The object (unused).
	 * @param array<string, mixed> $filters  The vendor settings.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the interface's signature
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return ($this->listEntries(settings: $filters) ?? []);
	}//end list()

	/**
	 * A JSON GET request for the path, with any extra headers.
	 *
	 * @param string                $path    The path, relative to the credential's base URL.
	 * @param array<string, string> $headers Extra headers.
	 *
	 * @return array{path: string, headers: array<string, string>}
	 */
	protected static function jsonRequest(string $path, array $headers = []): array {
		return ['path' => $path, 'headers' => ['Accept' => 'application/json'] + $headers];
	}//end jsonRequest()

	/**
	 * A nested object of a vendor row, [] when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	protected static function arrayOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOf()

	/**
	 * A string field, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	protected static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()

	/**
	 * A required setting as a non-empty string, or null.
	 *
	 * @param array<string, mixed> $settings The settings.
	 * @param string               $key      The key.
	 *
	 * @return string|null
	 */
	protected static function setting(array $settings, string $key): ?string {
		$value = ($settings[$key] ?? null);
		if (is_scalar($value) === false || trim((string)$value) === '') {
			return null;
		}

		return trim((string)$value);
	}//end setting()
}//end class
