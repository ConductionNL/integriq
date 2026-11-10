<?php

/**
 * Integriq SLO framework import run.
 *
 * The state of one SloCurriculumSourceAdapter::importFramework() call as it
 * moves from the set profile and the loaded roots to the framework record and
 * its competencies. Built and read by the adapter only.
 *
 * @category Source
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

/**
 * One framework import: the set and tenant, and what each step produced.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */
final class FrameworkImportRun {

	/**
	 * SLO vakleergebied key => learniq Course uuid, normalised.
	 *
	 * @var array<string,string>
	 */
	public array $subjects = [];

	/**
	 * The set profile.
	 *
	 * @var array<string,mixed>
	 */
	public array $profile = [];

	/**
	 * The loaded roots: roots, rootInfo, rootUuid, sourceRef, originId.
	 *
	 * @var array<string,mixed>
	 */
	public array $loaded = [];

	/**
	 * The CC BY 4.0 attribution.
	 *
	 * @var array<string,mixed>
	 */
	public array $attribution = [];

	/**
	 * The framework's stable uuid.
	 *
	 * @var string
	 */
	public string $frameworkUuid = '';

	/**
	 * The learniq competency-framework record.
	 *
	 * @var array<string,mixed>
	 */
	public array $framework = [];

	/**
	 * The learniq competency records, parents first.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $competencies = [];

	/**
	 * The walk's stats plus the competency counts.
	 *
	 * @var array<string,int>
	 */
	public array $stats = [];

	/**
	 * Constructor.
	 *
	 * @param string $setKey   The set key, such as `fo-kerndoelen`.
	 * @param string $tenantId The learniq tenant uuid.
	 */
	public function __construct(
		public readonly string $setKey,
		public readonly string $tenantId,
	) {

	}//end __construct()

	/**
	 * Whether the set imports all of its roots as one framework.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function isAggregate(): bool {
		return ($this->profile['framework'] === 'aggregate');

	}//end isAggregate()

	/**
	 * The SLO root uuid the import ran for, or null for an aggregate set.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function rootUuid(): ?string {
		return $this->loaded['rootUuid'];

	}//end rootUuid()

	/**
	 * The root's title, subject keys and edition fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function rootInfo(): array {
		return $this->loaded['rootInfo'];

	}//end rootInfo()
}//end class
