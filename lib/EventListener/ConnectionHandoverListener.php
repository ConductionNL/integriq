<?php

/**
 * Integriq Connection Handover Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\Security\ConnectionHandoverService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes over a connection another app hands to integriq, and answers per part.
 *
 * The handover events belong to the apps that send them, so they are named by
 * string and read by shape: the settings come from `getSettings()` (or a public
 * `settings` array), the target source from `getSourceSlug()` (or `sourceSlug`,
 * else the default for that event), and the answer goes back through
 * `setAnswer()`.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 */
class ConnectionHandoverListener implements IEventListener {

	/**
	 * The handover events integriq takes, with the source each fills by default.
	 *
	 * @var array<string, string>
	 */
	public const EVENTS = [
		'OCA\\Pipelinq\\Event\\BrpConnectionHandoverEvent' => 'brp-haalcentraal',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the handover service lazily.
	 * @param LoggerInterface    $logger    Logs a handover that could not run.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a handover event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function handle(Event $event): void {
		$defaultSlug = self::defaultSlugFor(eventClass: get_class($event));
		if ($defaultSlug === null) {
			return;
		}

		$settings = self::read(event: $event, getter: 'getSettings', property: 'settings');
		if (is_array($settings) === false) {
			$this->logger->warning('[integriq] connection handover ignored: the event carries no settings', ['app' => 'integriq']);
			return;
		}

		$slug = self::read(event: $event, getter: 'getSourceSlug', property: 'sourceSlug');
		if (is_string($slug) === false || trim($slug) === '') {
			$slug = $defaultSlug;
		}

		try {
			$answer = $this->container->get(ConnectionHandoverService::class)->takeOver(sourceSlug: trim($slug), settings: $settings);
		} catch (Throwable $e) {
			// Only the class: a message could carry a handed-over value.
			$this->logger->error(
				'[integriq] connection handover failed',
				['app' => 'integriq', 'errorClass' => get_class($e)]
			);
			$answer = array_fill_keys(
				array_keys(array_intersect_key($settings, ConnectionHandoverService::PLAIN_PARTS + ConnectionHandoverService::SECRET_PARTS)),
				ConnectionHandoverService::FAILED
			);
		}

		self::answer(event: $event, answer: $answer);

	}//end handle()

	/**
	 * Read a value from an event through its getter, else its public property.
	 *
	 * @param Event  $event    The event.
	 * @param string $getter   The getter name.
	 * @param string $property The property name.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function read(Event $event, string $getter, string $property): mixed {
		if (method_exists($event, $getter) === true) {
			return $event->$getter();
		}

		$public = get_object_vars($event);

		return ($public[$property] ?? null);

	}//end read()

	/**
	 * Hand the answer back on the event.
	 *
	 * @param Event                 $event  The event.
	 * @param array<string, string> $answer Per part: taken, already-set, conflict or failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function answer(Event $event, array $answer): void {
		if (method_exists($event, 'setAnswer') === true) {
			$event->setAnswer($answer);
		}

	}//end answer()

	/**
	 * The source a handover event fills by default, or null when integriq does not take that event.
	 *
	 * @param string $eventClass The event's class.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function defaultSlugFor(string $eventClass): ?string {
		foreach (self::EVENTS as $handoverEvent => $slug) {
			if ($handoverEvent === $eventClass) {
				return $slug;
			}
		}

		return null;

	}//end defaultSlugFor()
}//end class
