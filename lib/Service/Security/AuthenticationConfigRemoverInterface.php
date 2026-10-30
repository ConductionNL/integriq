<?php

/**
 * Contract of the AuthenticationConfigRemover.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
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

namespace OCA\Integriq\Service\Security;

use LogicException;

/**
 * Public operations of {@see AuthenticationConfigRemover} that other components call.
 */
interface AuthenticationConfigRemoverInterface {
	/**
	 * Clear `authenticationConfig` across the fleet — ONLY when explicitly opted in.
	 *
	 * Per-source isolation: every source is read, decided and saved independently
	 * inside its own try/catch, so one failure can neither abort the batch nor leave
	 * another source half-written.
	 *
	 * @param integer $limit Maximum number of sources to inspect.
	 * @param boolean $optIn MUST be true. The caller has to say so explicitly.
	 *
	 * @return array<string, mixed> The per-source outcome report (no values, ever).
	 *
	 * @throws LogicException When $optIn is not true — the deletion gate.
	 *
	 * PHPMD's BooleanArgumentFlag rule normally has a point: a boolean argument usually
	 * means one method is doing two jobs and should be split. Not here. `$optIn` does not
	 * select a BEHAVIOUR — there is only one behaviour, and the flag is the CONSENT to
	 * perform it. Splitting it into `removeAll()` / `removeAllWithoutOptIn()` would create
	 * exactly the ungated entry point this design exists to prevent, and defaulting it to
	 * false is what makes "forgot to opt in" fail loudly instead of deleting credentials.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
	 */
	public function removeAll(int $limit = 1000, bool $optIn = false): array;
}
