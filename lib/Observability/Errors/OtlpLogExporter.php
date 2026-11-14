<?php

/**
 * OTLP logs error sink exporter.
 *
 * Posts one forwarded error as an OTLP JSON `resourceLogs` payload to
 * `{endpoint}/v1/logs`. The resource's `service.name` is the app that
 * logged the message. The collector header is resolved from a credential
 * reference at send time (ADR-064), so no secret is stored in the app
 * settings.
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

use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCP\Http\Client\IClientService;
use RuntimeException;

/**
 * Sends a forwarded error as an OTLP log record.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
class OtlpLogExporter extends ErrorSinkHttpExporter {

	/**
	 * The OTLP severity number and text per Nextcloud log level.
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private const SEVERITIES = [
		0 => [5, 'DEBUG'],
		1 => [9, 'INFO'],
		2 => [13, 'WARN'],
		3 => [17, 'ERROR'],
		4 => [21, 'FATAL'],
	];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud's HTTP client.
	 * @param ErrorSinkSettings $settings The error sink settings.
	 * @param BrokeredCallServiceInterface $credentials Resolves the collector credential reference.
	 */
	public function __construct(
		IClientService $clientService,
		ErrorSinkSettings $settings,
		private readonly BrokeredCallServiceInterface $credentials,
	) {
		parent::__construct(clientService: $clientService, settings: $settings);

	}//end __construct()

	/**
	 * Send one event to `{endpoint}/v1/logs`.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no endpoint is set, or the collector refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function export(array $event): void {
		$endpoint = $this->settings->endpoint();
		if ($endpoint === '') {
			throw new RuntimeException('No error service endpoint is configured.');
		}

		$headers = ['Content-Type' => 'application/json'];
		$credentialName = $this->settings->credentialName();
		if ($credentialName !== '') {
			$headers[$this->settings->headerName()] = $this->credentials->resolveCredentialRef(ref: ['credentialName' => $credentialName]);
		}

		$this->post(url: $endpoint . '/v1/logs', body: $this->body(event: $event), headers: $headers);

	}//end export()

	/**
	 * The OTLP JSON `resourceLogs` payload of one event.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return string The JSON body.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function body(array $event): string {
		$severity = (self::SEVERITIES[(int)($event['level'] ?? 3)] ?? self::SEVERITIES[3]);

		return $this->json(
			value: [
				'resourceLogs' => [
					[
						'resource' => [
							'attributes' => $this->attributes(values: ['service.name' => (string)($event['app'] ?? '')]),
						],
						'scopeLogs' => [
							[
								'scope' => ['name' => 'integriq.error-sink'],
								'logRecords' => [
									[
										'timeUnixNano' => ((int)($event['time'] ?? 0)) . '000000000',
										'severityNumber' => $severity[0],
										'severityText' => $severity[1],
										'body' => ['stringValue' => (string)($event['message'] ?? '')],
										'attributes' => $this->attributes(
											values: [
												'exception.type' => (string)($event['exceptionClass'] ?? ''),
												'code.filepath' => (string)($event['file'] ?? ''),
												'code.lineno' => (int)($event['line'] ?? 0),
												'nextcloud.app' => (string)($event['app'] ?? ''),
												'nextcloud.request_id' => (string)($event['requestId'] ?? ''),
											]
										),
									],
								],
							],
						],
					],
				],
			]
		);

	}//end body()

	/**
	 * OTLP key/value attributes, empty values and a zero line left out.
	 *
	 * @param array<string, int|string> $values The attributes.
	 *
	 * @return list<array<string, mixed>> The OTLP attribute list.
	 */
	private function attributes(array $values): array {
		$list = [];
		foreach ($values as $key => $value) {
			if ($value === '' || $value === 0) {
				continue;
			}

			if (is_int($value) === true) {
				$list[] = ['key' => $key, 'value' => ['intValue' => (string)$value]];
				continue;
			}

			$list[] = ['key' => $key, 'value' => ['stringValue' => $value]];
		}

		return $list;

	}//end attributes()
}//end class
