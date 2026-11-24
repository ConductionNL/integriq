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
 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use LogicException;
use OCA\Integriq\BackgroundJob\RetentionRebaseSchedulerInterface;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and changes the log retention, and queues its rebase.
 *
 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */
class SettingsService implements SettingsServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                        $config          The `integriq` app config the retention is kept in.
	 * @param RetentionRebaseSchedulerInterface $rebaseScheduler Queues the rebase job.
	 * @param LoggerInterface                   $logger          Records a change and a queued rebase.
	 * @param ErrorSinkSettings|null            $errorSink       Reads, validates and stores the `errorsink_*` keys; the container always gives it.
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly RetentionRebaseSchedulerInterface $rebaseScheduler,
		private readonly LoggerInterface $logger,
		private readonly ?ErrorSinkSettings $errorSink = null,
	) {
	}//end __construct()

	/**
	 * The error sink settings (`errorsink_*`), for the admin page.
	 *
	 * @return array<string, mixed> enabled, kind, endpoint, allowLocal, minLevel, apps, headerName, credentialName.
	 *
	 * @spec openspec/changes/archive/2026-11-24-observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function getErrorSinkSettings(): array {
		return $this->errorSink()->all();

	}//end getErrorSinkSettings()

	/**
	 * Store the error sink settings an administrator saved. An enabled sink
	 * needs an endpoint or DSN, and a plain-http address is only taken when
	 * the administrator marked the service as internal, as for the trace
	 * collector (REQ-OTEL-005). A refused save stores nothing.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return array<string, mixed> The stored settings.
	 *
	 * @throws \InvalidArgumentException When a value is refused; the message is the translated reason.
	 *
	 * @spec openspec/changes/archive/2026-11-24-observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function saveErrorSinkSettings(array $values): array {
		return $this->errorSink()->save(values: $values);

	}//end saveErrorSinkSettings()

	/**
	 * The error sink settings store.
	 *
	 * @return ErrorSinkSettings The store.
	 *
	 * @throws LogicException When the service was built without one.
	 */
	private function errorSink(): ErrorSinkSettings {
		if ($this->errorSink === null) {
			throw new LogicException('SettingsService was built without the error sink settings.');
		}

		return $this->errorSink;

	}//end errorSink()

	/**
	 * The retention every log kind gets now, its default, and the last rebase's progress.
	 *
	 * @return array{retention: array<string,int>, defaults: array<string,int>, rebase: array<string,mixed>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function getSettings(): array {
		$rebase = json_decode($this->config->getValueString(app: 'integriq', key: RetentionSettings::REBASE_PROGRESS_KEY, default: ''), true);
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
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
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
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function rebase(array $kinds=[]): array {
		$kinds = self::rebaseKinds(kinds: $kinds);
		$this->config->setValueString(
			app: 'integriq',
			key: RetentionSettings::REBASE_PROGRESS_KEY,
			value: (string)json_encode(['status' => 'queued', 'kinds' => $kinds, 'processed' => 0, 'updated' => 0])
		);
		$this->rebaseScheduler->scheduleRebase(kinds: $kinds);
		$this->logger->info('Integriq retention rebase queued', ['kinds' => $kinds]);

		return ['queued' => true, 'kinds' => $kinds];
	}//end rebase()

	/**
	 * The distinct log kinds a rebase covers; every kind with an expiry when none is named.
	 *
	 * @param array<int,mixed> $kinds The requested kinds.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When a kind has no logs to rebase.
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private static function rebaseKinds(array $kinds): array {
		if ($kinds === []) {
			$kinds = array_keys(RetentionSettings::REBASE_SCHEMAS);
		}

		foreach ($kinds as $kind) {
			if (is_string($kind) === false || isset(RetentionSettings::REBASE_SCHEMAS[$kind]) === false) {
				throw new InvalidArgumentException('kinds: ' . (string)json_encode($kind) . ' is not a log kind that can be rebased.');
			}
		}

		return array_values(array_unique($kinds));
	}//end rebaseKinds()

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
