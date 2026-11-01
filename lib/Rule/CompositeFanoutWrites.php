<?php

/**
 * Integriq CompositeFanoutWrites.
 *
 * The state of one composite fan-out: the request body, the parent
 * configuration, and every object written so far, so a failure can roll
 * them all back.
 *
 * @category Rule
 * @package  OCA\Integriq\Rule
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
 * @spec openspec/specs/rule-pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Rule;

/**
 * Per-run state of a composite fan-out.
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
final class CompositeFanoutWrites {

	/**
	 * Every object written so far, in write order.
	 *
	 * @var array<int, array{register: string, schema: string, uuid: string}>
	 */
	public array $created = [];

	/**
	 * Uuids of already-written children, keyed by their bodyKey.
	 *
	 * @var array<string, string>
	 */
	public array $childUuidsByBodyKey = [];

	/**
	 * The uuid of the written parent, null until it is written.
	 *
	 * @var string|null
	 */
	public ?string $parentUuid = null;

	/**
	 * Constructor.
	 *
	 * @param array $body         The request body the fan-out splits.
	 * @param array $parentConfig The parent's register, schema and optional bodyKey.
	 */
	public function __construct(
		public readonly array $body,
		public readonly array $parentConfig,
	) {

	}//end __construct()
}//end class
