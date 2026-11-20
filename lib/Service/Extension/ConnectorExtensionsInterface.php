<?php

/**
 * Integriq ConnectorExtensionsInterface.
 *
 * What a registry outside the Service component needs from the connector
 * extension collector: the one collect answer, and a place to report what it
 * took and what it refused.
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
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Extension;

/**
 * The collect answer of the extension apps, shared by every registry, and the
 * record of what each contributed and what was refused.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
interface ConnectorExtensionsInterface {

	public const SEAM_INTAKE_CHANNEL = 'intake channel';
	public const SEAM_DIGITAL_POST   = 'digital post provider';
	public const SEAM_PAYMENT        = 'payment provider';
	public const SEAM_TEMPLATES      = 'connector templates';

	/**
	 * The extension apps' answer, asked for at most once.
	 *
	 * @return ConnectorExtensionsCollectEvent
	 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function collect(): ConnectorExtensionsCollectEvent;

	/**
	 * Record a contribution a registry took.
	 *
	 * @param string $seam  The seam (one of the SEAM_ constants).
	 * @param string $id    The id it was registered under.
	 * @param string $appId The contributing app.
	 * @param object $item  The contributed adapter, provider or template library.
	 *
	 * @return void
	 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function accepted(string $seam, string $id, string $appId, object $item): void;

	/**
	 * Record, and log, a contribution a registry refused because its id was taken.
	 *
	 * @param string $seam    The seam (one of the SEAM_ constants).
	 * @param string $id      The id it claimed.
	 * @param string $appId   The contributing app.
	 * @param object $item    The refused adapter, provider or template library.
	 * @param string $keptApp The app that holds the id.
	 *
	 * @return void
	 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function refused(string $seam, string $id, string $appId, object $item, string $keptApp): void;

	/**
	 * Everything contributed so far, registered and refused, in order.
	 *
	 * @return array<int,array{seam: string, id: string, appId: string, class: string, refused: bool, reason: string}>
	 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function record(): array;
}//end interface
