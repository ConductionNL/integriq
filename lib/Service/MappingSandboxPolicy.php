<?php

/**
 * The Twig sandbox allow-list every mapping template is rendered under.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Twig\Sandbox\SecurityPolicy;

/**
 * Holds the allow-list of tags, filters and functions a mapping template may
 * use. Kept apart from MappingService so the service stays within the
 * class-size limits of the PHPMD gate and the list can be reviewed on its own.
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
final class MappingSandboxPolicy {

	/**
	 * Build the sandbox policy every mapping template is rendered under.
	 *
	 * Mapping values, synchronization endpoint/query templates and extra-data
	 * endpoint templates are all authored configuration rendered by this one
	 * environment. Without a sandbox Twig still accepts a plain PHP callable as
	 * the arrow of `map`/`filter`/`reduce`/`sort`, so `{{ ['id']|map('system') }}`
	 * ran a shell command. Sandbox mode makes Twig refuse any arrow that is not a
	 * Closure, so the arrow filters themselves are allowed: `|map(x => x * 2)`
	 * renders, `|map('system')` throws. The allow-list holds exactly the tags,
	 * filters and functions the shipped mappings, connector fragments and
	 * documented examples use (the mapping guide documents `map`; mappings
	 * stored in a register can use any of the arrow filters) plus this
	 * environment's own MappingExtension. No method or property of a PHP
	 * object may be reached: mapping input is plain arrays.
	 *
	 * @return SecurityPolicy The allow-list policy.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function create(): SecurityPolicy {
		return new SecurityPolicy(
			allowedTags: ['if', 'for', 'set'],
			allowedFilters: [
				// Core filters used by shipped mappings and connector fragments.
				'abs',
				'capitalize',
				'column',
				'date',
				'date_modify',
				'default',
				'e',
				'escape',
				'filter',
				'first',
				'format',
				'join',
				'json_encode',
				'keys',
				'last',
				'length',
				'lower',
				'map',
				'merge',
				'nl2br',
				'number_format',
				'raw',
				'reduce',
				'replace',
				'reverse',
				'round',
				'slice',
				'sort',
				'split',
				'striptags',
				'title',
				'trim',
				'upper',
				'url_encode',
				// MappingExtension filters.
				'b64enc',
				'b64dec',
				'json_decode',
				'slugify',
			],
			allowedMethods: [],
			allowedProperties: [],
			allowedFunctions: [
				'date',
				'max',
				'min',
				'random',
				'range',
				// MappingExtension functions.
				'generateUuid',
				'uuidFor',
				'executeMapping',
				'getFileContents',
				'getFiles',
				'getTargetIdByOriginId',
				'getOriginIdByTargetId',
			],
		);
	}//end create()

}//end class
