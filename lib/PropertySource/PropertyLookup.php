<?php

/**
 * Integriq PropertyLookup: one authoritative read of a property value in
 * PropertySourceResolver — which provider and identifier, when the read
 * started and what the cache held for it.
 *
 * @category Service
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

/**
 * One property read in progress. Built and used by PropertySourceResolver only.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005
 */
final class PropertyLookup {
	/**
	 * Unix time the read started.
	 *
	 * @var integer
	 */
	public int $now = 0;

	/**
	 * What the cache held for this identifier, as the source read it then.
	 *
	 * @var ResolvedValue|null
	 */
	public ?ResolvedValue $cached = null;

	/**
	 * Constructor.
	 *
	 * @param string $providerId Provider id the property declares.
	 * @param string $identifier Identifier at the source.
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $identifier,
	) {
	}//end __construct()
}//end class
