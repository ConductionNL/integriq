<?php

/**
 * Unit tests for OtelSettings: what an administrator may store, what is
 * refused and why, the defaults, and deterministic sampling.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Otel;

use InvalidArgumentException;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Settings over an in-memory IAppConfig.
 *
 * @covers \OCA\Integriq\Observability\Otel\OtelSettings
 */
class OtelSettingsTest extends TestCase {

	/**
	 * Stored app settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Every write, as [app, key, value], in order.
	 *
	 * @var array<int, array{0:string,1:string,2:mixed}>
	 */
	private array $writes = [];

	/**
	 * The subject.
	 *
	 * @var OtelSettings
	 */
	private OtelSettings $settings;

	/**
	 * Build the subject over an in-memory IAppConfig and a pass-through IL10N.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$get = function (string $app, string $key, $default) {
			$this->assertSame('integriq', $app);
			return ($this->config[$key] ?? $default);
		};
		$set = function (string $app, string $key, $value): bool {
			$this->writes[] = [$app, $key, $value];
			$this->config[$key] = $value;
			return true;
		};
		foreach (['getValueBool', 'getValueString', 'getValueFloat'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		foreach (['setValueBool', 'setValueString', 'setValueFloat'] as $setter) {
			$appConfig->method($setter)->willReturnCallback($set);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->settings = new OtelSettings($appConfig, $l10n);
	}//end setUp()

	/**
	 * Nothing stored: export is off and the defaults show.
	 *
	 * @return void
	 */
	public function testNothingStoredGivesTheDefaults(): void {
		$this->assertSame(
			[
				'enabled' => false,
				'endpoint' => '',
				'serviceName' => 'integriq',
				'samplingRatio' => 0.1,
				'headerName' => 'Authorization',
				'credentialName' => '',
				'allowLocal' => false,
			],
			$this->settings->all()
		);
	}//end testNothingStoredGivesTheDefaults()

	/**
	 * Export is only enabled with an endpoint, even when the flag is set.
	 *
	 * @return void
	 */
	public function testEnabledNeedsAnEndpoint(): void {
		$this->config['otel_enabled'] = true;
		$this->assertFalse($this->settings->isEnabled());

		$this->config['otel_endpoint'] = 'https://otel.example.org';
		$this->assertTrue($this->settings->isEnabled());

		$this->config['otel_enabled'] = false;
		$this->assertFalse($this->settings->isEnabled());
	}//end testEnabledNeedsAnEndpoint()

	/**
	 * A save trims the fields, drops trailing slashes and writes every key
	 * once, in order; the answer is the stored view.
	 *
	 * @return void
	 */
	public function testASaveTrimsAndWritesEveryKey(): void {
		$stored = $this->settings->save(
			[
				'enabled' => true,
				'endpoint' => '  https://otel.example.org/collector//  ',
				'samplingRatio' => '0.25',
				'serviceName' => ' zaken-integriq ',
				'headerName' => ' X-Api-Key ',
				'credentialName' => ' otel ',
			]
		);

		$this->assertSame(
			[
				['integriq', 'otel_enabled', true],
				['integriq', 'otel_endpoint', 'https://otel.example.org/collector'],
				['integriq', 'otel_allow_local', false],
				['integriq', 'otel_sampling_ratio', 0.25],
				['integriq', 'otel_service_name', 'zaken-integriq'],
				['integriq', 'otel_header_name', 'X-Api-Key'],
				['integriq', 'otel_credential_name', 'otel'],
			],
			$this->writes
		);
		$this->assertSame('zaken-integriq', $stored['serviceName']);
		$this->assertSame('X-Api-Key', $stored['headerName']);
		$this->assertSame('otel', $stored['credentialName']);
		$this->assertTrue($stored['enabled']);
	}//end testASaveTrimsAndWritesEveryKey()

	/**
	 * Export may be stored off without an endpoint; blank names fall back to
	 * the defaults on read.
	 *
	 * @return void
	 */
	public function testExportOffNeedsNoEndpoint(): void {
		$stored = $this->settings->save(['enabled' => false, 'serviceName' => '  ', 'samplingRatio' => 0]);

		$this->assertFalse($stored['enabled']);
		$this->assertSame('', $stored['endpoint']);
		$this->assertSame(0.0, $stored['samplingRatio']);
		$this->assertSame('integriq', $stored['serviceName']);
		$this->assertSame('Authorization', $stored['headerName']);
	}//end testExportOffNeedsNoEndpoint()

	/**
	 * Each refusal names its own rule and stores nothing.
	 *
	 * @return void
	 */
	public function testRefusalsNameTheirRuleAndStoreNothing(): void {
		$cases = [
			'The sampling ratio must be between 0 and 1.' => [
				['endpoint' => 'https://otel.example.org', 'samplingRatio' => 1.01],
				['endpoint' => 'https://otel.example.org', 'samplingRatio' => -0.1],
			],
			'The collector endpoint must be a full http or https address.' => [
				['endpoint' => 'ftp://otel.example.org'],
				['endpoint' => 'otel.example.org'],
				['endpoint' => 'https://'],
			],
			'The collector endpoint must use https, unless you mark it as an internal collector.' => [
				['endpoint' => 'HTTP://collector:4318'],
			],
			'The collector endpoint may not carry a query or a login; give the login as a credential.' => [
				['endpoint' => 'https://otel.example.org/v1?token=x'],
				['endpoint' => 'https://user@otel.example.org'],
				['endpoint' => 'http://user:pw@collector', 'allowLocal' => true],
			],
			'Export needs a collector endpoint.' => [
				['enabled' => true],
				['enabled' => true, 'endpoint' => ' / '],
			],
		];

		foreach ($cases as $message => $inputs) {
			foreach ($inputs as $input) {
				try {
					$this->settings->save($input);
					$this->fail('accepted ' . json_encode($input));
				} catch (InvalidArgumentException $exception) {
					$this->assertSame($message, $exception->getMessage(), json_encode($input));
				}
			}
		}

		$this->assertSame([], $this->writes);
	}//end testRefusalsNameTheirRuleAndStoreNothing()

	/**
	 * Plain http is stored when marked as an internal collector.
	 *
	 * @return void
	 */
	public function testHttpIsAcceptedForAnInternalCollector(): void {
		$stored = $this->settings->save(['enabled' => true, 'endpoint' => 'http://collector:4318/', 'allowLocal' => true]);

		$this->assertSame('http://collector:4318', $stored['endpoint']);
		$this->assertTrue($stored['allowLocal']);
		$this->assertTrue($stored['enabled']);
	}//end testHttpIsAcceptedForAnInternalCollector()

	/**
	 * Between 0 and 1 a trace is sampled when its id's hash bucket falls
	 * below the ratio, the same way every time.
	 *
	 * @return void
	 */
	public function testSamplingFollowsTheTraceIdBucket(): void {
		// Buckets: "t-1" hashes to about 0.277, "t-5" to about 0.962.
		$this->config['otel_sampling_ratio'] = 0.5;
		$this->assertTrue($this->settings->isSampled('t-1', 'success', false));
		$this->assertFalse($this->settings->isSampled('t-5', 'success', false));

		$this->config['otel_sampling_ratio'] = 0.27;
		$this->assertFalse($this->settings->isSampled('t-1', 'success', false));
		$this->assertTrue($this->settings->isSampled('t-1', 'failed', false));
		$this->assertTrue($this->settings->isSampled('t-5', 'success', true));

		$this->config['otel_sampling_ratio'] = -1.0;
		$this->assertFalse($this->settings->isSampled('t-1', 'success', false));

		$this->config['otel_sampling_ratio'] = 2.0;
		$this->assertTrue($this->settings->isSampled('t-5', 'success', false));
	}//end testSamplingFollowsTheTraceIdBucket()
}//end class
