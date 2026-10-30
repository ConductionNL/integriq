<?php

/**
 * Integriq ServiceRegistrar.
 *
 * Registers the container bindings the Service component offers to the
 * rest of the app.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see ServiceBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class ServiceRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		(new ServiceBindings())->register(context: $context);
	}//end register()

	/**
	 * Register integriq-side IntegrationProviders with OR's IntegrationRegistry.
	 *
	 * Per OR's pluggable-integration-registry spec (AD-1), apps register their
	 * providers at boot — OR's registry is a shared per-request service so all
	 * apps see the same instance.
	 *
	 * Currently registered:
	 *   - SynchronizationContractProvider — surfaces SyncContract leaves on the
	 *     OR objects they synchronise (GH #824).
	 *   - AzureVirtualDesktopAdapter, SharePointOnlineAdapter, Microsoft365Adapter,
	 *     S3Adapter — one reference adapter per connector-category spec
	 *     (endpoint-workspace, document-cms, saas-productivity, data-infra),
	 *     proving the `AbstractCategoryAdapterProvider` registration pattern
	 *     (openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding).
	 *
	 * Soft-fails if OR's IntegrationRegistry isn't available (e.g. when
	 * integriq is loaded but openregister isn't enabled yet) so boot
	 * doesn't crash on a stale install.
	 *
	 * @param IBootContext $context The app's boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-integrationprovider-boot-time-registration-with-or-integrationregistry-req-002
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function boot(IBootContext $context): void {
		(new ServiceBindings())->boot(context: $context);
	}//end boot()
}//end class
