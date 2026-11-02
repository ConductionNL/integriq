<?php

/**
 * Contract of the EgressGuard.
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

use OCA\Integriq\Exception\EgressRefusedException;

/**
 * Public operations of {@see EgressGuard} that other components call.
 */
interface EgressGuardInterface {
	/**
	 * Refuse an outbound URL that may not be called.
	 *
	 * @param string $url The URL about to be called.
	 *
	 * @return void
	 *
	 * @throws EgressRefusedException When the URL is refused; the message names
	 *                                the rule and the host, never a credential.
	 *
	 * @spec openspec/changes/events-async-api-products/design.md
	 */
	public function assertAllowed(string $url): void;
}
