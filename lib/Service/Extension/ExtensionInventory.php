<?php

/**
 * Integriq ExtensionInventory.
 *
 * Builds the Service-side registries that take contributions from extension
 * apps, so the collect event has run, and hands back the collector's record.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Extension
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Extension;

use OCA\Integriq\Service\Catalog\ContributedTemplates;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;

/**
 * What `occ integriq:extensions` lists: every contribution to the digital
 * post, payment and template seams, registered and refused. Using each
 * registry once makes it ask the extension apps.
 *
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */
class ExtensionInventory {

	/**
	 * Constructor.
	 *
	 * @param ConnectorExtensionCollector $collector   Holds the record of every contribution.
	 * @param DigitalPostProviderRegistry $digitalPost The digital post provider registry.
	 * @param PaymentProviderRegistry     $payments    The payment provider registry.
	 * @param ContributedTemplates|null   $templates   The contributed connector templates, read so they are listed too.
	 *
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function __construct(
		private readonly ConnectorExtensionCollector $collector,
		private readonly DigitalPostProviderRegistry $digitalPost,
		private readonly PaymentProviderRegistry $payments,
		private readonly ?ContributedTemplates $templates = null,
	) {
	}//end __construct()

	/**
	 * Use the digital post, payment and template registries once, then return the record.
	 *
	 * @return array<int,array{seam: string, id: string, appId: string, class: string, refused: bool, reason: string}>
	 *
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function record(): array {
		$this->digitalPost->ids();
		$this->payments->ids();
		$this->templates?->cards();

		return $this->collector->record();
	}//end record()
}//end class
