<?php

/**
 * Stub for OCA\OpenRegister\AppHost\IMetricsProvider.
 *
 * Mirrors the interface shipped by the OpenRegister AppHost observability
 * engine (ADR-040). Used only in environments where the openregister runtime
 * is not installed (e.g. bare CI containers) — IntegriqMetricsProvider
 * implements the real interface in production; this stub keeps the class
 * loadable when openregister is absent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

/**
 * Minimal stub for OCA\OpenRegister\AppHost\IMetricsProvider.
 */
interface IMetricsProvider {

	/**
	 * Produce the provider's metric samples.
	 *
	 * @return \OCA\OpenRegister\AppHost\Observability\MetricSample[] The provider's samples.
	 */
	public function metrics(): array;

}//end interface
