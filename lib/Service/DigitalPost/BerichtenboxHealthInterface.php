<?php

/**
 * Contract of the BerichtenboxHealth.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

use DateTimeImmutable;

/**
 * Public operations of {@see BerichtenboxHealth} that other components call.
 */
interface BerichtenboxHealthInterface {
	/**
	 * The findings.
	 *
	 * @param DateTimeImmutable $now The time to measure from.
	 *
	 * @return array{sources:int,waiting:int,certificates:array<int,array{slug:string,problem:string,validTo:string}>}
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
	 */
	public function findings(DateTimeImmutable $now): array;
}
