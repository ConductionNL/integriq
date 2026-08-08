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

// Whether a real Nextcloud will be bootstrapped at the foot of this file.
//
// Decided HERE rather than relied on through class_exists() below, because the
// class_exists() guards cannot answer this question: they run before base.php
// is required, so core's autoloader is not registered yet and every core class
// reports "absent" even when core is about to define it. Under CI, where the
// app is checked out inside a server, the stubs therefore always won — and a
// stub that shadows a real class is worse than a missing one:
//
//   - OC\Hooks\Emitter shadowed core's interface, whose OC\Files\Node\LazyFolder
//     then failed to load against it. A parse-time fatal, exit 255, before a
//     single test ran.
//   - Doctrine\DBAL\Query\Expression\ExpressionBuilder shadowed the real
//     Doctrine class that core's own ExpressionBuilder extends, so loading the
//     app config died on an undefined eq().
//
// Both were reported as four failing PHPUnit legs that had in fact executed
// nothing at all, on development as well as on branches.
//
// The stubs that stand in for CORE and its VENDOR are therefore registered only
// in the bare, no-server mode they were written for. The stubs for peer APPS
// (OCA\OpenRegister, OCA\Tables, OCA\Forms) stay unconditional: those apps
// genuinely may not be installed, and their class_exists() guards do work,
// because nothing else is racing to define them.
$ncBase    = __DIR__ . '/../../../lib/base.php';
$ncConfig  = __DIR__ . '/../../../config/config.php';
$ncPresent = (defined('OC_CONSOLE') === false
    && is_readable($ncConfig) === true
    && file_exists($ncBase) === true);

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
        //
        // Bare mode only: a server ships the real doctrine/dbal, and standing in
        // for it there breaks core rather than completing it.
        if ($ncPresent === false) {
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
        }//end if

        // OCA\OpenRegister AppHost stubs — peer app not in vendor. Used by the
        // openconnector HealthController delegation path (licence-and-or-
        // requirement-honesty change).
        if (class_exists('OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Controller/GenericHealthController.php';
        }

        // OCA\OpenRegister AppHost observability stubs — peer app not in
        // vendor. OpenConnectorMetricsProvider implements IMetricsProvider
        // and returns MetricSample instances (retry-and-circuit-breaker-policies,
        // REQ-PROM-011); MetricSample must load before IMetricsProvider's
        // docblock @return type is referenced.
        if (class_exists('OCA\\OpenRegister\\AppHost\\Observability\\MetricSample') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Observability/MetricSample.php';
        }

        if (interface_exists('OCA\\OpenRegister\\AppHost\\IMetricsProvider') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/IMetricsProvider.php';
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

        // connector-catalog-ui: CatalogRegistryService reads OR's
        // IntegrationRegistry — stub both the interface and the registry
        // class so unit tests can mock them without a live OR install.
        if (interface_exists('OCA\\OpenRegister\\Service\\Integration\\IntegrationProvider') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Integration/IntegrationProvider.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Integration\\IntegrationRegistry') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Integration/IntegrationRegistry.php';
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
        //
        // Bare mode only, like the Doctrine stubs: core defines this itself, and
        // its OC\Files\Node\LazyFolder implements it. The stub's declaration is
        // kept byte-compatible with core's as a second line of defence, since a
        // mismatch here is a parse-time fatal rather than a test failure.
        if ($ncPresent === false && interface_exists('OC\\Hooks\\Emitter') === false) {
            require_once $stubsDir . '/OC/Hooks/Emitter.php';
        }

        // flow-workflowengine-integration: OC\AppFramework\Http\Request stub.
        // EndpointService::buildSyntheticRequest() constructs NC's REAL
        // concrete IRequest implementation at runtime (design.md Decision 5);
        // it is a private `\OC\` class, absent from the standalone
        // `nextcloud/ocp` dev-dependency, so EndpointServiceTest needs this
        // stand-in to exercise triggerFromFlow() without a live NC install.
        if ($ncPresent === false && class_exists('OC\\AppFramework\\Http\\Request') === false) {
            require_once $stubsDir . '/OC/AppFramework/Http/Request.php';
        }

        // api-product-gateway: OC\AppFramework\Http stub (STATUS_* constants) —
        // see tests/stubs/OC/AppFramework/Http.php docblock for why this was a
        // pre-existing, previously-unsurfaced gap.
        if ($ncPresent === false && class_exists('OC\\AppFramework\\Http') === false) {
            require_once $stubsDir . '/OC/AppFramework/Http.php';
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

        // ValidationException — what ProductSubscriptionsController catches to
        // turn a failed activation into a 400 instead of a 500. Missing here
        // made both of that test's failure cases error out on the NC stable31
        // legs (no openregister checkout) while stable32 passed.
        if (class_exists('OCA\\OpenRegister\\Exception\\ValidationException') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Exception/ValidationException.php';
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

        // nextcloud-event-hub: the four OCP\Calendar\Events\* stubs that used to
        // be required here are GONE, along with tests/stubs/OCP/Calendar/ (#1174).
        // They existed because those classes are `@since 32.0.0` and this repo
        // pinned `nextcloud/ocp: dev-stable29`, so vendor/nextcloud/ocp did not
        // carry them. The pin is now `^32.0` and it does, so the calendar
        // listeners are exercised against the REAL OCP classes — a hand-written
        // stub of an API you also declare a hard dependency on can only ever
        // agree with itself.
        //
        // nextcloud-event-hub: OCA\DAV\Events\Cached* stubs — `dav` is an NC
        // core app not present in the standalone composer dev-environment.
        if (class_exists('OCA\\DAV\\Events\\CachedCalendarObjectCreatedEvent') === false) {
            require_once $stubsDir . '/OCA/DAV/Events/CachedCalendarObjectCreatedEvent.php';
            require_once $stubsDir . '/OCA/DAV/Events/CachedCalendarObjectUpdatedEvent.php';
            require_once $stubsDir . '/OCA/DAV/Events/CachedCalendarObjectDeletedEvent.php';
        }

        // nextcloud-event-hub: OCA\Tables\* stubs — optional App Store app,
        // not present in this environment (verified against public source —
        // see discovery.md). Model must load before the events that type-hint it.
        if (class_exists('OCA\\Tables\\Model\\Public\\Row') === false) {
            require_once $stubsDir . '/OCA/Tables/Model/Public/Row.php';
            require_once $stubsDir . '/OCA/Tables/Event/AbstractRowEvent.php';
            require_once $stubsDir . '/OCA/Tables/Event/RowAddedEvent.php';
            require_once $stubsDir . '/OCA/Tables/Event/RowUpdatedEvent.php';
            require_once $stubsDir . '/OCA/Tables/Event/RowDeletedEvent.php';
        }

        // nextcloud-event-hub: OCA\Forms\* stubs — optional App Store app,
        // not present in this environment (verified against public source —
        // see discovery.md). Db entities must load before the events that
        // type-hint them.
        if (class_exists('OCA\\Forms\\Db\\Form') === false) {
            require_once $stubsDir . '/OCA/Forms/Db/Form.php';
            require_once $stubsDir . '/OCA/Forms/Db/Submission.php';
            require_once $stubsDir . '/OCA/Forms/Events/AbstractFormEvent.php';
            require_once $stubsDir . '/OCA/Forms/Events/FormSubmittedEvent.php';
        }

        // adopt-apphost: AppHost boilerplate stubs (peer app not in vendor).
        // The leaf `OpenConnectorAdmin` (Settings + Sections) and
        // `InitializeActions` classes extend these directly, so autoloading
        // the subclass requires the parent to resolve.
        if (class_exists('OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Settings/GenericAdminSettings.php';
        }

        if (class_exists('OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Settings/GenericSettingsSection.php';
        }

        if (class_exists('OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Service/GenericActionAuthService.php';
        }

        if (class_exists('OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeActions') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/AppHost/Repair/GenericInitializeActions.php';
        }

        // Flow-engine stubs (openconnector-flow-nodes change). The two
        // contributed node classes `implements IFlowNode`, so the interface
        // must exist before they are parsed — exactly the compile-time
        // reference the runtime `class_exists()` guard in Application.php
        // exists to avoid resolving on an instance without a flow engine.
        if (interface_exists('OCA\\OpenRegister\\Service\\Flow\\IFlowNode') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Flow/IFlowNode.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Flow/FlowNodeRegistry.php';
        }

        if (class_exists('OCA\\OpenRegister\\Service\\Flow\\RegisterFlowNodesEvent') === false) {
            require_once $stubsDir . '/OCA/OpenRegister/Service/Flow/RegisterFlowNodesEvent.php';
        }
    }
}

// Bootstrap Nextcloud only when the config file is readable (i.e., in a
// properly provisioned dev/CI environment). Skip silently in standalone mode —
// OCP stubs are sufficient for unit-only test suites.
//
// $ncPresent, decided at the top of this file, is the same condition; the core
// and vendor stubs above were skipped precisely because this block is about to
// run and provide the real classes.
if ($ncPresent === true) {
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
