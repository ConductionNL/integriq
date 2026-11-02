<?php

/**
 * Contract of the RosterTargetConfiguration.
 *
 * @category Contract
 * @package  OCA\Integriq\Sources\Roster
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

namespace OCA\Integriq\Sources\Roster;

/**
 * Public operations of {@see RosterTargetConfiguration} that other components call.
 */
interface RosterTargetConfigurationInterface {
	/**
	 * The one delivery target.
	 */
	public const TARGET = 'planninq';

	/**
	 * The effective configuration for one source.
	 *
	 * @param string              $systemId  The rostering Source row id.
	 * @param array<string,mixed> $overrides The delivery's `groupMap` and `teacherMap`, if any.
	 *
	 * @return array{target:string,groupMap:array<string,string>,teacherMap:array<string,string>}
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003
	 */
	public function forSystem(string $systemId, array $overrides = []): array;
}
