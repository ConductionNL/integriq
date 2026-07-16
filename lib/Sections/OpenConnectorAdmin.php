<?php

/**
 * OpenConnector Admin Section — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Settings\GenericSettingsSection}. It carries
 * no section logic of its own: id (`openconnector`), display name, icon file
 * and priority are supplied by the factory in
 * {@see \OCA\OpenConnector\AppInfo\Application::registerAppHostBoilerplate()},
 * matching the pre-adoption values exactly (priority 97, `app-dark.svg`).
 *
 * The display name stays translated: the factory resolves openconnector's own
 * scoped `IL10N` and calls `->t('Open Connector')` before constructing the
 * generic section, so `getName()` still returns the localised string (the
 * generic base class itself has no l10n hook — it stores a plain string).
 *
 * This class exists only so the class name referenced by
 * `appinfo/info.xml` `<settings><admin-section>` resolves in openconnector's
 * own namespace.
 *
 * @category Sections
 * @package  OCA\OpenConnector\Sections
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

namespace OCA\OpenConnector\Sections;

use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;

/**
 * Admin section provider for OpenConnector, delegated to the engine.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class OpenConnectorAdmin extends GenericSettingsSection
{
}//end class
