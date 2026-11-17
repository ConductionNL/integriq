<?php

/**
 * The HTTP send both error sink exporters share.
 *
 * One POST through Nextcloud's HTTP client with a short timeout. A local or
 * plain-http address is only reached when the administrator marked the
 * service as internal.
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

use OCP\Http\Client\IClientService;
use RuntimeException;
use Throwable;

/**
 * Posts one body to the error service.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
abstract class ErrorSinkHttpExporter implements ErrorSinkExporterInterface {

	/**
	 * Seconds the service gets before the send counts as failed.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 3;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud's HTTP client.
	 * @param ErrorSinkSettings $settings The error sink settings.
	 */
	public function __construct(
		protected readonly IClientService $clientService,
		protected readonly ErrorSinkSettings $settings,
	) {

	}//end __construct()

	/**
	 * Post one body.
	 *
	 * @param string $url The full URL.
	 * @param string $body The request body.
	 * @param array<string, string> $headers The request headers.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the service refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	protected function post(string $url, string $body, array $headers): void {
		$options = [
			'body' => $body,
			'headers' => $headers,
			'timeout' => self::TIMEOUT_SECONDS,
		];
		if ($this->settings->allowLocal() === true) {
			$options['nextcloud'] = ['allow_local_address' => true];
		}

		try {
			$response = $this->clientService->newClient()->post($url, $options);
		} catch (Throwable $e) {
			throw new RuntimeException('The error service could not be reached: ' . $e->getMessage(), 0, $e);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('The error service answered %d.', $status));
		}

	}//end post()

	/**
	 * JSON for a request body: slashes kept, invalid UTF-8 replaced.
	 *
	 * @param array<string, mixed> $value The value.
	 *
	 * @return string The JSON.
	 */
	protected function json(array $value): string {
		return json_encode($value, (JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

	}//end json()
}//end class
