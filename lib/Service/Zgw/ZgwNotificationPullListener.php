<?php

/**
 * Turns an inbound ZGW notification into a pull of the one resource it names.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pulls the main object a notification names through the installed set's pull synchronization.
 *
 * Not a full sync: one GET of one resource, then the same per-item path a full
 * run takes (mapping, the contract keyed by the remote url), so the existing
 * local object is updated in place. No list fetch, no deletion pass, and the
 * synchronization's own record (cursor, page, last run) is left alone.
 *
 * 🔴 THE URL MUST LIE UNDER THE SET'S SOURCE. A notification is an inbound
 * message; the pull is an outbound call carrying the set's credentials. A url
 * on any other host would hand those credentials to whoever sent it.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */
class ZgwNotificationPullListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService          OpenRegister objects, for the pull synchronization and its source.
	 * @param SynchronizationService $syncService Reads the one resource and runs it through the item path.
	 * @param IAppConfig             $appConfig              Holds the set bindings (which sets are installed).
	 * @param LoggerInterface        $logger                 Records what was not pulled, and why.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SynchronizationService $syncService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Pull the resource an inbound notification names, when an installed set owns its kanaal.
	 *
	 * Never throws: the notification was received and its CloudEvent emitted, and
	 * the scheduled full sync catches a resource up when this pull cannot.
	 *
	 * @param array<string, mixed> $notification The ZGW notification body (kanaal, hoofdObject, resource, resourceUrl, actie).
	 *
	 * @return string|null The url that was pulled, or null when nothing was.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function handle(array $notification): ?string {
		$set = $this->installedSetFor(kanaal: (string)($notification['kanaal'] ?? ''));
		$url = $this->mainObjectUrl(notification: $notification);
		if ($set === null || $url === null) {
			return null;
		}

		try {
			$synchronization = $this->objectService->find(id: $set . '-pull', register: 'integriq', schema: 'synchronization');
			if ($synchronization === null) {
				$this->logger->warning('[ZgwNotificationPullListener] The pull synchronization of '.$set.' is missing; '.$url.' was not pulled.');
				return null;
			}

			$synchronization = $synchronization->jsonSerialize();
			if ($this->isUnderSource(url: $url, sourceId: (string)($synchronization['sourceId'] ?? '')) === false) {
				$this->logger->warning(
					'[ZgwNotificationPullListener] Refused to pull '.$url.': it is not under the source of '.$set
					.'. A notification may only name resources on the store the set reads.'
				);
				return null;
			}

			$payload = $this->syncService->getObjectFromSource(synchronization: $synchronization, endpoint: $url);
			$this->syncService->replaySynchronizationItem(synchronization: $synchronization, payload: $payload);
		} catch (Throwable $e) {
			$this->logger->error(
				'[ZgwNotificationPullListener] Pulling '.$url.' for '.$set.' failed; the scheduled sync will catch it up: '.$e->getMessage(),
				['exception' => $e]
			);
			return null;
		}//end try

		return $url;
	}//end handle()

	/**
	 * The installed set that owns a kanaal, or null.
	 *
	 * @param string $kanaal The notification's kanaal.
	 *
	 * @return string|null The set slug.
	 */
	private function installedSetFor(string $kanaal): ?string {
		$set = (ZgwSetCatalogue::KANAAL_SETS[$kanaal] ?? null);
		if ($set === null) {
			return null;
		}

		$bindings = json_decode($this->appConfig->getValueString(Application::APP_ID, ZgwSetInstaller::BINDINGS_KEY, '{}'), true);
		if (is_array($bindings) === false || in_array($set, $bindings, true) === false) {
			return null;
		}

		return $set;
	}//end installedSetFor()

	/**
	 * The main object to pull: hoofdObject, else resourceUrl; none for a destroyed main object.
	 *
	 * @param array<string, mixed> $notification The notification.
	 *
	 * @return string|null The url.
	 */
	private function mainObjectUrl(array $notification): ?string {
		$resourceUrl = trim((string)($notification['resourceUrl'] ?? ''));
		$url         = trim((string)($notification['hoofdObject'] ?? ''));
		if ($url === '') {
			$url = $resourceUrl;
		}

		// The main object itself was destroyed: there is nothing to read, and the
		// scheduled full sync removes it, as it does today.
		if ($url === '' || (($notification['actie'] ?? '') === 'destroy' && $url === $resourceUrl)) {
			return null;
		}

		return $url;
	}//end mainObjectUrl()

	/**
	 * Whether a url lies under the location of the given source.
	 *
	 * @param string $url      The url to pull.
	 * @param string $sourceId The pull synchronization's source (slug or uuid).
	 *
	 * @return bool True when the url is on that source.
	 */
	private function isUnderSource(string $url, string $sourceId): bool {
		$source = $this->objectService->find(id: $sourceId, register: 'integriq', schema: 'source');
		if ($source === null) {
			return false;
		}

		$location = rtrim((string)($source->getObject()['location'] ?? ''), '/');

		return $location !== '' && str_starts_with($url, $location.'/') === true;
	}//end isUnderSource()
}//end class
