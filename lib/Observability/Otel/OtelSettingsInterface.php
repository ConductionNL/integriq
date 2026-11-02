<?php

/**
 * Contract of the OtelSettings.
 *
 * @category Contract
 * @package  OCA\Integriq\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

use InvalidArgumentException;

/**
 * Public operations of {@see OtelSettings} that other components call.
 */
interface OtelSettingsInterface {
	/**
	 * The sampling ratio when an administrator set none (design "Risks").
	 *
	 * @var float
	 */
	public const DEFAULT_SAMPLING_RATIO = 0.1;

	/**
	 * All settings, for the admin page. The credential reference is a name,
	 * never a secret, so it can be shown.
	 *
	 * @return array<string, bool|float|string> enabled, endpoint, serviceName, samplingRatio, headerName, credentialName, allowLocal.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function all(): array;

	/**
	 * Store the settings an administrator saved.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return array The stored settings, as {@see all()} reads them.
	 *
	 * @throws InvalidArgumentException When a value is refused; the message is the translated reason.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function save(array $values): array;

	/**
	 * Whether export is switched on with a collector to send to.
	 *
	 * @return bool True when traces should be queued.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function isEnabled(): bool;

	/**
	 * The collector base URL, without `/v1/traces`.
	 *
	 * @return string The endpoint, or an empty string.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::endpoint()
	 */
	public function endpoint(): string;

	/**
	 * Whether the collector may be a local or plain-http address.
	 *
	 * @return bool True when the administrator marked the collector as internal.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::allowLocal()
	 */
	public function allowLocal(): bool;

	/**
	 * The `service.name` resource attribute.
	 *
	 * @return string The service name.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::serviceName()
	 */
	public function serviceName(): string;

	/**
	 * The share of successful traces that is exported.
	 *
	 * @return float A ratio from 0 to 1.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::samplingRatio()
	 */
	public function samplingRatio(): float;

	/**
	 * The header the collector credential goes in.
	 *
	 * @return string The header name.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::headerName()
	 */
	public function headerName(): string;

	/**
	 * The credential the collector header value is resolved from (ADR-064).
	 *
	 * @return string The credential name, or an empty string for no header.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OtelSettings::credentialName()
	 */
	public function credentialName(): string;

	/**
	 * Whether one trace is exported. A failed or replayed trace always is;
	 * the rest are sampled by the ratio, decided on the trace id so the same
	 * trace always gets the same answer.
	 *
	 * @param string $traceId The execution trace id.
	 * @param string $status The trace's final status.
	 * @param bool $isReplay Whether the trace is a replay.
	 *
	 * @return bool True when the trace is exported.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function isSampled(string $traceId, string $status, bool $isReplay): bool;
}
