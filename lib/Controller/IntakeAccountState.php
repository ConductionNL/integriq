<?php

/**
 * IntakeAccountState
 *
 * Resolves the Nextcloud account an intake connection acts as, for the
 * settings forms of the DSO and Open Formulieren connections: its one-line
 * state, and the field error when it does not exist or is disabled.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-dso-connections-account-is-chosen-and-checked-by-an-administrator-req-dso-071
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCP\IL10N;

/**
 * The state of an intake connection's account.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-dso-connections-account-is-chosen-and-checked-by-an-administrator-req-dso-071
 */
final class IntakeAccountState {

	/**
	 * Constructor.
	 *
	 * @param PortalConnectionInterface|OpenFormulierenConnection $connection Resolves the account.
	 * @param IL10N                                               $l          Field errors.
	 */
	public function __construct(
		private readonly PortalConnectionInterface|OpenFormulierenConnection $connection,
		private readonly IL10N $l,
	) {

	}//end __construct()

	/**
	 * Describe the account for the admin section's one-line state.
	 *
	 * @param string $userId The uid on the consumer, or ''.
	 *
	 * @return array{state: string, displayName: string} `ok`, `none`, `unknown` or `disabled`.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-dso-connections-account-is-chosen-and-checked-by-an-administrator-req-dso-071
	 */
	public function describe(string $userId): array {
		if ($userId === '') {
			return ['state' => 'none', 'displayName' => ''];
		}

		try {
			$account = $this->connection->resolveAccount(userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			$state = 'unknown';
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				$state = 'disabled';
			}

			return ['state' => $state, 'displayName' => $userId];
		}

		return ['state' => 'ok', 'displayName' => $account->getDisplayName()];

	}//end describe()

	/**
	 * The field error when the account does not exist or is disabled, or null.
	 *
	 * @param string $userId The chosen uid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-an-account-without-rights-is-refused
	 */
	public function unresolvable(string $userId): ?string {
		try {
			$this->connection->resolveAccount(userId: $userId);
		} catch (DsoConnectionUnavailableException $exception) {
			if ($exception->getReason() === DsoConnectionUnavailableException::ACCOUNT_DISABLED) {
				return $this->l->t('Account %s is disabled.', [$userId]);
			}

			return $this->l->t('Account %s does not exist.', [$userId]);
		}

		return null;

	}//end unresolvable()
}//end class
