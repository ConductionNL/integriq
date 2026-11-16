<?php

/**
 * Integriq RegisterConnectorExtensions Event.
 *
 * The collect event an extension app answers with its intake channels,
 * digital post providers, payment providers and connector templates.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

use OCA\Integriq\Service\Extension\ConnectorExtensionsCollectEvent;

/**
 * "Give me your connector adapters": dispatched once, when integriq first
 * needs one of its registries. A listener in an extension app adds its own
 * adapters, each with its app id. Integriq's built-ins are registered first
 * and keep their ids: a contribution that claims one is refused and logged.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class RegisterConnectorExtensionsEvent extends ConnectorExtensionsCollectEvent {
}//end class
