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

        if (class_exists('Doctrine\\DBAL\\Types\\Types') === false) {
            require_once $stubsDir . '/Doctrine/DBAL/Types/Types.php';
        }

        if (class_exists('Doctrine\\DBAL\\Query\\Expression\\ExpressionBuilder') === false) {
            require_once $stubsDir . '/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
        }

        // OCA\OpenRegister AppHost stubs — peer app not in vendor. Used by the
        // openconnector HealthController delegation path (licence-and-or-
        // requirement-honesty change).
        if (class_exists('OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Controller/GenericHealthController.php';
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

        if (class_exists('OCA\\OpenRegister\\Db\\Mapping') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Db/Mapping.php';
        }

        // OC\Hooks\Emitter — required by OCP\Files\IRootFolder at interface-load time.
        if (interface_exists('OC\\Hooks\\Emitter') === false) {
            require_once $stubsDir . '/OC/Hooks/Emitter.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\OrganisationService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/OrganisationService.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\RegisterResolverService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/RegisterResolverService.php';
        }

        // Credential-broker stubs (source-broker-credentials change). The
        // broker stub mirrors the OR origin/development request() signature
        // WITHOUT the acting-user parameter, so the reflection-based
        // feature-detection path is exercised realistically in tests.
        if (class_exists('OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Credential/CredentialBrokerService.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Credential\\CredentialAccessDeniedException') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Credential/CredentialAccessDeniedException.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Credential\\CredentialUpstreamException') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Credential/CredentialUpstreamException.php';
        }

        // Handoff engine stubs (open-formulieren-intake change). HandoffService
        // is the real cross-app DI target OpenFormulierenIntakeService injects
        // (same pattern as ObjectService); HandoffException/NotAuthorizedException
        // are the typed failures it propagates.
        if (class_exists('OCA\\OpenRegister\\Service\\Handoff\\HandoffService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Handoff/HandoffService.php';
        }

        if (class_exists('OCA\\OpenRegister\\Exception\\HandoffException') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Exception/HandoffException.php';
        }

        if (class_exists('OCA\\OpenRegister\\Exception\\NotAuthorizedException') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Exception/NotAuthorizedException.php';
        }

        // OCA\OpenRegister\Event\Object{Created,Updated,Deleted}Event stubs —
        // peer app not in vendor. Used by outbound-webhooks-activation's
        // CloudEventListenerTest to construct real event instances (PHPUnit
        // cannot mock these: they are constructor-only DTOs in the real app,
        // and mocking them would not exercise the
        // getObject()/getNewObject()/getOldObject() shape this listener
        // depends on).
        if (class_exists('OCA\\OpenRegister\\Event\\ObjectCreatedEvent') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Event/ObjectCreatedEvent.php';
        }

        if (class_exists('OCA\\OpenRegister\\Event\\ObjectUpdatedEvent') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Event/ObjectUpdatedEvent.php';
        }

        if (class_exists('OCA\\OpenRegister\\Event\\ObjectDeletedEvent') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Event/ObjectDeletedEvent.php';
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
