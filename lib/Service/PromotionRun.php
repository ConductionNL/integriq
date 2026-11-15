<?php

/**
 * Integriq PromotionRun.
 *
 * One promotion of a configuration to a target environment while
 * PromotionService runs it: what is promoted where, by whom, with which
 * credential rebindings, when it started, the call log of the import on the
 * target, and how many placeholders were rebound. Built per promote() call and
 * never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/environments-and-promotion/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;

/**
 * One promotion.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md
 */
final class PromotionRun {

	/**
	 * Operator-supplied rebindings, each `{sourceSlug, field?, credentialId?, credentialName?}`.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $credentialBindings = [];

	/**
	 * Who promotes.
	 *
	 * @var string
	 */
	public string $actorUid = '';

	/**
	 * The environment promoted from.
	 *
	 * @var string
	 */
	public string $fromEnvironmentSlug = 'local';

	/**
	 * When the promotion started.
	 *
	 * @var DateTime
	 */
	public DateTime $startedAt;

	/**
	 * The call log of the import on the target, once it was sent.
	 *
	 * @var string|null
	 */
	public ?string $callLogId = null;

	/**
	 * How many flagged placeholders a rebinding matched.
	 *
	 * @var int
	 */
	public int $rebindCount = 0;

	/**
	 * Whether the promoter accepted failing test cases (REQ-IRT-005).
	 * @var bool
	 */
	public bool $overrideFailingTests = false;

	/**
	 * How many test cases failed when the promotion was checked.
	 * @var int
	 */
	public int $failedTestCount = 0;

	/**
	 * Constructor.
	 *
	 * @param string $configurationId The configuration group id.
	 * @param string $targetEnvironmentSlug The target environment's slug.
	 */
	public function __construct(
		public readonly string $configurationId,
		public readonly string $targetEnvironmentSlug,
	) {
		$this->startedAt = new DateTime();
	}//end __construct()
}//end class
