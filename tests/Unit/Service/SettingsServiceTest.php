<?php

/**
 * Unit tests for the error sink settings on SettingsService (REQ-OTEL-008).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use LogicException;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCA\Integriq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The error sink is stored under `errorsink_*` keys, and a bad save stores nothing.
 */
class SettingsServiceTest extends TestCase {

	/**
	 * Stored app settings, as an in-memory IAppConfig.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The service over an in-memory IAppConfig.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		$set = function (string $app, string $key, $value): bool {
			$this->assertSame(expected: 'integriq', actual: $app);
			$this->config[$key] = $value;
			return true;
		};
		foreach (['getValueBool', 'getValueString', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		foreach (['setValueBool', 'setValueString', 'setValueInt'] as $setter) {
			$appConfig->method($setter)->willReturnCallback($set);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SettingsService(
			db: $this->createMock(originalClassName: IDBConnection::class),
			config: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			errorSink: new ErrorSinkSettings(appConfig: $appConfig, l: $l10n)
		);

	}//end service()

	/**
	 * Assert one save is refused with a reason containing the given text,
	 * and stores nothing.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 * @param string $reason Text the reason contains.
	 *
	 * @return void
	 */
	private function assertRefused(array $values, string $reason): void {
		$this->config = [];
		try {
			$this->service()->saveErrorSinkSettings(values: $values);
			$this->fail(message: 'The save was taken: ' . json_encode($values));
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString(needle: $reason, haystack: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->config, message: 'A refused save stores nothing.');

	}//end assertRefused()

	/**
	 * The sink switched on with an empty endpoint is refused, naming the endpoint.
	 *
	 * @return void
	 */
	public function testAnEnabledErrorSinkNeedsAnEndpoint(): void {
		$this->assertRefused(values: ['enabled' => true, 'kind' => 'sentry', 'endpoint' => '  '], reason: 'endpoint');

		$stored = $this->service()->saveErrorSinkSettings(values: ['enabled' => false, 'endpoint' => '']);
		$this->assertFalse(condition: $stored['enabled']);

	}//end testAnEnabledErrorSinkNeedsAnEndpoint()

	/**
	 * An `http` endpoint is refused unless the service is marked internal.
	 *
	 * @return void
	 */
	public function testAnHttpErrorSinkNeedsTheInternalMark(): void {
		$values = ['enabled' => true, 'kind' => 'otlp-logs', 'endpoint' => 'http://otel.internal:4318'];
		$this->assertRefused(values: $values, reason: 'must use https');

		$stored = $this->service()->saveErrorSinkSettings(values: ($values + ['allowLocal' => true]));
		$this->assertTrue(condition: $stored['enabled']);
		$this->assertTrue(condition: $stored['allowLocal']);

	}//end testAnHttpErrorSinkNeedsTheInternalMark()

	/**
	 * A Sentry sink with its DSN and the default apps is stored under the
	 * `errorsink_*` keys and read back.
	 *
	 * @return void
	 */
	public function testAnErrorSinkIsStoredUnderErrorsinkKeys(): void {
		$service = $this->service();
		$this->assertSame(
			expected: [
				'enabled' => false,
				'kind' => 'sentry',
				'endpoint' => '',
				'allowLocal' => false,
				'minLevel' => 3,
				'apps' => ['integriq', 'openregister', 'opencatalogi'],
				'headerName' => 'Authorization',
				'credentialName' => '',
			],
			actual: $service->getErrorSinkSettings()
		);

		$stored = $service->saveErrorSinkSettings(
			values: [
				'enabled' => true,
				'kind' => 'sentry',
				'endpoint' => 'https://key@sentry.example.org/42/',
				'apps' => 'integriq, openregister,openregister ,opencatalogi',
			]
		);

		$this->assertSame(
			expected: [
				'errorsink_enabled' => true,
				'errorsink_kind' => 'sentry',
				'errorsink_endpoint' => 'https://key@sentry.example.org/42',
				'errorsink_allow_local' => false,
				'errorsink_min_level' => 3,
				'errorsink_apps' => 'integriq,openregister,opencatalogi',
				'errorsink_header_name' => '',
				'errorsink_credential_name' => '',
			],
			actual: $this->config
		);
		$this->assertTrue(condition: $stored['enabled']);
		$this->assertSame(expected: $stored, actual: $service->getErrorSinkSettings());

		$settings = new ErrorSinkSettings(
			appConfig: $this->createConfiguredMock(originalClassName: IAppConfig::class, configuration: []),
			l: $this->createMock(originalClassName: IL10N::class)
		);
		$this->assertSame(expected: 'sentry', actual: $settings->kind());

	}//end testAnErrorSinkIsStoredUnderErrorsinkKeys()

	/**
	 * A wrong kind, level, app id, DSN or login is refused.
	 *
	 * @return void
	 */
	public function testMalformedErrorSinkValuesAreRefused(): void {
		$this->assertRefused(values: ['kind' => 'syslog'], reason: 'kind');
		$this->assertRefused(values: ['minLevel' => 7], reason: 'minimum level');
		$this->assertRefused(values: ['minLevel' => 'high'], reason: 'minimum level');
		$this->assertRefused(values: ['apps' => ['openregister', 'Open Register']], reason: 'app id');
		$this->assertRefused(values: ['endpoint' => 'ftp://sentry.example.org/42'], reason: 'full http or https');
		$this->assertRefused(values: ['endpoint' => 'https://sentry.example.org/42'], reason: 'Sentry DSN');
		$this->assertRefused(values: ['endpoint' => 'https://key@sentry.example.org/project'], reason: 'Sentry DSN');
		$this->assertRefused(values: ['endpoint' => 'https://key:pass@sentry.example.org/42'], reason: 'password');
		$this->assertRefused(values: ['kind' => 'otlp-logs', 'endpoint' => 'https://otel.example.org?token=x'], reason: 'query');
		$this->assertRefused(values: ['kind' => 'otlp-logs', 'endpoint' => 'https://user@otel.example.org'], reason: 'login');

	}//end testMalformedErrorSinkValuesAreRefused()

	/**
	 * The sink forwards a listed app at or above the minimum level, and only
	 * while it is on with an endpoint.
	 *
	 * @return void
	 */
	public function testTheSinkForwardsListedAppsAtTheMinimumLevel(): void {
		$service = $this->service();
		$service->saveErrorSinkSettings(
			values: [
				'enabled' => true,
				'kind' => 'otlp-logs',
				'endpoint' => 'https://otel.example.org',
				'minLevel' => 2,
				'apps' => ['dossiq'],
				'credentialName' => 'otel',
				'headerName' => 'X-Token',
			]
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		foreach (['getValueBool', 'getValueString', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		$settings = new ErrorSinkSettings(appConfig: $appConfig, l: $l10n);
		$this->assertTrue(condition: $settings->forwards(app: 'dossiq', level: 2));
		$this->assertFalse(condition: $settings->forwards(app: 'dossiq', level: 1));
		$this->assertFalse(condition: $settings->forwards(app: 'openregister', level: 4));
		$this->assertSame(expected: 'otlp-logs', actual: $settings->kind());
		$this->assertSame(expected: 'X-Token', actual: $settings->headerName());
		$this->assertSame(expected: 'otel', actual: $settings->credentialName());

		$this->config['errorsink_endpoint'] = '';
		$this->assertFalse(condition: $settings->forwards(app: 'dossiq', level: 4));

	}//end testTheSinkForwardsListedAppsAtTheMinimumLevel()

	/**
	 * A service built without the error sink store says so instead of
	 * pretending the sink is off.
	 *
	 * @return void
	 */
	public function testWithoutTheStoreTheErrorSinkIsNotAvailable(): void {
		$service = new SettingsService(
			db: $this->createMock(originalClassName: IDBConnection::class),
			config: $this->createMock(originalClassName: IAppConfig::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->expectException(exception: LogicException::class);
		$service->getErrorSinkSettings();

	}//end testWithoutTheStoreTheErrorSinkIsNotAvailable()
}//end class
