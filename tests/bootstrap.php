<?php

/**
 * PHPUnit bootstrap for OpenConnector unit tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openconnector.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader. Use `require` (not `require_once`) so the
// ClassLoader instance is returned even when PHPUnit has already pulled it in.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the OCP/NCU namespaces from the nextcloud/ocp dev dependency so that
// unit tests can run in a bare environment (no installed Nextcloud server). When
// NC is present its own autoloader provides these and these mappings are inert.
// Must come BEFORE the Doctrine/OpenRegister stub registration because the
// ObjectEntity stub extends OCP\AppFramework\Db\Entity.
if ($autoloader instanceof \Composer\Autoload\ClassLoader && is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
    if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/NCU') === true) {
        $autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
    }
}

// Register test stubs for Doctrine\DBAL\* (referenced at parse-time by OCP
// QueryBuilder interfaces) and OCA\OpenRegister\* (peer app not in vendor).
// Stubs are guarded by class_exists() so they never clobber a real class when
// running inside a full Nextcloud installation.
//
// Important: The Composer ClassLoader caches "missing" class lookups after the
// first failed autoload. Eagerly require_once the stub files to guarantee the
// classes are defined regardless of lookup-cache state.
if ($autoloader instanceof \Composer\Autoload\ClassLoader) {
    $stubsDir = __DIR__ . '/stubs';
    if (is_dir($stubsDir) === true) {
        // Doctrine\DBAL stubs — required by OCP\DB\QueryBuilder\IQueryBuilder
        // and OCP\DB\QueryBuilder\IExpressionBuilder which reference constants
        // at class-body level (not inside methods), so they must exist before
        // PHP parses those OCP interface files.
        if (class_exists('Doctrine\\DBAL\\ParameterType') === false) {
            require_once $stubsDir . '/Doctrine/DBAL/ParameterType.php';
            require_once $stubsDir . '/Doctrine/DBAL/ArrayParameterType.php';
        }

        if (class_exists('Doctrine\\DBAL\\Query\\Expression\\ExpressionBuilder') === false) {
            require_once $stubsDir . '/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
        }

        // OCA\OpenRegister stubs — peer app not in vendor.
        if (class_exists('OCA\\OpenRegister\\Db\\ObjectEntity') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Db/ObjectEntity.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\ObjectService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/ObjectService.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Integration\\AbstractIntegrationProvider') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Integration/AbstractIntegrationProvider.php';
        }

        if (class_exists('OCA\\OpenRegister\\Db\\RegisterMapper') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Db/RegisterMapper.php';
        }

        if (class_exists('OCA\\OpenRegister\\Db\\SchemaMapper') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Db/SchemaMapper.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\FileService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/FileService.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\OrganisationService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/OrganisationService.php';
        }
    }
}

// Bootstrap Nextcloud only when the config file is readable (i.e., in a
// properly provisioned dev/CI environment). Skip silently in standalone mode —
// OCP stubs are sufficient for unit-only test suites.
$ncBase   = __DIR__ . '/../../../lib/base.php';
$ncConfig = __DIR__ . '/../../../config/config.php';
if (defined('OC_CONSOLE') === false && is_readable($ncConfig) === true) {
    if (file_exists($ncBase) === true) {
        try {
            require_once $ncBase;
        } catch (\Throwable $e) {
            // NC not fully installed — unit tests continue with vendor stubs only.
        }
    }

    // Load Test\TestCase and other NC test classes (NC convention).
    if (file_exists(__DIR__ . '/../../../tests/autoload.php') === true) {
        require_once __DIR__ . '/../../../tests/autoload.php';
    }

    // Load all enabled apps if Nextcloud is available.
    if (class_exists('OC_App')) {
        \OC_App::loadApps();

        // Load our specific app.
        \OC_App::loadApp('openconnector');

        // Clear hooks for testing.
        OC_Hook::clear();
    }
}
