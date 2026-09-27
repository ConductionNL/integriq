<?php

/**
 * Integriq Roster Target Configuration.
 *
 * Where a rostering delivery goes and how the school's own codes resolve to
 * fleet ids. The target is planninq (decision D10); per rostering Source row
 * an operator stores two JSON maps in integriq's app config:
 *
 *   roster.<systemId>.group_map    school group code   -> cohort id
 *   roster.<systemId>.teacher_map  school teacher code -> Nextcloud user id
 *
 * A delivery may pass its own maps (learniq passes the ones it knows); those
 * entries win over the stored ones.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the target and the code maps for one rostering source.
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003
 */
class RosterTargetConfiguration {
	/**
	 * The one delivery target.
	 */
	public const TARGET = 'planninq';

	/**
	 * App id used for IAppConfig look-ups.
	 */
	private const APP_ID = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config App config holding the stored maps.
	 * @param LoggerInterface $logger Logs an unreadable map.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The effective configuration for one source.
	 *
	 * @param string              $systemId  The rostering Source row id.
	 * @param array<string,mixed> $overrides The delivery's `groupMap` and `teacherMap`, if any.
	 *
	 * @return array{target:string,groupMap:array<string,string>,teacherMap:array<string,string>}
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003
	 */
	public function forSystem(string $systemId, array $overrides = []): array {
		// `+`, not array_merge(): a school code such as `3` or `1024` becomes an
		// integer key, and array_merge() renumbers integer keys, which would
		// silently point every numeric code at the wrong cohort. The left side
		// wins, so the delivery's entries go first.
		return [
			'target' => self::TARGET,
			'groupMap' => $this->stringMap(value: ($overrides['groupMap'] ?? []))
				+ $this->storedMap(systemId: $systemId, kind: 'group_map'),
			'teacherMap' => $this->stringMap(value: ($overrides['teacherMap'] ?? []))
				+ $this->storedMap(systemId: $systemId, kind: 'teacher_map'),
		];
	}//end forSystem()

	/**
	 * Read one stored map; an unreadable value is logged and counts as empty.
	 *
	 * @param string $systemId The rostering Source row id.
	 * @param string $kind     `group_map` or `teacher_map`.
	 *
	 * @return array<string,string>
	 */
	private function storedMap(string $systemId, string $kind): array {
		$key = "roster.{$systemId}.{$kind}";
		$raw = $this->config->getValueString(self::APP_ID, $key, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->warning('roster-target.unreadable-map', ['key' => $key]);
			return [];
		}

		return $this->stringMap(value: $decoded);
	}//end storedMap()

	/**
	 * Keep only string-to-string entries with non-empty keys and values.
	 *
	 * @param mixed $value A candidate map.
	 *
	 * @return array<string,string>
	 */
	private function stringMap(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$map = [];
		foreach ($value as $code => $id) {
			if (is_scalar($id) === true && trim((string)$code) !== '' && trim((string)$id) !== '') {
				$map[trim((string)$code)] = trim((string)$id);
			}
		}

		return $map;
	}//end stringMap()
}//end class
