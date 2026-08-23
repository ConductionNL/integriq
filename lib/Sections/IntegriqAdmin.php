<?php

/**
 * Integriq Admin Section — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Settings\GenericSettingsSection}. It carries
 * no section logic of its own: id (`integriq`), display name, icon file
 * and priority are supplied by the factory in
 * {@see \OCA\Integriq\AppInfo\Application::registerAppHostBoilerplate()},
 * matching the pre-adoption values exactly (priority 97, `app-dark.svg`).
 *
 * The display name stays translated: the factory resolves integriq's own
 * scoped `IL10N` and calls `->t('Integriq')` before constructing the
 * generic section, so `getName()` still returns the localised string (the
 * generic base class itself has no l10n hook — it stores a plain string).
 *
 * This class exists only so the class name referenced by
 * `appinfo/info.xml` `<settings><admin-section>` resolves in integriq's
 * own namespace.
 *
 * @category Sections
 * @package  OCA\Integriq\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Sections;

use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;

/**
 * Admin section provider for Integriq, delegated to the engine.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class IntegriqAdmin extends GenericSettingsSection {
}//end class
