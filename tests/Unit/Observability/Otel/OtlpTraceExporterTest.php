<?php

/**
 * Unit tests for the OTLP trace exporter's request shape and failure paths
 * (observability-opentelemetry-export REQ-OTEL-001).
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

use Exception;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Observability\Otel\OtlpTraceExporter;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for OtlpTraceExporter.
 *
 * @covers \OCA\Integriq\Observability\Otel\OtlpTraceExporter
 * @uses   \OCA\Integriq\Observability\Otel\OtelSettings
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */
class OtlpTraceExporterTest extends TestCase {

	/**
	 * Settings over a stored app config.
	 *
	 * @param array<string, string|bool> $values The stored otel_* values.
	 *
	 * @return OtelSettings
	 */
	private function settings(array $values): OtelSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				return (string) ($values[$key] ?? $default);
			}
		);
		$config->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default = false) use ($values): bool {
				return (bool) ($values[$key] ?? $default);
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OtelSettings($config, $l10n);
	}//end settings()

	/**
	 * A client service whose client answers with the given status, recording the request.
	 *
	 * @param int        $status   The collector's status code.
	 * @param array|null $captured Receives [url, options] of the post.
	 *
	 * @return IClientService
	 */
	private function clients(int $status, ?array &$captured = null): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturnCallback(
			static function (string $url, array $options) use ($response, &$captured): IResponse {
				$captured = [$url, $options];
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return $clients;
	}//end clients()

	/**
	 * Without an endpoint nothing is sent and the export fails.
	 *
	 * @return void
	 */
	public function testAMissingEndpointFailsWithoutSending(): void {
		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->never())->method('newClient');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No collector endpoint is configured.');

		(new OtlpTraceExporter($clients, $this->settings(values: []), $this->createMock(BrokeredCallServiceInterface::class)))
			->export(payload: ['resourceSpans' => []]);
	}//end testAMissingEndpointFailsWithoutSending()

	/**
	 * Without a credential no auth header is resolved, and a local collector is allowed when configured.
	 *
	 * @return void
	 */
	public function testAllowLocalAddsTheLocalAddressOptionAndNoCredentialIsResolved(): void {
		$credentials = $this->createMock(BrokeredCallServiceInterface::class);
		$credentials->expects($this->never())->method('resolveCredentialRef');
		$captured = null;

		(new OtlpTraceExporter(
			$this->clients(status: 204, captured: $captured),
			$this->settings(values: ['otel_endpoint' => 'http://collector:4318', 'otel_allow_local' => true]),
			$credentials
		))->export(payload: ['a' => 'b/c']);

		$this->assertSame('http://collector:4318/v1/traces', $captured[0]);
		$this->assertSame(['allow_local_address' => true], $captured[1]['nextcloud']);
		$this->assertSame(['Content-Type' => 'application/json'], $captured[1]['headers']);
		$this->assertSame('{"a":"b/c"}', $captured[1]['body']);
		$this->assertSame(3, $captured[1]['timeout']);
	}//end testAllowLocalAddsTheLocalAddressOptionAndNoCredentialIsResolved()

	/**
	 * A configured credential is resolved into the configured header, and remote collectors get no local option.
	 *
	 * @return void
	 */
	public function testACredentialGoesIntoTheConfiguredHeader(): void {
		$credentials = $this->createMock(BrokeredCallServiceInterface::class);
		$credentials->expects($this->once())->method('resolveCredentialRef')
			->with(['credentialName' => 'otel-key'])
			->willReturn('Bearer s3cret');
		$captured = null;

		(new OtlpTraceExporter(
			$this->clients(status: 200, captured: $captured),
			$this->settings(values: ['otel_endpoint' => 'https://otel.example', 'otel_credential_name' => 'otel-key', 'otel_header_name' => 'X-Otel-Auth']),
			$credentials
		))->export(payload: []);

		$this->assertSame('Bearer s3cret', $captured[1]['headers']['X-Otel-Auth']);
		$this->assertArrayNotHasKey('nextcloud', $captured[1]);
	}//end testACredentialGoesIntoTheConfiguredHeader()

	/**
	 * A transport error becomes a RuntimeException that keeps the cause.
	 *
	 * @return void
	 */
	public function testAnUnreachableCollectorWrapsTheCause(): void {
		$cause  = new Exception('connection refused');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException($cause);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		try {
			(new OtlpTraceExporter($clients, $this->settings(values: ['otel_endpoint' => 'https://otel.example']), $this->createMock(BrokeredCallServiceInterface::class)))
				->export(payload: []);
			$this->fail('Expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('The collector could not be reached: connection refused', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAnUnreachableCollectorWrapsTheCause()

	/**
	 * A status outside 2xx fails, at both edges of the range.
	 *
	 * @return void
	 */
	public function testStatusesOutside2xxFail(): void {
		foreach ([199, 300] as $status) {
			try {
				(new OtlpTraceExporter($this->clients(status: $status), $this->settings(values: ['otel_endpoint' => 'https://otel.example']), $this->createMock(BrokeredCallServiceInterface::class)))
					->export(payload: []);
				$this->fail('Expected a RuntimeException for '.$status);
			} catch (RuntimeException $e) {
				$this->assertSame(sprintf('The collector answered %d.', $status), $e->getMessage());
			}
		}
	}//end testStatusesOutside2xxFail()
}//end class
