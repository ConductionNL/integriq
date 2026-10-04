<?php

/**
 * Integriq DSO Identity.
 *
 * Who a verified STAM push acts as: the Nextcloud account of the instance's
 * `dso-stam` consumer, plus that consumer's uuid. The uuid ends up on the
 * stored verzoek as `receivedVia.consumer`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCP\IUser;

/**
 * The account and connection a verified STAM push acts as.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
 */
final class DsoIdentity {
	/**
	 * Constructor.
	 *
	 * @param IUser  $account      The Nextcloud account every write runs as.
	 * @param string $consumerUuid The uuid of the `dso-stam` consumer.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function __construct(
		public readonly IUser $account,
		public readonly string $consumerUuid,
	) {

	}//end __construct()
}//end class
