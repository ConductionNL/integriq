<?php
/**
 * Bootstrap file for PHPUnit tests
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// Nextcloud composer autoloader (provides OCP, OCA core classes without booting NC).
if (file_exists(__DIR__ . '/../../../lib/composer/autoload.php')) {
    require_once __DIR__ . '/../../../lib/composer/autoload.php';
}

// OpenRegister classes (SchemaMapper etc.) needed by SoftwareCatalogueService.
if (file_exists(__DIR__ . '/../../openregister/vendor/autoload.php')) {
    require_once __DIR__ . '/../../openregister/vendor/autoload.php';
}

// App-local vendor autoloader.
require_once __DIR__ . '/../vendor/autoload.php';
