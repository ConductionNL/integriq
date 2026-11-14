<?php

/**
 * Integriq pipeline edges.
 *
 * The edges of a generated linear flow: one `from-to` edge per consecutive
 * pair of nodes, in array order. The job and synchronization migration
 * generators both emit a straight pipeline and chain it this way.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Chains a generated flow's nodes.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class PipelineEdges {

	/**
	 * Chain the nodes together, one edge per consecutive pair.
	 *
	 * @param array $nodes The flow's nodes, in pipeline order.
	 *
	 * @return array<int, array<string, string>> The edges.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public static function chain(array $nodes): array {
		$ids = array_map(static fn (array $node): string => (string)$node['id'], array_values($nodes));

		$edges = [];
		$total = count($ids);
		for ($index = 0; $index < ($total - 1); $index++) {
			$from = $ids[$index];
			$to = $ids[($index + 1)];
			$edges[] = [
				'id' => $from . '-' . $to,
				'from' => $from,
				'to' => $to,
			];
		}

		return $edges;
	}//end chain()
}//end class
