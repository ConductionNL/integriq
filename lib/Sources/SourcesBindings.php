<?php

/**
 * Integriq SourcesBindings.
 *
 * Registers the services of the Sources component.
 *
 * @category Sources
 * @package  OCA\Integriq\Sources
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

namespace OCA\Integriq\Sources;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient as AdapterPdokGeocodingClient;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\Adapters\Pdok\PdokWmsClient;
use OCA\Integriq\Sources\Pdok\PdokGeocodingClient as SourcePdokGeocodingClient;
use OCA\Integriq\Sources\Pdok\PdokWfsSourceAdapter;
use OCA\Integriq\Sources\Pdok\PdokWmsSourceAdapter;
use OCA\Integriq\Sources\Roster\RosterDeliveryService;
use OCA\Integriq\Sources\Roster\RosterDeliveryServiceInterface;
use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCA\Integriq\Sources\Roster\RosterTargetConfigurationInterface;
use OCA\Integriq\Sources\Swv\DossierHandoffAdapterInterface;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Sources component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 *
 * Naming every class it wires is what a registrar is for, so the coupling
 * between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class SourcesBindings {
	/**
	 * Register this component's services on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's services and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(RosterTargetConfigurationInterface::class, RosterTargetConfiguration::class);
		$context->registerServiceAlias(RosterDeliveryServiceInterface::class, RosterDeliveryService::class);
		$context->registerServiceAlias(DossierHandoffAdapterInterface::class, SwvHandoffSourceAdapter::class);
		$this->registerPdokSourceAdapters(context: $context);
		$this->registerPdokGeocodingSourceAdapter(context: $context);
	}//end register()

	/**
	 * Registers the PDOK WMS and WFS source adapters.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPdokSourceAdapters(IRegistrationContext $context): void {
		$context->registerService(
			PdokWmsSourceAdapter::class,
			static function ($c) {
				return new PdokWmsSourceAdapter(
					config: $c->get('OCP\IAppConfig'),
					logger: $c->get('Psr\Log\LoggerInterface'),
					wmsClient: $c->get(PdokWmsClient::class)
				);
			}
		);
		$context->registerService(
			PdokWfsSourceAdapter::class,
			static function ($c) {
				return new PdokWfsSourceAdapter(
					config: $c->get('OCP\IAppConfig'),
					logger: $c->get('Psr\Log\LoggerInterface'),
					wfsClient: $c->get(PdokWfsClient::class)
				);
			}
		);
	}//end registerPdokSourceAdapters()

	/**
	 * Registers the PDOK geocoding source adapter.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPdokGeocodingSourceAdapter(IRegistrationContext $context): void {
		$context->registerService(
			SourcePdokGeocodingClient::class,
			static function ($c) {
				return new SourcePdokGeocodingClient(
					config: $c->get('OCP\IAppConfig'),
					logger: $c->get('Psr\Log\LoggerInterface'),
					geocodingClient: $c->get(AdapterPdokGeocodingClient::class)
				);
			}
		);
	}//end registerPdokGeocodingSourceAdapter()
}
