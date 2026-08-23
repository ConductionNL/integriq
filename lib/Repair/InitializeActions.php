<?php

/**
 * Integriq InitializeActions Repair Step — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Repair\GenericInitializeActions}. It
 * carries no seeding logic of its own: the engine's
 * {@see \OCA\OpenRegister\AppHost\Service\GenericActionAuthService} reads/
 * writes the exact same `IAppConfig` key (`actions`, under the `integriq`
 * app id) as the still-bespoke {@see \OCA\Integriq\Service\ActionAuthService}
 * that every controller's `#[…]` action-RBAC check actually enforces against —
 * so seeding through the generic step and enforcing through the bespoke
 * service read/write the identical storage location (ADR-023). On fresh
 * install (empty matrix) it seeds from `lib/actions.seed.json`; any
 * admin-customised non-empty matrix is left untouched on upgrade.
 *
 * Wired via `appinfo/info.xml` `<repair-steps><post-migration>`.
 *
 * The constructor + service wiring is registered in
 * {@see \OCA\Integriq\AppInfo\Application::registerAppHostBoilerplate()}.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
 * @spec openspec/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;

/**
 * Seeds the action-authorization matrix on install, delegated to the engine.
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class InitializeActions extends GenericInitializeActions {
}//end class
