<?php

/**
 * OpenConnector Admin Settings — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Settings\GenericAdminSettings}. It carries no
 * settings logic of its own: the engine renders openconnector's
 * `settings/admin` template and provides `version`/`configuredVersion`/
 * `isUpToDate` as initial state. This class exists only so the class name
 * referenced by `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`
 * (dozens of admin-only controller methods across the app) and by
 * `appinfo/info.xml` `<settings><admin>` resolves in openconnector's own
 * namespace — required because NC instantiates settings/attribute-referenced
 * classes by their concrete class name.
 *
 * `getAuthorizedAppConfig()` (the auth-critical method every
 * `#[AuthorizedAdminSetting]` gate depends on) returns an empty map — byte
 * identical to the pre-adoption bespoke implementation — so every existing
 * admin gate keeps its exact fail-closed (full-admin-only) posture. The
 * pre-adoption `mySetting` template parameter is dropped: it was dead code
 * (never read by `templates/settings/admin.php`, which only mounts the Vue
 * settings app).
 *
 * The constructor + service wiring (appId/sectionId/priority + collaborators)
 * is registered in
 * {@see \OCA\OpenConnector\AppInfo\Application::registerAppHostBoilerplate()}.
 *
 * @category Settings
 * @package  OCA\OpenConnector\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Settings;

use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;

/**
 * Admin settings panel for OpenConnector, delegated to the engine.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class OpenConnectorAdmin extends GenericAdminSettings {
}//end class
