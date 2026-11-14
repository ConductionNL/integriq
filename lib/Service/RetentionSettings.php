<?php

/**
 * Integriq Log Retention Settings.
 *
 * The one place integriq reads how long a log is kept: the `integriq` /
 * `retention` app config key, each value falling back to the default the
 * log's schema declares ({@see RetentionDefaults}). The call, job and
 * synchronization services read their retention here, so an unset key means
 * the same thing to every writer and to the admin page.
 *
 * Values are milliseconds, the unit of the stored JSON, so a value set
 * through `occ` keeps meaning what it meant.
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

use InvalidArgumentException;
use OCP\IAppConfig;

/**
 * Resolves the retention of each log kind, in milliseconds.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
 */
class RetentionSettings {

	/**
	 * The app config key the retention JSON is stored under.
	 */
	public const CONFIG_KEY = 'retention';

	/**
	 * Each log kind and the key it has in the stored retention JSON.
	 *
	 * @var array<string,string>
	 */
	public const KINDS = [
		'success' => 'successLogRetention',
		'callLog' => 'callLogRetention',
		'jobLog' => 'jobLogRetention',
		'syncLog' => 'syncLogRetention',
		'syncContractLog' => 'syncContractLogRetention',
		'eventMessage' => 'eventMessageRetention',
		'executionTrace' => 'executionTraceRetention',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config the retention JSON is read from.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The retention of one log kind: the configured value, else the schema's default.
	 *
	 * @param string $kind One of the keys of KINDS.
	 *
	 * @return int Milliseconds; 0 keeps the log indefinitely.
	 *
	 * @throws InvalidArgumentException When the kind is unknown.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
	 */
	public function get(string $kind): int {
		$setting = (self::KINDS[$kind] ?? null);
		if ($setting === null) {
			throw new InvalidArgumentException('Unknown log kind "' . $kind . '".');
		}

		return $this->all()[$setting];
	}//end get()

	/**
	 * Every retention, keyed by the stored JSON's keys, defaults filled in.
	 *
	 * A stored value is taken as it was set through `occ`, a number or a
	 * numeric string; one that is not a non-negative number is ignored, as if
	 * it were not set, so a hand-edited key cannot make a log expire before
	 * it was written.
	 *
	 * @return array<string,int> Milliseconds per setting.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
	 */
	public function all(): array {
		$stored = $this->stored();
		$retention = [];
		foreach (RetentionDefaults::BY_SETTING as $setting => $default) {
			$retention[$setting] = $default;
			$value = ($stored[$setting] ?? null);
			if (is_numeric($value) === true && (float)$value >= 0) {
				$retention[$setting] = (int)$value;
			}
		}

		return $retention;
	}//end all()

	/**
	 * The stored retention JSON, decoded; empty when unset or unreadable.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
	 */
	public function stored(): array {
		$raw = $this->appConfig->getValueString(app: 'integriq', key: self::CONFIG_KEY, default: '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()
}//end class
