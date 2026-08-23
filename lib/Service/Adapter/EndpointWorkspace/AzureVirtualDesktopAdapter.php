<?php

/**
 * Azure Virtual Desktop endpoint/workspace adapter.
 *
 * Reference adapter proving REQ-EWC-001 / REQ-EWC-002 for the
 * endpoint-workspace-connectors category.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\EndpointWorkspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCP\IL10N;

/**
 * Reference `endpoint-workspace-connectors` adapter: Azure Virtual Desktop
 * (Microsoft's ARM-based VDI/DaaS service).
 *
 * Implements REQ-EWC-002's fixed capability vocabulary:
 *   - `session-enumeration` — {@see listSessions()}, ARM `userSessions` list.
 *   - `user-mapping`        — {@see mapSessionToUser()}, resolves an AVD
 *     session's `userPrincipalName` against OR's identity data.
 *   - `audit-event-ingestion` — {@see ingestAuditEvent()}, accepts an Azure
 *     Monitor Activity Log event shape and normalises it for OR's audit
 *     surface.
 *
 * ARM endpoints called (via {@see brokeredRequest()}, never with a
 * locally-held secret):
 *   `GET /subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.DesktopVirtualization
 *     /hostPools/{hostPool}/sessionHosts/{sessionHost}/userSessions?api-version=2023-09-05`
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
 */
class AzureVirtualDesktopAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * ARM API version pinned for the AVD `userSessions` surface.
	 *
	 * @var string
	 */
	private const ARM_API_VERSION = '2023-09-05';

	/**
	 * Constructor.
	 *
	 * @param \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker OR's credential broker.
	 * @param \OCP\IAppConfig $appConfig App config.
	 * @param \Psr\Log\LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translator for labels.
	 */
	public function __construct(
		\OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker,
		\OCP\IAppConfig $appConfig,
		\Psr\Log\LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function getId(): string {
		return 'azure-virtual-desktop';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function getLabel(): string {
		return $this->l10n->t('Azure Virtual Desktop');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function getIcon(): string {
		return 'Monitor';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function getCapabilities(): array {
		return ['session-enumeration', 'user-mapping', 'audit-event-ingestion'];
	}//end getCapabilities()

	/**
	 * Enumerate active user sessions on a host pool's session hosts.
	 *
	 * @param string $subscriptionId Azure subscription id.
	 * @param string $resourceGroup Resource group name.
	 * @param string $hostPool Host pool name.
	 * @param string $sessionHost Session host name.
	 * @param array<string,mixed> $filters Reserved for future OData `$filter` passthrough.
	 *
	 * @return array<int,array<string,mixed>> Normalised session summaries; empty when
	 *                                        unconfigured or the upstream call fails.
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function listSessions(
		string $subscriptionId,
		string $resourceGroup,
		string $hostPool,
		string $sessionHost,
		array $filters = [],
	): array {
		$path = sprintf(
			'/subscriptions/%s/resourceGroups/%s/providers/Microsoft.DesktopVirtualization'
			. '/hostPools/%s/sessionHosts/%s/userSessions?api-version=%s',
			rawurlencode($subscriptionId),
			rawurlencode($resourceGroup),
			rawurlencode($hostPool),
			rawurlencode($sessionHost),
			self::ARM_API_VERSION
		);

		$response = $this->brokeredRequest(method: 'GET', path: $path);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return [];
		}

		$decoded = json_decode($response['body'], true);
		if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
			return [];
		}

		return array_map(
			static function (array $session): array {
				$properties = ($session['properties'] ?? []);
				return [
					'id' => ($session['name'] ?? null),
					'userPrincipalName' => ($properties['userPrincipalName'] ?? null),
					'sessionState' => ($properties['sessionState'] ?? null),
					'createTime' => ($properties['createTime'] ?? null),
					'activeDirectoryUserName' => ($properties['activeDirectoryUserName'] ?? null),
				];
			},
			$decoded['value']
		);

	}//end listSessions()

	/**
	 * Map an AVD session's `userPrincipalName` to a normalised identity shape.
	 *
	 * @param array<string,mixed> $session A session row from {@see listSessions()}.
	 *
	 * @return array{userPrincipalName: ?string, displayName: ?string} Normalised mapping.
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function mapSessionToUser(array $session): array {
		$upn = ($session['userPrincipalName'] ?? null);

		$displayName = null;
		if (is_string($upn) === true) {
			$localPart = strstr($upn, '@', true);
			if ($localPart === false) {
				$displayName = $upn;
			} else {
				$displayName = $localPart;
			}
		}

		return [
			'userPrincipalName' => $upn,
			'displayName' => $displayName,
		];

	}//end mapSessionToUser()

	/**
	 * Normalise an Azure Monitor Activity Log event into OR's audit shape.
	 *
	 * @param array<string,mixed> $event Raw Azure Monitor Activity Log event.
	 *
	 * @return array<string,mixed> Normalised audit-event row.
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function ingestAuditEvent(array $event): array {
		return [
			'source' => $this->getId(),
			'eventName' => ($event['operationName']['value'] ?? ($event['operationName'] ?? null)),
			'caller' => ($event['caller'] ?? null),
			'timestamp' => ($event['eventTimestamp'] ?? null),
			'level' => ($event['level'] ?? null),
			'raw' => $event,
		];

	}//end ingestAuditEvent()

	/**
	 * {@inheritDoc}
	 *
	 * `register`/`schema`/`objectId` are ignored — AVD sessions are not
	 * scoped to an OR object; the required
	 * `$filters['subscriptionId'|'resourceGroup'|'hostPool'|'sessionHost']`
	 * keys select which host pool to enumerate.
	 *
	 * @param string $register Ignored (instance-scoped adapter).
	 * @param string $schema Ignored (instance-scoped adapter).
	 * @param string $objectId Ignored (instance-scoped adapter).
	 * @param array<string,mixed> $filters `subscriptionId`, `resourceGroup`, `hostPool`, `sessionHost` (all required).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
	 *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if (isset($filters['subscriptionId'], $filters['resourceGroup'], $filters['hostPool'], $filters['sessionHost']) === false) {
			return [];
		}

		return $this->listSessions(
			subscriptionId: (string)$filters['subscriptionId'],
			resourceGroup: (string)$filters['resourceGroup'],
			hostPool: (string)$filters['hostPool'],
			sessionHost: (string)$filters['sessionHost']
		);

	}//end list()
}//end class
