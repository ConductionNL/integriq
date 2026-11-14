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
	 * Retrieve the current retention settings from app configuration.
	 *
	 * @return array The current retention settings configuration.
	 *
	 * @throws \RuntimeException If settings retrieval fails.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md#requirement-settingscontroller-must-shrink-to-connector-specific-actions-only
	 */
	public function getSettings(): array;

	/**
	 * Rebase all logs with current retention settings.
	 *
	 * This method sets expiry dates for all logs based on current retention settings,
	 * using database operations for optimal performance.
	 *
	 * @return array Array containing the rebase operation results
	 * @throws \RuntimeException If the rebase operation fails
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	public function rebase(): array;

	/**
	 * The error sink settings (`errorsink_*`), for the admin page.
	 *
	 * @return array<string, mixed> enabled, kind, endpoint, allowLocal, minLevel, apps, headerName, credentialName.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
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
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function saveErrorSinkSettings(array $values): array;
}
