<?php

/**
 * Integriq ObservabilityRegistrar.
 *
 * Registers the container bindings the Observability component offers to the
 * rest of the app.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability
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

namespace OCA\Integriq\Observability;

use OCA\Integriq\Observability\Otel\OtlpTraceExporter;
use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\SpanMapperInterface;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Observability contract to the class that implements it, so callers
 * outside the Observability component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class ObservabilityRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(SpanMapperInterface::class, SpanMapper::class);
		$this->registerTraceExporter(context: $context);
		$this->registerMetricsProvider(context: $context);
	}
	/**
	 * Binds the OpenTelemetry trace exporter.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerTraceExporter(IRegistrationContext $context): void {
		// OpenTelemetry export (observability-opentelemetry-export D2): the
		// OTLP/HTTP JSON exporter is the one binding; an SDK exporter can
		// replace it here without touching the span mapper.
		$context->registerServiceAlias(TraceExporterInterface::class, OtlpTraceExporter::class);
	}//end registerTraceExporter()

	/**
	 * Registers the metrics provider the AppHost metrics engine reads.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerMetricsProvider(IRegistrationContext $context): void {
		// Provider escape hatch (REQ-PROM-011, retry-and-circuit-breaker-policies):
		// the per-Source circuit_breaker_state gauge needs each Source's OWN
		// field value (1/0), not a row count — declarative tableCount/objectCount
		// descriptors only aggregate counts. The `{"kind":"provider"}` metric
		// descriptor in src/manifest.json merges this provider's samples into
		// the /api/metrics response; the engine resolves it via this alias.
		$context->registerServiceAlias(
			IMetricsProvider::class . '::integriq',
			IntegriqMetricsProvider::class
		);
	}//end registerMetricsProvider()
}
