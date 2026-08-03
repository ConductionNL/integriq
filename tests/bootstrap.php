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

// Should this run boot a real Nextcloud, or stay hermetic on the stubs below?
//
// DEFAULT: hermetic. The suite phpunit.xml declares is `tests/Unit` ONLY, and
// everything below exists precisely so that suite needs no server. Booting one
// anyway is not a bonus — it is a conflict, because these stand-ins are loaded
// by NAME: a stub defined first SHADOWS core's real class, and core's own code
// is then type-checked against our stub. Enabling this repo's PHPUnit job for
// the first time surfaced two such fatals, each killing the runner before it
// could finish:
//
//   * `OC\Hooks\Emitter` — the stub's typed `listen(): void` was incompatible
//     with core's untyped `LazyFolder::listen()`, so compiling `LazyRoot`
//     (which implements `IRootFolder extends Emitter`) fataled. Fixed
//     separately by making that stub mirror core, but the shadowing remains
//     structural.
//   * `Doctrine\DBAL\Query\Expression\ExpressionBuilder` — the stub has no
//     `eq()`, so core's `OC\AppConfig::loadConfig()` died with "Call to
//     undefined method ...ExpressionBuilder::eq()" during boot.
//
// The per-class `class_exists()` guards below cannot prevent this: they run
// BEFORE `lib/base.php` would be required, so core's classes are not yet
// loadable, every probe answers false, and the stub always wins.
//
// Booting also swaps the OpenRegister stub for the REAL ObjectService, whose
// `find()` signature this stub knowingly diverges from (see the "KNOWN,
// DELIBERATE REMAINING DRIFT" note in
// tests/stubs/OCA/OpenRegister/Service/ObjectService.php). ~38 positional
// `willReturnCallback` closures are written against the stub's shape, so that
// swap breaks them wholesale. Realigning them is a real, separate, mechanical
// change — it is not something to smuggle into the commit that merely turns
// the CI job on.
//
// Integration tests (tests/Integration, reachable via phpunit-unit.xml) DO
// want a live server. Set OPENCONNECTOR_TEST_BOOTSTRAP_NEXTCLOUD=1 for those.
$ncBase        = __DIR__ . '/../../../lib/base.php';
$ncConfig      = __DIR__ . '/../../../config/config.php';
$wantNextcloud = (getenv('OPENCONNECTOR_TEST_BOOTSTRAP_NEXTCLOUD') === '1');
$bootNextcloud = ($wantNextcloud === true
    && defined('OC_CONSOLE') === false
    && is_readable($ncConfig) === true
    && file_exists($ncBase) === true);

// Register the OCP/NCU namespaces from the nextcloud/ocp dev dependency so that
// unit tests can run in a bare environment (no installed Nextcloud server).
// Skipped when we are deliberately booting a real server, whose own public API
// must win: the vendored `nextcloud/ocp` is a DIFFERENT NC release's copy.
// Must come BEFORE the Doctrine/OpenRegister stub registration because the
// ObjectEntity stub extends OCP\AppFramework\Db\Entity.
if ($bootNextcloud === false && $autoloader instanceof \Composer\Autoload\ClassLoader && is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
    if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/NCU') === true) {
        $autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
    }
}

// Register test stubs for Doctrine\DBAL\* (referenced at parse-time by OCP
// QueryBuilder interfaces) and OCA\OpenRegister\* (peer app not in vendor).
//
// Registered unless we are deliberately booting a real server — see the
// $bootNextcloud note above. When core IS booted it supplies the real Doctrine
// and OC classes, so every stand-in here is both unnecessary and, because it is
// loaded by name, actively shadowing.
//
// The per-class `class_exists()` guards below are kept as a second line of
// defence, but they are NOT sufficient on their own: they run before
// `lib/base.php` would be required, so core's classes are not yet loadable and
// every probe answers false.
//
// Important: The Composer ClassLoader caches "missing" class lookups after the
// first failed autoload. Eagerly require_once the stub files to guarantee the
// classes are defined regardless of lookup-cache state.
if ($bootNextcloud === false && $autoloader instanceof \Composer\Autoload\ClassLoader) {
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
        if (interface_exists('OC\\Hooks\\Emitter') === false) {
            require_once $stubsDir . '/OC/Hooks/Emitter.php';
        }

        // flow-workflowengine-integration: OC\AppFramework\Http\Request stub.
        // EndpointService::buildSyntheticRequest() constructs NC's REAL
        // concrete IRequest implementation at runtime (design.md Decision 5);
        // it is a private `\OC\` class, absent from the standalone
        // `nextcloud/ocp` dev-dependency, so EndpointServiceTest needs this
        // stand-in to exercise triggerFromFlow() without a live NC install.
        if (class_exists('OC\\AppFramework\\Http\\Request') === false) {
            require_once $stubsDir . '/OC/AppFramework/Http/Request.php';
        }

        // api-product-gateway: OC\AppFramework\Http stub (STATUS_* constants) —
        // see tests/stubs/OC/AppFramework/Http.php docblock for why this was a
        // pre-existing, previously-unsurfaced gap.
        if (class_exists('OC\\AppFramework\\Http') === false) {
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

        // nextcloud-event-hub: OCP\Calendar\Events\* stubs. Real OCP API but
        // `@since 32.0.0` — newer than the pinned `nextcloud/ocp: dev-stable29`
        // dev dependency, so absent from vendor/nextcloud/ocp. Order matters:
        // the Abstract* parent must load before its children.
        if (class_exists('OCP\\Calendar\\Events\\AbstractCalendarObjectEvent') === false) {
            require_once $stubsDir . '/OCP/Calendar/Events/AbstractCalendarObjectEvent.php';
            require_once $stubsDir . '/OCP/Calendar/Events/CalendarObjectCreatedEvent.php';
            require_once $stubsDir . '/OCP/Calendar/Events/CalendarObjectUpdatedEvent.php';
            require_once $stubsDir . '/OCP/Calendar/Events/CalendarObjectDeletedEvent.php';
        }

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

// Bootstrap Nextcloud only when explicitly asked for (see $bootNextcloud
// above, computed early because it also gates the stub registration). Off by
// default: the OCP/vendor stubs registered above are sufficient for — and are
// the intended environment of — the unit-only suite phpunit.xml declares.
if ($bootNextcloud === true) {
    try {
        require_once $ncBase;
    } catch (\Throwable $e) {
        // NC not fully installed — unit tests continue with vendor stubs only.
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
