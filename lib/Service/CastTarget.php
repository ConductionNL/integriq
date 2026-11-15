<?php

/**
 * Integriq cast target.
 *
 * The field a mapping cast works on: the data being mapped and the key.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

use Adbar\Dot;

/**
 * One field of the data a mapping casts.
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
final class CastTarget {

	/**
	 * Constructor.
	 *
	 * @param Dot    $dotArray The data being mapped.
	 * @param string $key      The key of the field to cast.
	 */
	public function __construct(
		public readonly Dot $dotArray,
		public readonly string $key,
	) {

	}//end __construct()

	/**
	 * The field's current value.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function value(): mixed {
		return $this->dotArray->get($this->key);

	}//end value()
}//end class
