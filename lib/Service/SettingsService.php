<?php

/**
 * Integriq Settings Service
 *
 * The log retention integriq applies, read and changed on the admin page: the
 * settings read and update in the index and update shape of ADR-076, and the
 * "Apply to existing logs" action, which queues a background job over the
 * OpenRegister log objects.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use OCA\Integriq\BackgroundJob\RetentionRebaseJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and changes the log retention, and queues its rebase.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */
class SettingsService implements SettingsServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config  The `integriq` app config the retention is kept in.
	 * @param IJobList        $jobList Queues the rebase job.
	 * @param LoggerInterface $logger  Records a change and a queued rebase.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The retention every log kind gets now, its default, and the last rebase's progress.
	 *
	 * @return array{retention: array<string,int>, defaults: array<string,int>, rebase: array<string,mixed>}
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function getSettings(): array {
		$rebase = json_decode($this->config->getValueString(app: 'integriq', key: RetentionRebaseJob::PROGRESS_KEY, default: ''), true);
		if (is_array($rebase) === false) {
			$rebase = [];
		}

		return [
			'retention' => (new RetentionSettings(appConfig: $this->config))->all(),
			'defaults' => RetentionDefaults::BY_SETTING,
			'rebase' => $rebase,
		];
	}//end getSettings()

	/**
	 * Change the retention of one or more log kinds.
	 *
	 * Values are whole milliseconds, 0 keeping a log indefinitely. A key that
	 * is not a retention, or a value that is negative or not a whole number,
	 * refuses the whole update and names the field. Values not sent keep what
	 * they were.
	 *
	 * @param array<string,mixed> $payload `retention`, an object of setting key to milliseconds.
	 *
	 * @return array The settings after the change, as getSettings() returns them.
	 *
	 * @throws InvalidArgumentException When a field is unknown or its value is not a whole non-negative number.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function updateSettings(array $payload): array {
		$changes = ($payload['retention'] ?? null);
		if (is_array($changes) === false) {
			throw new InvalidArgumentException('retention: send an object of retention values.');
		}

		$resolver = new RetentionSettings(appConfig: $this->config);
		$stored = $resolver->stored();
		foreach ($changes as $field => $value) {
			$stored[(string)$field] = self::validRetention(field: (string)$field, value: $value);
		}

		$this->config->setValueString(app: 'integriq', key: RetentionSettings::CONFIG_KEY, value: (string)json_encode($stored));
		$this->logger->info('Integriq log retention changed', ['fields' => array_keys($changes)]);

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Queue the job that applies the current retention to existing logs.
	 *
	 * @param array<int,string> $kinds The log kinds to rebase; empty means every kind with an expiry.
	 *
	 * @return array{queued: bool, kinds: list<string>}
	 *
	 * @throws InvalidArgumentException When a kind has no logs to rebase.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function rebase(array $kinds=[]): array {
		if ($kinds === []) {
			$kinds = array_keys(RetentionRebaseJob::SCHEMAS);
		}

		foreach ($kinds as $kind) {
			if (is_string($kind) === false || isset(RetentionRebaseJob::SCHEMAS[$kind]) === false) {
				throw new InvalidArgumentException('kinds: ' . (string)json_encode($kind) . ' is not a log kind that can be rebased.');
			}
		}

		$kinds = array_values(array_unique($kinds));
		$this->config->setValueString(
			app: 'integriq',
			key: RetentionRebaseJob::PROGRESS_KEY,
			value: (string)json_encode(['status' => 'queued', 'kinds' => $kinds, 'processed' => 0, 'updated' => 0])
		);
		$this->jobList->add(RetentionRebaseJob::class, ['kinds' => $kinds, 'kind' => 0, 'offset' => 0]);
		$this->logger->info('Integriq retention rebase queued', ['kinds' => $kinds]);

		return ['queued' => true, 'kinds' => $kinds];
	}//end rebase()

	/**
	 * A retention value as it is stored, or the refusal naming the field.
	 *
	 * @param string $field The setting key.
	 * @param mixed  $value The value sent.
	 *
	 * @return int Milliseconds.
	 *
	 * @throws InvalidArgumentException When the field is unknown or the value is not a whole non-negative number.
	 */
	private static function validRetention(string $field, mixed $value): int {
		if (array_key_exists($field, RetentionDefaults::BY_SETTING) === false) {
			throw new InvalidArgumentException($field . ': not a retention setting.');
		}

		if (is_int($value) === false && (is_string($value) === false || preg_match('/^\d+$/', $value) !== 1)) {
			throw new InvalidArgumentException($field . ': must be a whole number of milliseconds, 0 or more.');
		}

		if ((int)$value < 0) {
			throw new InvalidArgumentException($field . ': must be a whole number of milliseconds, 0 or more.');
		}

		return (int)$value;
	}//end validRetention()
}//end class
