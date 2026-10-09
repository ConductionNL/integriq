<?php

/**
 * OpenTelemetry export settings.
 *
 * The administrator's choices for trace export, stored as `otel_*` app
 * settings (design "Settings"): whether export is on, the collector
 * endpoint, the service name, the sampling ratio, and the collector header
 * given as a credential reference. Also decides whether one trace is
 * sampled.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability\Otel
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

use InvalidArgumentException;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Reads, validates and stores the export settings.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
 */
class OtelSettings {

	/**
	 * The app id the settings are stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'integriq';

	/**
	 * The sampling ratio when an administrator set none (design "Risks").
	 *
	 * @var float
	 */
	public const DEFAULT_SAMPLING_RATIO = 0.1;

	/**
	 * The service name spans carry when an administrator set none.
	 *
	 * @var string
	 */
	public const DEFAULT_SERVICE_NAME = 'integriq';

	/**
	 * The header the resolved collector credential is sent in by default.
	 *
	 * @var string
	 */
	public const DEFAULT_HEADER_NAME = 'Authorization';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IL10N $l Translates the reason a value is refused.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l,
	) {

	}//end __construct()

	/**
	 * All settings, for the admin page. The credential reference is a name,
	 * never a secret, so it can be shown.
	 *
	 * @return array<string, bool|float|string> enabled, endpoint, serviceName, samplingRatio, headerName, credentialName, allowLocal.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function all(): array {
		return [
			'enabled' => $this->isEnabled(),
			'endpoint' => $this->endpoint(),
			'serviceName' => $this->serviceName(),
			'samplingRatio' => $this->samplingRatio(),
			'headerName' => $this->headerName(),
			'credentialName' => $this->credentialName(),
			'allowLocal' => $this->allowLocal(),
		];

	}//end all()

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
	public function save(array $values): array {
		$enabled = (bool)($values['enabled'] ?? false);
		$endpoint = rtrim(trim((string)($values['endpoint'] ?? '')), '/');
		$allowLocal = (bool)($values['allowLocal'] ?? false);
		$ratio = (float)($values['samplingRatio'] ?? self::DEFAULT_SAMPLING_RATIO);

		if ($ratio < 0.0 || $ratio > 1.0) {
			throw new InvalidArgumentException($this->l->t('The sampling ratio must be between 0 and 1.'));
		}

		if ($endpoint !== '') {
			$this->assertEndpoint(endpoint: $endpoint, allowLocal: $allowLocal);
		}

		if ($enabled === true && $endpoint === '') {
			throw new InvalidArgumentException($this->l->t('Export needs a collector endpoint.'));
		}

		$this->appConfig->setValueBool(self::APP_ID, 'otel_enabled', $enabled);
		$this->appConfig->setValueString(self::APP_ID, 'otel_endpoint', $endpoint);
		$this->appConfig->setValueBool(self::APP_ID, 'otel_allow_local', $allowLocal);
		$this->appConfig->setValueFloat(self::APP_ID, 'otel_sampling_ratio', $ratio);
		$this->appConfig->setValueString(self::APP_ID, 'otel_service_name', trim((string)($values['serviceName'] ?? '')));
		$this->appConfig->setValueString(self::APP_ID, 'otel_header_name', trim((string)($values['headerName'] ?? '')));
		$this->appConfig->setValueString(self::APP_ID, 'otel_credential_name', trim((string)($values['credentialName'] ?? '')));

		return $this->all();

	}//end save()

	/**
	 * Whether export is switched on with a collector to send to.
	 *
	 * @return bool True when traces should be queued.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, 'otel_enabled', false) === true
			&& $this->endpoint() !== '';

	}//end isEnabled()

	/**
	 * The collector base URL, without `/v1/traces`.
	 *
	 * @return string The endpoint, or an empty string.
	 */
	public function endpoint(): string {
		return $this->appConfig->getValueString(self::APP_ID, 'otel_endpoint', '');

	}//end endpoint()

	/**
	 * Whether the collector may be a local or plain-http address.
	 *
	 * @return bool True when the administrator marked the collector as internal.
	 */
	public function allowLocal(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, 'otel_allow_local', false);

	}//end allowLocal()

	/**
	 * The `service.name` resource attribute.
	 *
	 * @return string The service name.
	 */
	public function serviceName(): string {
		$name = $this->appConfig->getValueString(self::APP_ID, 'otel_service_name', '');
		if ($name === '') {
			return self::DEFAULT_SERVICE_NAME;
		}

		return $name;

	}//end serviceName()

	/**
	 * The share of successful traces that is exported.
	 *
	 * @return float A ratio from 0 to 1.
	 */
	public function samplingRatio(): float {
		return $this->appConfig->getValueFloat(self::APP_ID, 'otel_sampling_ratio', self::DEFAULT_SAMPLING_RATIO);

	}//end samplingRatio()

	/**
	 * The header the collector credential goes in.
	 *
	 * @return string The header name.
	 */
	public function headerName(): string {
		$name = $this->appConfig->getValueString(self::APP_ID, 'otel_header_name', '');
		if ($name === '') {
			return self::DEFAULT_HEADER_NAME;
		}

		return $name;

	}//end headerName()

	/**
	 * The credential the collector header value is resolved from (ADR-064).
	 *
	 * @return string The credential name, or an empty string for no header.
	 */
	public function credentialName(): string {
		return $this->appConfig->getValueString(self::APP_ID, 'otel_credential_name', '');

	}//end credentialName()

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
	public function isSampled(string $traceId, string $status, bool $isReplay): bool {
		if ($status === 'failed' || $isReplay === true) {
			return true;
		}

		$ratio = $this->samplingRatio();
		if ($ratio >= 1.0) {
			return true;
		}

		if ($ratio <= 0.0) {
			return false;
		}

		$bucket = (hexdec(substr(hash('sha256', $traceId), 0, 8)) / 0xFFFFFFFF);

		return $bucket < $ratio;

	}//end isSampled()

	/**
	 * Refuse a collector URL that is not https, unless the administrator
	 * marked the collector as internal (design "Risks").
	 *
	 * @param string $endpoint The collector base URL.
	 * @param bool $allowLocal Whether a plain-http address is allowed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is refused.
	 */
	private function assertEndpoint(string $endpoint, bool $allowLocal): void {
		$scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));
		$host = (string)parse_url($endpoint, PHP_URL_HOST);
		if ($host === '' || in_array($scheme, ['http', 'https'], true) === false) {
			throw new InvalidArgumentException($this->l->t('The collector endpoint must be a full http or https address.'));
		}

		if ($scheme === 'http' && $allowLocal === false) {
			throw new InvalidArgumentException($this->l->t('The collector endpoint must use https, unless you mark it as an internal collector.'));
		}

		if (parse_url($endpoint, PHP_URL_QUERY) !== null || parse_url($endpoint, PHP_URL_USER) !== null) {
			throw new InvalidArgumentException($this->l->t('The collector endpoint may not carry a query or a login; give the login as a credential.'));
		}

	}//end assertEndpoint()
}//end class
