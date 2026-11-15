<?php

/**
 * WorkspaceUserMatcher: the Nextcloud user a workspace entry belongs to, by a declared mapping.
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
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

use OCP\IUser;
use OCP\IUserManager;

/**
 * Applies a source's declarative `userMapping` (REQ-EWC-003 fields
 * `ncUserAttribute`, `remoteUserAttribute`, `transformChain`) to an entry.
 *
 * Without a declared mapping, an address-shaped remote value is looked up by
 * e-mail and anything else by user id. A value that matches no user, or more
 * than one by e-mail, matches nobody: no user is ever guessed.
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
 */
class WorkspaceUserMatcher {

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Looks users up.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The user an entry maps to, or null.
	 *
	 * @param array<string, mixed> $entry   The workspace entry (`remoteUser`, `email`).
	 * @param array<string, mixed> $mapping The source's `userMapping`, or [].
	 *
	 * @return array{uid: string, displayName: string}|null
	 *
	 * @spec openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008
	 */
	public function match(array $entry, array $mapping = []): ?array {
		$value = $this->remoteValue(entry: $entry, attribute: (string)($mapping['remoteUserAttribute'] ?? ''));
		if ($value === null) {
			return null;
		}

		$transforms = ($mapping['transformChain'] ?? []);
		if (is_array($transforms) === true) {
			$value = self::transformed(value: $value, transforms: $transforms);
		}

		if ($value === '') {
			return null;
		}

		$attribute = (string)($mapping['ncUserAttribute'] ?? '');
		if ($attribute === '') {
			$attribute = 'uid';
			if (str_contains($value, '@') === true) {
				$attribute = 'email';
			}
		}

		$user = $this->userBy(attribute: $attribute, value: $value);
		if ($user === null) {
			return null;
		}

		return ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
	}//end match()

	/**
	 * The remote value the mapping names.
	 *
	 * @param array<string, mixed> $entry     The entry.
	 * @param string               $attribute `upn`, `username`, `samAccountName`, `email`, or '' for the default.
	 *
	 * @return string|null
	 */
	private function remoteValue(array $entry, string $attribute): ?string {
		$value = match ($attribute) {
			'email' => ($entry['email'] ?? null),
			'upn', 'username', 'samAccountName' => ($entry['remoteUser'] ?? null),
			'' => ($entry['email'] ?? $entry['remoteUser'] ?? null),
			default => null,
		};

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end remoteValue()

	/**
	 * The value after the named transforms, applied in order; unknown names are skipped.
	 *
	 * @param string            $value      The value.
	 * @param array<int, mixed> $transforms The transform names.
	 *
	 * @return string
	 */
	private static function transformed(string $value, array $transforms): string {
		foreach ($transforms as $transform) {
			$value = match ($transform) {
				'lowercase' => mb_strtolower($value),
				'strip-domain' => explode('@', $value, 2)[0],
				'strip-netbios' => substr((string)strrchr('\\' . $value, '\\'), 1),
				default => $value,
			};
		}

		return $value;
	}//end transformed()

	/**
	 * The one user with that attribute value, or null.
	 *
	 * @param string $attribute `uid` or `email`.
	 * @param string $value     The value.
	 *
	 * @return IUser|null
	 */
	private function userBy(string $attribute, string $value): ?IUser {
		if ($attribute === 'uid') {
			return $this->userManager->get($value);
		}

		if ($attribute !== 'email') {
			return null;
		}

		$users = $this->userManager->getByEmail($value);
		if (count($users) !== 1) {
			return null;
		}

		return array_values($users)[0];
	}//end userBy()
}//end class
