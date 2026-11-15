<?php

/**
 * Stub for OC\AppFramework\DependencyInjection\DIContainer in bare unit runs.
 *
 * NC 35 declares `OCP\AppFramework\App::$container` as this private core class,
 * which the standalone nextcloud/ocp dev dependency does not ship. Without it a
 * test cannot build a container double of the declared type
 * (tests/Helpers/AppContainerInjection.php), and assigning an IAppContainer
 * double to the property throws a TypeError.
 *
 * @category Stub
 * @package  OCA\Integriq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OC\AppFramework\DependencyInjection;

use OCP\AppFramework\IAppContainer;

/**
 * Minimal stand-in: core's DIContainer implements IAppContainer, which is the
 * whole surface the tests stub.
 */
abstract class DIContainer implements IAppContainer {
}//end class
