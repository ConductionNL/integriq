<?php

/**
 * Integriq SystemDnsResolver.
 *
 * The production binding: the host's own resolver. A lookup that fails
 * answers no records rather than throwing, because a resolver that is briefly
 * unreachable must read as "not checked", never as "the record is gone".
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
 * Reads TXT records through the host's resolver.
 */
class SystemDnsResolver implements DnsResolverInterface {

	/**
	 * The TXT records published for one host.
	 *
	 * @param string $host The host.
	 *
	 * @return array<int,string> The records.
	 */
	public function txt(string $host): array {
		if (trim($host) === '' || function_exists('dns_get_record') === false) {
			return [];
		}

		$records = @dns_get_record($host, DNS_TXT);
		if (is_array($records) === false) {
			return [];
		}

		$texts = [];
		foreach ($records as $record) {
			$entries = ($record['entries'] ?? null);
			if (is_array($entries) === true) {
				$texts[] = implode('', $entries);
				continue;
			}

			if (isset($record['txt']) === true) {
				$texts[] = (string)$record['txt'];
			}
		}

		return $texts;

	}//end txt()

}//end class
