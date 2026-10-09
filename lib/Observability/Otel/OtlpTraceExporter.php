<?php

/**
 * OTLP/HTTP JSON trace exporter.
 *
 * One POST of an OTLP JSON payload to `{endpoint}/v1/traces` through
 * Nextcloud's HTTP client (design D2: no SDK). The collector header is
 * resolved from a credential reference at send time (ADR-064), so no
 * secret is stored in the app settings.
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

use OCA\Integriq\Service\BrokeredCallService;
use OCP\Http\Client\IClientService;
use RuntimeException;
use Throwable;

/**
 * Posts OTLP JSON to the configured collector.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */
class OtlpTraceExporter implements TraceExporterInterface {

	/**
	 * Seconds a collector gets before the batch counts as failed.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud's HTTP client.
	 * @param OtelSettings $settings The export settings.
	 * @param BrokeredCallService $credentials Resolves the collector credential reference.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly OtelSettings $settings,
		private readonly BrokeredCallService $credentials,
	) {

	}//end __construct()

	/**
	 * Send one payload.
	 *
	 * @param array $payload The OTLP JSON payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the collector refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function export(array $payload): void {
		$endpoint = $this->settings->endpoint();
		if ($endpoint === '') {
			throw new RuntimeException('No collector endpoint is configured.');
		}

		$headers = ['Content-Type' => 'application/json'];
		$credentialName = $this->settings->credentialName();
		if ($credentialName !== '') {
			$headers[$this->settings->headerName()] = $this->credentials->resolveCredentialRef(ref: ['credentialName' => $credentialName]);
		}

		$options = [
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
			'headers' => $headers,
			'timeout' => self::TIMEOUT_SECONDS,
		];
		if ($this->settings->allowLocal() === true) {
			$options['nextcloud'] = ['allow_local_address' => true];
		}

		try {
			$response = $this->clientService->newClient()->post($endpoint . '/v1/traces', $options);
		} catch (Throwable $e) {
			throw new RuntimeException('The collector could not be reached: ' . $e->getMessage(), 0, $e);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('The collector answered %d.', $status));
		}

	}//end export()
}//end class
