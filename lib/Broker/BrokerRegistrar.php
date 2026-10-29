<?php

/**
 * Integriq BrokerRegistrar.
 *
 * Registers the services of the Broker component.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
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

namespace OCA\Integriq\Broker;

use OCA\Integriq\Broker\Transport\CloudEventsHttpTransport;
use OCA\Integriq\Broker\Transport\KafkaRestTransport;
use OCA\Integriq\Broker\Transport\LogBrokerTransport;
use OCA\Integriq\Broker\Transport\RabbitMqHttpTransport;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Broker component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class BrokerRegistrar {
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
		$this->registerTransportRegistry(context: $context);
	}//end register()

	/**
	 * Registers the broker transport registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerTransportRegistry(IRegistrationContext $context): void {
		// The broker transport registry: one keyed list of the brokers this
		// instance can publish a matched CloudEvent to. Registered explicitly
		// rather than autowired, for the same reason as the intake channel
		// registry below: autowiring would build one with an EMPTY transport
		// list, which fails as "no transport answers to rabbitmq" on the
		// first matched event rather than at boot
		// (openspec/changes/event-broker-transport).
		//
		// `log` is LAST, so a real transport always wins its own id and the
		// dormant one only answers to `log`.
		$context->registerService(
			BrokerTransportRegistry::class,
			static function ($c): BrokerTransportRegistry {
				return new BrokerTransportRegistry(
					logger: $c->get('Psr\Log\LoggerInterface'),
					transports: [
						$c->get(CloudEventsHttpTransport::class),
						$c->get(KafkaRestTransport::class),
						$c->get(RabbitMqHttpTransport::class),
						$c->get(LogBrokerTransport::class),
					]
				);
			}
		);
	}//end registerTransportRegistry()
}
