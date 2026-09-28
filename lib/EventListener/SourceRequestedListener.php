<?php

/**
 * Integriq SourceRequested EventListener.
 *
 * The in-process half of the "give me a Source for this base URL" seam:
 * receives a sibling app's typed {@see SourceRequestedEvent}, finds the Source
 * for that base URL or creates one, and writes the result back onto the event.
 *
 * WHAT IT REFUSES. A URL that is not http or https, that carries a user name,
 * password, path, query or fragment, or whose host this instance does not let
 * it call (Nextcloud's own remote-host rule, which refuses local and private
 * addresses unless `allow_local_remote_servers` is set). The same rule applied
 * to the calls the requesting apps made themselves before they handed them to
 * Integriq, so no request that used to be refused becomes possible.
 *
 * WHO ASKED IS WRITTEN DOWN. A created Source names the requesting app, the
 * purpose and the acting user in its description, and every find or create is
 * logged with the same three.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/source-requested-event/specs/source-requested-event/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\SourceRequestedEvent;
use OCA\Integriq\Service\ConnectionStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\IRemoteHostValidator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds or creates the Source a sibling app asks for.
 *
 * @spec openspec/changes/source-requested-event/specs/source-requested-event/spec.md
 */
class SourceRequestedListener implements IEventListener {

	/**
	 * The prefix of every slug this listener derives.
	 *
	 * @var string
	 */
	public const SLUG_PREFIX = 'url-';

	/**
	 * The schemes a requested Source may use.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMES = ['http', 'https'];

	/**
	 * The longest request timeout a request may set, in seconds.
	 *
	 * @var int
	 */
	private const MAX_TIMEOUT = 120;

	/**
	 * Constructor.
	 *
	 * @param ConnectionStore      $store         Reads and creates Sources as the system.
	 * @param IRemoteHostValidator $hostValidator Nextcloud's rule for which hosts may be called.
	 * @param LoggerInterface      $logger        The audit and failure log.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly IRemoteHostValidator $hostValidator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a Source request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/source-requested-event/specs/source-requested-event/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SourceRequestedEvent) === false) {
			return;
		}

		$parts = $this->baseUrlParts(event: $event);
		if ($parts === null) {
			return;
		}

		[$location, $slug, $host] = $parts;
		$audit = [
			'sourceApp' => $event->getSourceApp(),
			'purpose' => $event->getPurpose(),
			'userId' => ($event->getUserId() ?? 'system'),
			'location' => $location,
			'slug' => $slug,
		];

		try {
			$existing = $this->store->findSourceBySlug(slug: $slug);
			if ($existing !== null) {
				$event->setSource(sourceId: (string)$existing->getUuid(), sourceSlug: $slug, created: false);
				$this->logger->info('Integriq: a requested Source already exists and was returned', $audit);
				return;
			}

			$created = $this->store->createSource(payload: $this->payload(event: $event, location: $location, slug: $slug, host: $host));
		} catch (Throwable $e) {
			$event->refuse(refusal: 'the Source could not be found or created: ' . $e->getMessage());
			$this->logger->error('Integriq: a requested Source could not be found or created', $audit + ['exception' => $e]);
			return;
		}

		$event->setSource(sourceId: (string)$created->getUuid(), sourceSlug: $slug, created: true);
		$this->logger->info('Integriq: a requested Source was created', $audit);
	}//end handle()

	/**
	 * The normalised location, slug and host of the requested base URL, or null after refusing it.
	 *
	 * @param SourceRequestedEvent $event The request.
	 *
	 * @return array{0: string, 1: string, 2: string}|null The location, slug and host.
	 *
	 * @spec openspec/changes/source-requested-event/specs/source-requested-event/spec.md
	 */
	public function baseUrlParts(SourceRequestedEvent $event): ?array {
		$parsed = parse_url(trim($event->getBaseUrl()));
		if (is_array($parsed) === false) {
			$parsed = [];
		}

		$refusal = $this->refusalFor(parsed: $parsed);
		if ($refusal !== null) {
			$event->refuse(refusal: $refusal);
			return null;
		}

		$scheme = strtolower((string)$parsed['scheme']);
		$host = strtolower((string)$parsed['host']);
		$location = $scheme . '://' . $host;
		$slugTail = $scheme . '-' . $host;
		if (isset($parsed['port']) === true) {
			$location .= ':' . (int)$parsed['port'];
			$slugTail .= '-' . (int)$parsed['port'];
		}

		$slug = self::SLUG_PREFIX . trim((string)preg_replace('/[^a-z0-9]+/', '-', $slugTail), '-');

		return [$location, $slug, $host];
	}//end baseUrlParts()

	/**
	 * Why a parsed base URL is refused, or null when it is a bare http(s) base URL on an allowed host.
	 *
	 * @param array<string, mixed> $parsed The `parse_url()` result, or an empty array.
	 *
	 * @return string|null The refusal.
	 */
	private function refusalFor(array $parsed): ?string {
		$scheme = strtolower((string)($parsed['scheme'] ?? ''));
		$host = strtolower((string)($parsed['host'] ?? ''));
		if (in_array($scheme, self::SCHEMES, true) === false || $host === '') {
			return 'the base URL must be an http or https URL with a host';
		}

		$extra = self::extraPartsRefusal(parsed: $parsed);
		if ($extra !== null) {
			return $extra;
		}

		if ($this->hostValidator->isValid($host) === false) {
			return 'this instance does not allow calls to the host ' . $host;
		}

		return null;
	}//end refusalFor()

	/**
	 * Why a base URL carrying more than scheme, host and port is refused, or null when it carries nothing more.
	 *
	 * @param array<string, mixed> $parsed The `parse_url()` result.
	 *
	 * @return string|null The refusal.
	 */
	private static function extraPartsRefusal(array $parsed): ?string {
		if (isset($parsed['user']) === true || isset($parsed['pass']) === true) {
			return 'the base URL may not carry a user name or password; credentials belong on the Source';
		}

		$path = (string)($parsed['path'] ?? '');
		if (($path !== '' && $path !== '/') || isset($parsed['query']) === true || isset($parsed['fragment']) === true) {
			return 'the base URL may not carry a path, query or fragment; those belong on the step';
		}

		return null;
	}//end extraPartsRefusal()

	/**
	 * The Source a request creates: enabled, no credentials, provenance in the description.
	 *
	 * @param SourceRequestedEvent $event    The request.
	 * @param string               $location The normalised base URL.
	 * @param string               $slug     The derived slug.
	 * @param string               $host     The host, used as the name.
	 *
	 * @return array<string, mixed> The Source payload.
	 */
	private function payload(SourceRequestedEvent $event, string $location, string $slug, string $host): array {
		$requestedBy = 'the system';
		if (($event->getUserId() ?? '') !== '') {
			$requestedBy = 'user ' . $event->getUserId();
		}

		$payload = [
			'name' => $host,
			'slug' => $slug,
			'description' => 'Created on request of ' . $event->getSourceApp() . ' (' . $requestedBy . '): '
				. $event->getPurpose() . '. It carries no credentials; add them here if the other side needs them.',
			'type' => 'api',
			'location' => $location,
			'isEnabled' => true,
		];

		$timeout = $event->getTimeoutSeconds();
		if ($timeout !== null && $timeout > 0) {
			$payload['configuration'] = ['timeout' => min($timeout, self::MAX_TIMEOUT)];
		}

		return $payload;
	}//end payload()
}//end class
