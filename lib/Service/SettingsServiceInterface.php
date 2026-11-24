<?php

/**
 * Contract of the SettingsService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see SettingsService} that other components call.
 */
interface SettingsServiceInterface {
	/**
	 * The retention every log kind gets now, its default, and the last rebase's progress.
	 *
	 * @return array{retention: array<string,int>, defaults: array<string,int>, rebase: array<string,mixed>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function getSettings(): array;

	/**
	 * Change the retention of one or more log kinds.
	 *
	 * @param array<string,mixed> $payload `retention`, an object of setting key to milliseconds.
	 *
	 * @return array The settings after the change.
	 *
	 * @throws \InvalidArgumentException When a field is unknown or its value is not a whole non-negative number.
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function updateSettings(array $payload): array;

	/**
	 * Queue the job that applies the current retention to existing logs.
	 *
	 * @param array<int,string> $kinds The log kinds to rebase; empty means every kind with an expiry.
	 *
	 * @return array{queued: bool, kinds: list<string>}
	 *
	 * @throws \InvalidArgumentException When a kind has no logs to rebase.
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function rebase(array $kinds=[]): array;

	/**
	 * The error sink settings (`errorsink_*`), for the admin page.
	 *
	 * @return array<string, mixed> enabled, kind, endpoint, allowLocal, minLevel, apps, headerName, credentialName.
	 *
	 * @spec openspec/changes/archive/2026-11-24-observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function getErrorSinkSettings(): array;

	/**
	 * Store the error sink settings an administrator saved.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return array<string, mixed> The stored settings.
	 *
	 * @throws \InvalidArgumentException When a value is refused; the message is the translated reason.
	 *
	 * @spec openspec/changes/archive/2026-11-24-observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function saveErrorSinkSettings(array $values): array;
}//end interface
