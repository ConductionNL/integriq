<?php

/**
 * Integriq DnsResolverInterface.
 *
 * The seam the alignment check reads through, so a test can hand it a zone
 * rather than the internet, and so an instance behind a resolver of its own
 * can bind something else.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * Reads TXT records.
 */
interface DnsResolverInterface {

	/**
	 * The TXT records published for one host.
	 *
	 * @param string $host The host.
	 *
	 * @return array<int,string> The records, empty when there are none or the lookup failed.
	 */
	public function txt(string $host): array;

}//end interface
