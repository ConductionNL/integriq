<?php

/**
 * An IEventDispatcher that runs service listeners the way Nextcloud's does.
 *
 * Nextcloud's own dispatcher (OC\EventDispatcher\EventDispatcher) is not in
 * the unit autoload. This one keeps the two properties a listener test leans
 * on: a service listener is resolved by class name when its event fires, and
 * dispatchTyped() routes on the event's class name, so an event built from a
 * string class name reaches the same listener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;

/**
 * Resolves service listeners from a map of class name to instance.
 */
class ServiceListenerDispatcher implements IEventDispatcher {

	/**
	 * Callables per event name.
	 *
	 * @var array<string,list<callable>>
	 */
	private array $listeners = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,IEventListener> $services The listener instances by class name.
	 */
	public function __construct(private readonly array $services) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 */
	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
		$this->listeners[$eventName][] = $listener;

	}//end addListener()

	/**
	 * {@inheritDoc}
	 */
	public function removeListener(string $eventName, callable $listener): void {
		$this->listeners[$eventName] = array_values(
			array_filter(($this->listeners[$eventName] ?? []), static fn (callable $l): bool => $l !== $listener)
		);

	}//end removeListener()

	/**
	 * {@inheritDoc}
	 */
	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
		$this->listeners[$eventName][] = function (Event $event) use ($className): void {
			$this->services[$className]->handle($event);
		};

	}//end addServiceListener()

	/**
	 * {@inheritDoc}
	 */
	public function hasListeners(string $eventName): bool {
		return ($this->listeners[$eventName] ?? []) !== [];

	}//end hasListeners()

	/**
	 * {@inheritDoc}
	 */
	public function dispatch(string $eventName, Event $event): void {
		foreach (($this->listeners[$eventName] ?? []) as $listener) {
			$listener($event);
		}

	}//end dispatch()

	/**
	 * {@inheritDoc}
	 */
	public function dispatchTyped(Event $event): void {
		$this->dispatch(get_class($event), $event);

	}//end dispatchTyped()

}//end class
