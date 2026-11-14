<?php

/**
 * Integriq — DUO ROD adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item
 * or a `/beheer` route. This descriptor is that catalogue entry for DUO ROD
 * (Register Onderwijsdeelnemers): it declares the adapter's identity,
 * category, the provider bindings it offers, and the JSON configuration
 * schema a Verbinding (source) fills in to select it.
 *
 * Live traffic over the `edukoppeling` binding is gated on the DUO
 * software-vendor certificate holder decision (M3(c), open in
 * `market-intelligence/learniq/decisions.md`) — an operational/governance
 * gate, not a reason this card is absent from the catalogue.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Rod;

use OCA\Integriq\Adapters\DuoEdukoppelingAdapter;

/**
 * Catalogue descriptor for the DUO ROD adapter (ADR-017 Rule 1).
 *
 * @spec openspec/specs/rod-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class RodAdapter extends DuoEdukoppelingAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'rod';

	/**
	 * Catalogue id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rod-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rod-adapter/spec.md
	 */
	public function label(): string {
		return 'DUO ROD';
	}//end label()
}//end class
