<?php

/**
 * Integriq ConnectionStatusResolver.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Works out a connection row's `status`, `statusMessage` and `checkedAt` by the
 * rules of the hydra umbrella design D4, first match wins:
 *
 *   1.  the declaring app is disabled                  -> unavailable, now
 *   2.  the declaration says `available: false`        -> unavailable, sync time
 *   2b. `switch` is set and reads as off               -> disabled, now
 *   3.  not `reportedOnly`, and the adapter value is
 *       one of `adapter.simulatedValues`               -> simulated, sync time
 *   4a. `lastReport.status` is `simulated`, and the
 *       report is not older than `refreshedAt`         -> simulated, its time
 *   4b. a `lastProbe` or `lastReport` exists that is
 *       not older than `refreshedAt`                   -> the newer one, its time
 *   5.  not `reportedOnly`, and every `requiredConfig`
 *       entry is filled                                -> configured, now
 *   6.  otherwise                                      -> unconfigured, empty
 *
 * Rule 6 uses the declared `unconfiguredMessage` when there is one, and rule
 * 5 says "Required settings are filled." rather than "saved": a register
 * import can fill the keys too (umbrella amendment from dossiq#2715).
 *
 * Rule 3 sits above rule 4 on purpose. A mock adapter never calls the source,
 * so a green probe says nothing about what the app actually sends. Rule 4a
 * applies the same reasoning to the app's own report (umbrella D12).
 *
 * Rule 2b sits above rules 3 and 4, and applies to `reportedOnly` rows too.
 * An admin turned the connection off on purpose, so an old error or a mock
 * adapter says nothing about it (umbrella D4, D12 item 9). Its outcome carries
 * rule number 2, like rule 2.
 *
 * Rules 4a and 4b count only a report or probe that is not older than the
 * row's `refreshedAt`. A refresh follows a settings save, so an observation
 * from before it judged settings that no longer hold (umbrella D6). An equal
 * time still counts.
 *
 * Reading the declaring app's config, including `adapter.jsonPath` and
 * `adapter.simulatedValues`, lives in {@see ConnectionConfigReader}.
 * No declaration produces `limited`; only a report can.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The D4 status rules.
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */
class ConnectionStatusResolver {

	/**
	 * The seven stored status values.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['configured', 'limited', 'unconfigured', 'simulated', 'disabled', 'unavailable', 'error'];

	/**
	 * The row property that holds integriq's own probe; the other observation is the app's report.
	 *
	 * @var string
	 */
	private const PROBE_PROPERTY = 'lastProbe';

	/**
	 * Reads the declaring app's settings.
	 *
	 * @var ConnectionConfigReader
	 */
	private readonly ConnectionConfigReader $config;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the declaring app's settings.
	 * @param ITimeFactory $timeFactory The clock.
	 */
	public function __construct(
		IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
	) {
		$this->config = new ConnectionConfigReader(appConfig: $appConfig);
	}//end __construct()

	/**
	 * Resolve one row.
	 *
	 * The returned `checkedAt` for rules 2, 2b, 3 and 5 keeps the row's stored
	 * value while its status and message stay the same. That is how "sync time"
	 * is read here: the time of the sync that first gave the row this status.
	 * Re-running a sync then changes nothing.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 * @param bool $appEnabled Whether the declaring app is enabled.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
	 */
	public function resolve(array $row, bool $appEnabled): array {
		$check = new ConnectionStatusCheck(row: $row, now: $this->now());

		return $this->ruleAppDisabled(check: $check, appEnabled: $appEnabled)
			?? $this->ruleDeclaredUnavailable(check: $check)
			?? $this->ruleSwitchedOff(check: $check)
			?? $this->ruleSimulated(check: $check)
			?? $this->ruleReportedSimulated(row: $row)
			?? $this->ruleObserved(row: $row)
			?? $this->ruleSettingsSaved(check: $check)
			?? $this->ruleUnconfigured(check: $check);
	}//end resolve()

	/**
	 * Rule 1: the declaring app is disabled.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 * @param bool $appEnabled Whether the declaring app is enabled.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleAppDisabled(ConnectionStatusCheck $check, bool $appEnabled): ?array {
		if ($appEnabled === true) {
			return null;
		}

		$verdict = new ConnectionVerdict(
			status: 'unavailable',
			message: 'The ' . (string)($check->row['app'] ?? '') . ' app is disabled.'
		);
		$verdict->checkedAt = $check->now;

		return $this->outcome(verdict: $verdict, rule: 1);
	}//end ruleAppDisabled()

	/**
	 * Rule 2: declared, not usable yet.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleDeclaredUnavailable(ConnectionStatusCheck $check): ?array {
		if (($check->declaration['available'] ?? true) !== false) {
			return null;
		}

		$verdict = new ConnectionVerdict(
			status: 'unavailable',
			message: $this->nonEmptyString(value: $check->declaration['unavailableMessage'] ?? null, fallback: 'Declared, not built yet.')
		);

		return $this->outcome(verdict: $this->keepOrNow(check: $check, verdict: $verdict), rule: 2);
	}//end ruleDeclaredUnavailable()

	/**
	 * Rule 2b: the declared switch reads as off, so an admin turned the connection off.
	 *
	 * Applies to a `reportedOnly` row too: the switch is a fact integriq can read.
	 * "Now" keeps the stored time while status and message stay the same, as
	 * rule 5 does, so a sync over a row that stays off changes nothing.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-a-connection-switched-off-reads-disabled
	 */
	private function ruleSwitchedOff(ConnectionStatusCheck $check): ?array {
		$app = (string)($check->row['app'] ?? '');
		if ($this->config->isSwitchedOff(app: $app, switch: $check->declaration['switch'] ?? null) === false) {
			return null;
		}

		$verdict = new ConnectionVerdict(
			status: 'disabled',
			message: $this->nonEmptyString(value: $check->declaration['disabledMessage'] ?? null, fallback: 'Switched off in ' . $app . "'s settings.")
		);

		return $this->outcome(verdict: $this->keepOrNow(check: $check, verdict: $verdict), rule: 2);
	}//end ruleSwitchedOff()

	/**
	 * Rule 3: the adapter value is one of the simulated values, so a mock answers.
	 *
	 * Skipped for a `reportedOnly` row: only the app can tell what answers there.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleSimulated(ConnectionStatusCheck $check): ?array {
		$adapter = $this->simulatedAdapter(check: $check);
		if ($adapter === null) {
			return null;
		}

		$verdict = new ConnectionVerdict(
			status: 'simulated',
			message: $this->nonEmptyString(
				value: $adapter['simulatedMessage'] ?? null,
				fallback: 'A mock adapter answers here. Set ' . (string)($adapter['configKey'] ?? '') . ' to a real adapter.'
			)
		);

		return $this->outcome(verdict: $this->keepOrNow(check: $check, verdict: $verdict), rule: 3);
	}//end ruleSimulated()

	/**
	 * The declared adapter, when rule 3 applies to it: not reported-only, and
	 * the app's adapter value is one of the simulated values.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array<string,mixed>|null The adapter entry, or null when rule 3 does not apply.
	 */
	private function simulatedAdapter(ConnectionStatusCheck $check): ?array {
		$adapter = $check->declaration['adapter'] ?? null;
		if (is_array($adapter) === false || $this->isReportedOnly(declaration: $check->declaration) === true) {
			return null;
		}

		if ($this->config->isSimulated(app: (string)($check->row['app'] ?? ''), adapter: $adapter) === false) {
			return null;
		}

		return $adapter;
	}//end simulatedAdapter()

	/**
	 * Rule 4a: the app reported that a mock answers.
	 *
	 * The report stands against any probe, newer ones included: a green test
	 * on the source says nothing about what a mock adapter sends. The outcome
	 * carries rule number 4, like rule 4b.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleReportedSimulated(array $row): ?array {
		$report = $this->readObservation(row: $row, property: 'lastReport');
		if ($report === null || $report['status'] !== 'simulated') {
			return null;
		}

		return $this->observedOutcome(observation: $report, rule: 4);
	}//end ruleReportedSimulated()

	/**
	 * Rule 4b: the newer of the last probe and the last report.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleObserved(array $row): ?array {
		$observation = $this->newestObservation(row: $row);
		if ($observation === null) {
			return null;
		}

		return $this->observedOutcome(observation: $observation, rule: 4);
	}//end ruleObserved()

	/**
	 * Rule 5: every required setting holds a filled value.
	 *
	 * Skipped for a `reportedOnly` row: filled settings say nothing about a
	 * platform chosen elsewhere.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}|null
	 */
	private function ruleSettingsSaved(ConnectionStatusCheck $check): ?array {
		$required = $this->requiredSettings(check: $check);
		if ($required === null) {
			return null;
		}

		if ($this->config->allFilled(app: (string)($check->row['app'] ?? ''), entries: $required) === false) {
			return null;
		}

		$verdict = new ConnectionVerdict(status: 'configured', message: 'Required settings are filled.');

		return $this->outcome(verdict: $this->keepOrNow(check: $check, verdict: $verdict), rule: 5);
	}//end ruleSettingsSaved()

	/**
	 * The declared required settings, when rule 5 applies: a non-empty list on
	 * a row that is not reported-only.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array<mixed>|null The `requiredConfig` entries, or null when rule 5 does not apply.
	 */
	private function requiredSettings(ConnectionStatusCheck $check): ?array {
		$required = $check->declaration['requiredConfig'] ?? [];
		if (is_array($required) === false || $required === [] || $this->isReportedOnly(declaration: $check->declaration) === true) {
			return null;
		}

		return $required;
	}//end requiredSettings()

	/**
	 * Rule 6: nothing else matched.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}
	 */
	private function ruleUnconfigured(ConnectionStatusCheck $check): array {
		$verdict = new ConnectionVerdict(
			status: 'unconfigured',
			message: $this->nonEmptyString(value: $check->declaration['unconfiguredMessage'] ?? null, fallback: 'Not checked yet.')
		);

		return $this->outcome(verdict: $verdict, rule: 6);
	}//end ruleUnconfigured()

	/**
	 * Pick the newer of `lastProbe` and `lastReport`, of those a refresh did not retire.
	 *
	 * A probe's `ok` reads as `configured`. On an equal time the probe wins,
	 * because it is integriq's own observation.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 *
	 * @return array{status:string,message:string,at:?string}|null The observation, or null when there is none.
	 */
	private function newestObservation(array $row): ?array {
		$probe = $this->readObservation(row: $row, property: 'lastProbe');
		$report = $this->readObservation(row: $row, property: 'lastReport');

		if ($probe === null) {
			return $report;
		}

		if ($report === null) {
			return $probe;
		}

		if ($report['time'] > $probe['time']) {
			return $report;
		}

		return $probe;
	}//end newestObservation()

	/**
	 * Read one stored observation, or null when it is missing, invalid or retired.
	 *
	 * An observation retires when its time is older than the row's `refreshedAt`.
	 * An equal time still counts. An observation without a time is older than
	 * any refresh. A missing or unreadable `refreshedAt` retires nothing.
	 * `lastProbe` reads as a probe (ok|error), anything else as a report (the
	 * seven statuses).
	 *
	 * @param array<string,mixed> $row The stored row data.
	 * @param string $property The row property, `lastProbe` or `lastReport`.
	 *
	 * @return array{status:string,message:string,at:?string,time:int}|null
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 * @spec openspec/specs/connection-registry/spec.md#scenario-a-report-after-the-refresh-counts-again
	 */
	private function readObservation(array $row, string $property): ?array {
		$value = $row[$property] ?? null;
		if (is_array($value) === false) {
			return null;
		}

		$status = $this->observationStatus(status: (string)($value['status'] ?? ''), isProbe: $property === self::PROBE_PROPERTY);
		if ($status === null) {
			return null;
		}

		$observation = $this->timedObservation(value: $value, status: $status);
		if ($observation['time'] < $this->refreshTime(row: $row)) {
			return null;
		}

		return $observation;
	}//end readObservation()

	/**
	 * An observation with its message and, when it carries one, its time.
	 *
	 * @param array<string,mixed> $value The stored probe or report.
	 * @param string $status The mapped status.
	 *
	 * @return array{status:string,message:string,at:?string,time:int}
	 */
	private function timedObservation(array $value, string $status): array {
		$observation = ['status' => $status, 'message' => (string)($value['message'] ?? ''), 'at' => null, 'time' => 0];
		$observedAt = $value['at'] ?? null;
		if (is_string($observedAt) === true && $observedAt !== '') {
			$observation['at'] = $observedAt;
			$observation['time'] = (int)strtotime($observedAt);
		}

		return $observation;
	}//end timedObservation()

	/**
	 * The row's `refreshedAt` as a Unix time, or 0 when it is missing or unreadable.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 *
	 * @return int
	 */
	private function refreshTime(array $row): int {
		$refreshedAt = $row['refreshedAt'] ?? null;
		if (is_string($refreshedAt) === false) {
			return 0;
		}

		return (int)strtotime($refreshedAt);
	}//end refreshTime()

	/**
	 * Map a stored observation status onto a row status.
	 *
	 * @param string $status The stored status.
	 * @param bool $isProbe Whether the observation is a probe.
	 *
	 * @return string|null The row status, or null when the value is not valid for its kind.
	 */
	private function observationStatus(string $status, bool $isProbe): ?string {
		if ($isProbe === true) {
			return match ($status) {
				'ok' => 'configured',
				'error' => 'error',
				default => null,
			};
		}

		if (in_array($status, self::STATUSES, true) === true) {
			return $status;
		}

		return null;
	}//end observationStatus()

	/**
	 * Whether the declaration says only the app can judge this connection.
	 *
	 * @param array<string,mixed> $declaration The declaration entry.
	 *
	 * @return bool
	 */
	private function isReportedOnly(array $declaration): bool {
		return ($declaration['reportedOnly'] ?? false) === true;
	}//end isReportedOnly()

	/**
	 * Stamp a verdict: keep the stored `checkedAt` when status and message did
	 * not change, otherwise the current time.
	 *
	 * @param ConnectionStatusCheck $check The row being resolved.
	 * @param ConnectionVerdict $verdict The resolved status and message.
	 *
	 * @return ConnectionVerdict The same verdict, stamped.
	 */
	private function keepOrNow(ConnectionStatusCheck $check, ConnectionVerdict $verdict): ConnectionVerdict {
		$row = $check->row;
		$stored = $row['checkedAt'] ?? null;
		$verdict->checkedAt = $check->now;
		if (($row['status'] ?? null) === $verdict->status
			&& ($row['statusMessage'] ?? null) === $verdict->message
			&& is_string($stored) === true
			&& $stored !== ''
		) {
			$verdict->checkedAt = $stored;
		}

		return $verdict;
	}//end keepOrNow()

	/**
	 * A string value, or the fallback when it is empty or not a string.
	 *
	 * @param mixed $value The value.
	 * @param string $fallback The fallback.
	 *
	 * @return string
	 */
	private function nonEmptyString(mixed $value, string $fallback): string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return $fallback;
	}//end nonEmptyString()

	/**
	 * Build the outcome array.
	 *
	 * @param ConnectionVerdict $verdict The status, message and observation time.
	 * @param int $rule The D4 rule number that matched.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}
	 */
	private function outcome(ConnectionVerdict $verdict, int $rule): array {
		return [
			'status' => $verdict->status,
			'statusMessage' => $verdict->message,
			'checkedAt' => $verdict->checkedAt,
			'rule' => $rule,
		];
	}//end outcome()

	/**
	 * Build the outcome of rule 4 from an observation, at its own time.
	 *
	 * @param array{status:string,message:string,at:?string} $observation The observation.
	 * @param int $rule The D4 rule number that matched.
	 *
	 * @return array{status:string,statusMessage:string,checkedAt:?string,rule:int}
	 */
	private function observedOutcome(array $observation, int $rule): array {
		$verdict = new ConnectionVerdict(status: $observation['status'], message: $observation['message']);
		$verdict->checkedAt = $observation['at'];

		return $this->outcome(verdict: $verdict, rule: $rule);
	}//end observedOutcome()

	/**
	 * The current time as ISO 8601.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
	 */
	public function now(): string {
		return $this->timeFactory->now()->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
