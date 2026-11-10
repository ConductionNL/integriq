<?php

/**
 * Integriq grouped count metric definition.
 *
 * Describes one single-label grouped counter or gauge that the metrics
 * provider builds from OpenRegister objects: which metric it is, which
 * schema's objects it counts, which property it groups by and which label
 * value the zero placeholder carries.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-004-sources-gauge-by-type
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability;

/**
 * One grouped count metric: its identity, its object source and its grouping.
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-004-sources-gauge-by-type
 */
final class GroupedCountMetric {
	/**
	 * Constructor.
	 *
	 * @param string $name        The metric name (unprefixed).
	 * @param string $type        Prometheus metric type (gauge/counter).
	 * @param string $help        The HELP text.
	 * @param string $schemaTitle The OR schema title to read objects from.
	 * @param string $field       The object property to group by.
	 * @param string $label       The Prometheus label key to emit.
	 * @param string $placeholder The label value for the zero placeholder.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $help,
		public readonly string $schemaTitle,
		public readonly string $field,
		public readonly string $label,
		public readonly string $placeholder,
	) {
	}//end __construct()
}//end class
