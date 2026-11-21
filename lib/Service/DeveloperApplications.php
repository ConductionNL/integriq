<?php

/**
 * Integriq Developer Applications.
 *
 * The applications of the signed-in developer, as the portal key routes reach
 * them: the developer group and the ownership of the application are checked
 * before anything is read or written (access-developer-portal-and-subscriptions
 * D2 and D4), and a refusal is answered as the HTTP status to give, so the
 * controller only words it.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Consumer\ConsumerCredentialList;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IUserSession;
use Throwable;

/**
 * The signed-in developer's own applications and their credentials.
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
class DeveloperApplications {

	/**
	 * Constructor.
	 *
	 * @param IUserSession           $userSession The session.
	 * @param DeveloperPortalAccess  $access      Who a developer is and what they own.
	 * @param ConsumerCredentialList $consumers   Reads and writes an application's credentials list.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly DeveloperPortalAccess $access,
		private readonly ConsumerCredentialList $consumers,
	) {
	}//end __construct()

	/**
	 * The application when the signed-in account is a developer who owns it,
	 * else the status to refuse with: 401 signed out, 403 outside the developer
	 * group, 404 for an application that is not theirs or not there, 503 when
	 * it could not be read.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return ObjectEntity|int The application, or the HTTP status of the refusal.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d2-an-application-is-a-consumer-the-developer-owns
	 */
	public function owned(string $consumerId): ObjectEntity|int {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return Http::STATUS_UNAUTHORIZED;
		}

		if ($this->access->isDeveloper(user: $user) === false) {
			return Http::STATUS_FORBIDDEN;
		}

		try {
			$application = $this->consumers->find(id: $consumerId);
		} catch (Throwable) {
			return Http::STATUS_SERVICE_UNAVAILABLE;
		}

		if ($this->access->owns(user: $user, consumer: $application) === false) {
			return Http::STATUS_NOT_FOUND;
		}

		return $application;
	}//end owned()

	/**
	 * The application's stored credentials, hashes included.
	 *
	 * @param ObjectEntity $application The application.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	public function credentials(ObjectEntity $application): array {
		return $this->consumers->entries(data: $application->getObject());
	}//end credentials()

	/**
	 * Add a credential to the application and save it.
	 *
	 * @param ObjectEntity         $application The application.
	 * @param array<string, mixed> $entry       The new credential.
	 *
	 * @return bool Whether the save landed.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	public function addCredential(ObjectEntity $application, array $entry): bool {
		return $this->consumers->add(consumer: $application, entry: $entry);
	}//end addCredential()

	/**
	 * Revoke one API key of the application.
	 *
	 * Only an API key: a client certificate is pinned by an administrator, so
	 * the portal does not remove one.
	 *
	 * @param ObjectEntity $application  The application.
	 * @param string       $credentialId The key's id.
	 * @param string       $apiKeyType   The credential type of an API key.
	 *
	 * @return bool|null True when revoked, false when the save failed, null when the application holds no such key.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	public function revokeKey(ObjectEntity $application, string $credentialId, string $apiKeyType): ?bool {
		$isKey = false;
		foreach ($this->credentials(application: $application) as $entry) {
			if (($entry['id'] ?? null) === $credentialId) {
				$isKey = (($entry['type'] ?? null) === $apiKeyType);
			}
		}

		if ($isKey === false) {
			return null;
		}

		return ($this->consumers->remove(consumer: $application, credentialId: $credentialId) === true);
	}//end revokeKey()
}//end class
