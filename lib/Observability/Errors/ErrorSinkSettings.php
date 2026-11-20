<?php

/**
 * Error sink settings.
 *
 * The administrator's choices for the error sink, stored as `errorsink_*`
 * app settings: whether it is on, its kind (`sentry` or `otlp-logs`), the
 * DSN or endpoint, whether that address is internal, the minimum log level,
 * the apps whose messages are forwarded, and the header given as a
 * credential reference (ADR-064).
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
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Errors;

use InvalidArgumentException;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Reads, validates and stores the error sink settings.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */
class ErrorSinkSettings {

	/**
	 * The app id the settings are stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'integriq';

	/**
	 * A Sentry compatible service, addressed by its DSN.
	 *
	 * @var string
	 */
	public const KIND_SENTRY = 'sentry';

	/**
	 * An OpenTelemetry collector that takes OTLP JSON on `/v1/logs`.
	 *
	 * @var string
	 */
	public const KIND_OTLP_LOGS = 'otlp-logs';

	/**
	 * The lowest level forwarded when an administrator set none: error.
	 *
	 * @var int
	 */
	public const DEFAULT_MIN_LEVEL = 3;

	/**
	 * The apps whose messages are forwarded when an administrator set none.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_APPS = ['integriq', 'openregister', 'opencatalogi'];

	/**
	 * The header the resolved credential is sent in by default.
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
	 * @return array<string, mixed> enabled, kind, endpoint, allowLocal, minLevel, apps, headerName, credentialName.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function all(): array {
		return [
			'enabled' => $this->isEnabled(),
			'kind' => $this->kind(),
			'endpoint' => $this->endpoint(),
			'allowLocal' => $this->allowLocal(),
			'minLevel' => $this->minLevel(),
			'apps' => $this->apps(),
			'headerName' => $this->headerName(),
			'credentialName' => $this->credentialName(),
		];

	}//end all()

	/**
	 * Store the settings an administrator saved. Nothing is stored when one
	 * value is refused.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return array<string, mixed> The stored settings, as {@see all()} reads them.
	 *
	 * @throws InvalidArgumentException When a value is refused; the message is the translated reason.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function save(array $values): array {
		$enabled = (bool)($values['enabled'] ?? false);
		$kind = trim((string)($values['kind'] ?? self::KIND_SENTRY));
		$endpoint = rtrim(trim((string)($values['endpoint'] ?? '')), '/');
		$allowLocal = (bool)($values['allowLocal'] ?? false);
		$minLevel = $values['minLevel'] ?? self::DEFAULT_MIN_LEVEL;
		$apps = $this->parseApps(apps: ($values['apps'] ?? self::DEFAULT_APPS));

		if (in_array($kind, [self::KIND_SENTRY, self::KIND_OTLP_LOGS], true) === false) {
			throw new InvalidArgumentException($this->l->t('The error service kind must be Sentry or OTLP logs.'));
		}

		if (is_numeric($minLevel) === false || (int)$minLevel < 0 || (int)$minLevel > 4) {
			throw new InvalidArgumentException($this->l->t('The minimum level must be between 0 and 4.'));
		}

		if ($enabled === true && $endpoint === '') {
			throw new InvalidArgumentException($this->l->t('The error service needs an endpoint or DSN.'));
		}

		if ($endpoint !== '') {
			$this->assertEndpoint(endpoint: $endpoint, kind: $kind, allowLocal: $allowLocal);
		}

		$this->appConfig->setValueBool(self::APP_ID, 'errorsink_enabled', $enabled);
		$this->appConfig->setValueString(self::APP_ID, 'errorsink_kind', $kind);
		$this->appConfig->setValueString(self::APP_ID, 'errorsink_endpoint', $endpoint);
		$this->appConfig->setValueBool(self::APP_ID, 'errorsink_allow_local', $allowLocal);
		$this->appConfig->setValueInt(self::APP_ID, 'errorsink_min_level', (int)$minLevel);
		$this->appConfig->setValueString(self::APP_ID, 'errorsink_apps', implode(',', $apps));
		$this->appConfig->setValueString(self::APP_ID, 'errorsink_header_name', trim((string)($values['headerName'] ?? '')));
		$this->appConfig->setValueString(self::APP_ID, 'errorsink_credential_name', trim((string)($values['credentialName'] ?? '')));

		return $this->all();

	}//end save()

	/**
	 * Whether the sink is switched on with an address to send to.
	 *
	 * @return bool True when messages should be queued.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, 'errorsink_enabled', false) === true
			&& $this->endpoint() !== '';

	}//end isEnabled()

	/**
	 * Whether one log message is forwarded: the sink is on, the app is
	 * listed and the level is at or above the minimum.
	 *
	 * @param string $app The app that logged the message.
	 * @param int $level The Nextcloud log level, 0 (debug) to 4 (fatal).
	 *
	 * @return bool True when the message is queued for the sink.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function forwards(string $app, int $level): bool {
		if ($level < $this->minLevel()) {
			return false;
		}

		return in_array($app, $this->apps(), true) === true && $this->isEnabled() === true;

	}//end forwards()

	/**
	 * The sink kind.
	 *
	 * @return string `sentry` or `otlp-logs`.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function kind(): string {
		$kind = $this->appConfig->getValueString(self::APP_ID, 'errorsink_kind', self::KIND_SENTRY);
		if ($kind === self::KIND_OTLP_LOGS) {
			return self::KIND_OTLP_LOGS;
		}

		return self::KIND_SENTRY;

	}//end kind()

	/**
	 * The DSN (kind `sentry`) or the collector base URL without `/v1/logs`
	 * (kind `otlp-logs`).
	 *
	 * @return string The address, or an empty string.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function endpoint(): string {
		return $this->appConfig->getValueString(self::APP_ID, 'errorsink_endpoint', '');

	}//end endpoint()

	/**
	 * Whether the address may be a local or plain-http one.
	 *
	 * @return bool True when the administrator marked the service as internal.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function allowLocal(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, 'errorsink_allow_local', false);

	}//end allowLocal()

	/**
	 * The lowest log level that is forwarded.
	 *
	 * @return int 0 (debug) to 4 (fatal).
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function minLevel(): int {
		return $this->appConfig->getValueInt(self::APP_ID, 'errorsink_min_level', self::DEFAULT_MIN_LEVEL);

	}//end minLevel()

	/**
	 * The apps whose messages are forwarded.
	 *
	 * @return list<string> App ids.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function apps(): array {
		$stored = $this->appConfig->getValueString(self::APP_ID, 'errorsink_apps', implode(',', self::DEFAULT_APPS));

		return array_values(array_filter(array_map('trim', explode(',', $stored)), static fn (string $app): bool => $app !== ''));

	}//end apps()

	/**
	 * The header the credential goes in (kind `otlp-logs`).
	 *
	 * @return string The header name.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function headerName(): string {
		$name = $this->appConfig->getValueString(self::APP_ID, 'errorsink_header_name', '');
		if ($name === '') {
			return self::DEFAULT_HEADER_NAME;
		}

		return $name;

	}//end headerName()

	/**
	 * The credential the header value is resolved from (ADR-064).
	 *
	 * @return string The credential name, or an empty string for no header.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
	 */
	public function credentialName(): string {
		return $this->appConfig->getValueString(self::APP_ID, 'errorsink_credential_name', '');

	}//end credentialName()

	/**
	 * The submitted app list as clean app ids: a list or a comma separated
	 * string, trimmed, without duplicates.
	 *
	 * @param mixed $apps The submitted list.
	 *
	 * @return list<string> The app ids.
	 *
	 * @throws InvalidArgumentException When an entry is not an app id.
	 */
	private function parseApps(mixed $apps): array {
		if (is_string($apps) === true) {
			$apps = explode(',', $apps);
		}

		$clean = [];
		foreach ((array)$apps as $app) {
			$app = trim((string)$app);
			if ($app === '') {
				continue;
			}

			if (preg_match('/^[a-z0-9_\-]{1,64}$/', $app) !== 1) {
				throw new InvalidArgumentException($this->l->t('Each app in the list must be an app id, such as openregister.'));
			}

			$clean[$app] = $app;
		}

		return array_values($clean);

	}//end parseApps()

	/**
	 * Refuse an address that is not https, unless the administrator marked
	 * the service as internal: the same rule as the trace collector
	 * (REQ-OTEL-005). A Sentry DSN carries its public key as the user part;
	 * any other login, and a query, are refused.
	 *
	 * @param string $endpoint The DSN or the collector base URL.
	 * @param string $kind The sink kind.
	 * @param bool $allowLocal Whether a plain-http address is allowed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the address is refused.
	 */
	private function assertEndpoint(string $endpoint, string $kind, bool $allowLocal): void {
		$scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));
		$host = (string)parse_url($endpoint, PHP_URL_HOST);
		if ($host === '' || in_array($scheme, ['http', 'https'], true) === false) {
			throw new InvalidArgumentException($this->l->t('The error service endpoint must be a full http or https address.'));
		}

		if ($scheme === 'http' && $allowLocal === false) {
			throw new InvalidArgumentException($this->l->t('The error service endpoint must use https, unless you mark it as an internal service.'));
		}

		if (parse_url($endpoint, PHP_URL_QUERY) !== null || parse_url($endpoint, PHP_URL_PASS) !== null) {
			throw new InvalidArgumentException($this->l->t('The error service endpoint may not carry a query or a password; give the login as a credential.'));
		}

		$user = parse_url($endpoint, PHP_URL_USER);
		if ($kind === self::KIND_SENTRY) {
			if ($user === null || $user === '' || preg_match('#/\d+$#', (string)parse_url($endpoint, PHP_URL_PATH)) !== 1) {
				throw new InvalidArgumentException($this->l->t('A Sentry DSN looks like https://key@sentry.example.org/42.'));
			}

			return;
		}

		if ($user !== null) {
			throw new InvalidArgumentException($this->l->t('The error service endpoint may not carry a login; give the login as a credential.'));
		}

	}//end assertEndpoint()
}//end class
