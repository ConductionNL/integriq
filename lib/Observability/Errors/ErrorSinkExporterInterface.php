<?php

/**
 * The seam an error sink exporter implements.
 *
 * Integriq sends a forwarded error to a Sentry compatible service or to an
 * OTLP logs collector. Each kind implements this interface, so the export
 * job does not depend on the wire format.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability\Errors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Errors;

/**
 * Sends one queued error event to the configured error service.
 *
 * The event is the array {@see ErrorSinkListener} queues: `app`, `level`,
 * `time`, `exceptionClass`, `file`, `line`, `requestId` and `message`,
 * already redacted.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
interface ErrorSinkExporterInterface {

	/**
	 * The request body one event is sent as.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function body(array $event): string;

	/**
	 * Send one event.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the service refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function export(array $event): void;
}//end interface
