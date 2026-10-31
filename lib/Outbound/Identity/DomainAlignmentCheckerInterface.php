<?php

/**
 * Contract of the DomainAlignmentChecker.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
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

namespace OCA\Integriq\Outbound\Identity;

/**
 * Public operations of {@see DomainAlignmentChecker} that other components call.
 */
interface DomainAlignmentCheckerInterface {
	/**
	 * Check one identity's domain.
	 *
	 * @param array<string,mixed> $identity The identity, whose address names the domain.
	 *
	 * @return array<string,mixed> Per record: its state, what is published and what to publish.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DomainAlignmentChecker::check()
	 */
	public function check(array $identity): array;

	/**
	 * Whether a checked alignment is anything other than fully aligned.
	 *
	 * @param array<string,mixed> $alignment The result of {@see check()}.
	 *
	 * @return bool True when at least one record is absent or misaligned.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DomainAlignmentChecker::isAtRisk()
	 */
	public function isAtRisk(array $alignment): bool;
}
