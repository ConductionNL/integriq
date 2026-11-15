<?php

/**
 * Integriq SLO tree position.
 *
 * Where the tree walk is: the parent node an entity hangs under and the
 * entity's depth.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

/**
 * One position in an SLO tree walk.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */
final class SloTreePosition {

	/**
	 * Constructor.
	 *
	 * @param string|null $parentUuid SLO uuid of the parent node, or null at the top.
	 * @param int         $depth      Depth of the entity at this position.
	 */
	public function __construct(
		public readonly ?string $parentUuid,
		public readonly int $depth,
	) {
	}//end __construct()

	/**
	 * The position of the children of the node at this position.
	 *
	 * @param string|null $parentUuid The node's SLO uuid, or null when it is the framework.
	 *
	 * @return self One level deeper, under that node.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function childrenOf(?string $parentUuid): self {
		return new self(parentUuid: $parentUuid, depth: ($this->depth + 1));
	}//end childrenOf()
}//end class
