<?php

/**
 * Stub for OCA\OpenRegister\AppHost\Observability\MetricSample.
 *
 * Mirrors the value object shipped by the OpenRegister AppHost observability
 * engine (ADR-040). Used only in environments where the openregister runtime
 * is not installed (e.g. bare CI containers) — IntegriqMetricsProvider
 * implements the real interface/value-object in production; this stub keeps
 * the class loadable when openregister is absent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Minimal stub for OCA\OpenRegister\AppHost\Observability\MetricSample.
 */
final class MetricSample {

	/**
	 * Constructor.
	 *
	 * @param string $name Metric name (without `{app}_` prefix).
	 * @param string $type Prometheus type (gauge|counter).
	 * @param string $help HELP text.
	 * @param array $samples Labelled samples.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $help,
		public readonly array $samples,
	) {

	}//end __construct()

	/**
	 * Convenience factory for a single unlabelled sample.
	 *
	 * @param string $name Metric name.
	 * @param string $type Prometheus type.
	 * @param string $help HELP text.
	 * @param float|int $value Sample value.
	 * @param array<string, string> $labels Optional labels.
	 *
	 * @return self
	 */
	public static function single(string $name, string $type, string $help, float|int $value, array $labels = []): self {
		return new self(
			name: $name,
			type: $type,
			help: $help,
			samples: [['labels' => $labels, 'value' => $value]]
		);

	}//end single()

}//end class
