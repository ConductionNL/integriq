<?php

/**
 * Integriq unknown SLO curriculum set exception.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;

/**
 * It fails naming the set key and the keys that do exist.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */
class UnknownSloCurriculumSetException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $setKey The set key no profile is seeded under.
	 * @param array<int,string> $known Set keys that do exist.
	 */
	public function __construct(private readonly string $setKey, array $known = []) {
		$knownText = '(none)';
		if ($known !== []) {
			$knownText = implode(', ', $known);
		}

		parent::__construct(
			message: sprintf('No SLO curriculum set is seeded under the key "%s". Seeded keys: %s.', $setKey, $knownText)
		);
	}//end __construct()

	/**
	 * The set key no profile is seeded under.
	 *
	 * @return string Set key.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function getSetKey(): string {
		return $this->setKey;
	}//end getSetKey()
}//end class
