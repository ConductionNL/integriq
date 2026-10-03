<?php

/**
 * Integriq Log Retention Defaults.
 *
 * The retention each log kind gets when the `integriq` / `retention` app
 * config key does not set it. One table, read by the settings read
 * ({@see SettingsService::getSettings()}) and by the writers that stamp an
 * expiry on synchronization and contract logs, so the value an administrator
 * is shown is the value the logs get.
 *
 * Before this, the settings read reported 30 days for synchronization logs
 * and 90 days for contract logs, while SynchronizationService stamped both
 * with its own 3-day fallback (integriq#2210). Both log schemas declare 30
 * days, so both are 30 days here.
 *
 * Values are in milliseconds, the unit of the stored `retention` JSON, so a
 * value set through `occ` keeps meaning what it meant.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Default log retention per kind, in milliseconds.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
 */
final class RetentionDefaults {

	/**
	 * Successful call, job and synchronization logs: one hour.
	 *
	 * @var int
	 */
	public const SUCCESS_LOG = 3600000;

	/**
	 * Call logs: 30 days.
	 *
	 * @var int
	 */
	public const CALL_LOG = 2592000000;

	/**
	 * Event messages: 7 days.
	 *
	 * @var int
	 */
	public const EVENT_MESSAGE = 604800000;

	/**
	 * Job logs: 30 days.
	 *
	 * @var int
	 */
	public const JOB_LOG = 2592000000;

	/**
	 * Synchronization contract logs: 30 days, the default the
	 * `synchronization_contract_log` schema declares
	 * (`x-openregister-archival.retention.default` P30D).
	 *
	 * @var int
	 */
	public const SYNC_CONTRACT_LOG = 2592000000;

	/**
	 * Synchronization logs: 30 days.
	 *
	 * @var int
	 */
	public const SYNC_LOG = 2592000000;

	/**
	 * The defaults keyed by the `retention` JSON's own keys, in the order the settings read returns them.
	 *
	 * @var array<string,int>
	 */
	public const BY_SETTING = [
		'successLogRetention' => self::SUCCESS_LOG,
		'callLogRetention' => self::CALL_LOG,
		'eventMessageRetention' => self::EVENT_MESSAGE,
		'jobLogRetention' => self::JOB_LOG,
		'syncContractLogRetention' => self::SYNC_CONTRACT_LOG,
		'syncLogRetention' => self::SYNC_LOG,
	];
}//end class
