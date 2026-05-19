<?php

declare(strict_types=1);

namespace OCA\OpenConnector\AppInfo;

use OCA\OpenConnector\EventListener\ObjectCreatedEventListener;
use OCA\OpenConnector\EventListener\ObjectDeletedEventListener;
use OCA\OpenConnector\EventListener\ObjectUpdatedEventListener;
use OCA\OpenConnector\EventListener\ViewDeletedEventListener;
use OCA\OpenConnector\EventListener\ViewUpdatedOrCreatedEventListener; // @todo: remove this temporary listener to the software catalog application
use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCA\OpenConnector\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'openconnector';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Register services
		$context->registerService(SettingsService::class, function($c) {
			return new SettingsService(
				$c->get('OCP\IDBConnection'),
				$c->get('OCP\IAppConfig'),
				$c->get('Psr\Log\LoggerInterface')
			);
		});

		/* @var IEventDispatcher $dispatcher */
		$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: ObjectCreatedEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: ObjectUpdatedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ViewDeletedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ObjectDeletedEventListener::class);
        // @todo: remove this temporary listener to the software catalog application
//        $dispatcher->addServiceListener(eventName: ViewUpdatedOrCreatedEventListener::class, className: ViewUpdatedOrCreatedEventListener::class);

	}

	public function boot(IBootContext $context): void {
	}
}
