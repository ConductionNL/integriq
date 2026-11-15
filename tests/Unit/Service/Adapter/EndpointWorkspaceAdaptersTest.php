<?php

/**
 * Unit tests for the Intune, Jamf Pro and Citrix DaaS adapters against recorded vendor answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\EndpointWorkspace\AbstractWorkspaceAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What each vendor's answer becomes, which request goes through the broker,
 * and that nothing is called without a credential.
 *
 * @covers \OCA\Integriq\Service\Adapter\EndpointWorkspace\AbstractWorkspaceAdapter
 * @covers \OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter
 * @covers \OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter
 * @covers \OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter
 * @uses   \OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider
 */
final class EndpointWorkspaceAdaptersTest extends TestCase {

	/**
	 * Broker requests made, as [credentialId, appId, method, path, headers].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $requests = [];

	public function testIntuneListsManagedDevicesWithOwnerAndLastCheckIn(): void {
		$body = json_encode([
			'@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#deviceManagement/managedDevices',
			'value' => [
				['id' => 'd-1', 'deviceName' => 'LT-0042', 'userPrincipalName' => 'jan.de.vries@gemeente.nl', 'emailAddress' => 'Jan.de.Vries@gemeente.nl', 'lastSyncDateTime' => '2026-11-12T08:15:00Z', 'complianceState' => 'compliant', 'operatingSystem' => 'Windows'],
				['id' => 'd-2', 'deviceName' => 'Kiosk', 'userPrincipalName' => '', 'emailAddress' => null, 'lastSyncDateTime' => '2026-11-01T10:00:00Z', 'complianceState' => 'noncompliant'],
			],
		]);
		$adapter = $this->adapter(class: IntuneAdapter::class, status: 200, body: $body);

		$entries = $adapter->listEntries();

		$this->assertSame(
			[
				['kind' => 'device', 'id' => 'd-1', 'name' => 'LT-0042', 'remoteUser' => 'jan.de.vries@gemeente.nl', 'email' => 'Jan.de.Vries@gemeente.nl', 'lastSeen' => '2026-11-12T08:15:00Z', 'state' => 'compliant'],
				['kind' => 'device', 'id' => 'd-2', 'name' => 'Kiosk', 'remoteUser' => null, 'email' => null, 'lastSeen' => '2026-11-01T10:00:00Z', 'state' => 'noncompliant'],
			],
			$entries
		);
		$this->assertSame(
			[['cred-intune', 'openconnector', 'GET', '/v1.0/deviceManagement/managedDevices?$select=id,deviceName,userPrincipalName,emailAddress,lastSyncDateTime,complianceState,operatingSystem', ['Accept' => 'application/json']]],
			$this->requests
		);
		$this->assertSame(['device-inventory', 'device-compliance', 'user-mapping'], $adapter->getCapabilities());
		$this->assertSame(['intune', 'Microsoft Intune', 'device'], [$adapter->getId(), $adapter->getLabel(), $adapter->entryKind()]);
	}

	public function testJamfProListsComputersWithTheirAssignedUser(): void {
		$body = json_encode([
			'totalCount' => 2,
			'results' => [
				['id' => '17', 'general' => ['name' => 'MacBook-Lisa', 'lastContactTime' => '2026-11-12T14:00:00.000Z'], 'userAndLocation' => ['username' => 'lisa', 'email' => 'lisa@gemeente.nl']],
				['id' => 18, 'general' => ['name' => 'iMac-balie'], 'userAndLocation' => null],
			],
		]);
		$adapter = $this->adapter(class: JamfProAdapter::class, status: 200, body: $body);

		$this->assertSame(
			[
				['kind' => 'device', 'id' => '17', 'name' => 'MacBook-Lisa', 'remoteUser' => 'lisa', 'email' => 'lisa@gemeente.nl', 'lastSeen' => '2026-11-12T14:00:00.000Z', 'state' => null],
				['kind' => 'device', 'id' => '18', 'name' => 'iMac-balie', 'remoteUser' => null, 'email' => null, 'lastSeen' => null, 'state' => null],
			],
			$adapter->listEntries()
		);
		$this->assertSame('/api/v1/computers-inventory?section=GENERAL&section=USER_AND_LOCATION&page=0&page-size=200', $this->requests[0][3]);
		$this->assertSame(['device-inventory', 'user-mapping'], $adapter->getCapabilities());
	}

	public function testCitrixListsSessionsWithTheCustomerAndSiteHeaders(): void {
		$body = json_encode([
			'Items' => [
				['Id' => 's-1', 'SessionState' => 'Active', 'StartTime' => '2026-11-13T07:58:00Z', 'User' => ['PrincipalName' => 'piet@gemeente.nl', 'SamName' => 'GEMEENTE\\piet', 'Mail' => 'piet@gemeente.nl'], 'Machine' => ['Name' => 'GEMEENTE\\VDA-01']],
				['Id' => 's-2', 'SessionState' => 'Disconnected', 'User' => ['SamName' => 'EXTERN\\onbekend'], 'Machine' => 'x'],
			],
			'ContinuationToken' => null,
		]);
		$adapter = $this->adapter(class: CitrixDaasAdapter::class, status: 200, body: $body);

		$entries = $adapter->listEntries(settings: ['customerId' => ' acme ', 'siteId' => 'site-1']);

		$this->assertSame(
			[
				['kind' => 'session', 'id' => 's-1', 'name' => 'GEMEENTE\\VDA-01', 'remoteUser' => 'piet@gemeente.nl', 'email' => 'piet@gemeente.nl', 'lastSeen' => '2026-11-13T07:58:00Z', 'state' => 'Active'],
				['kind' => 'session', 'id' => 's-2', 'name' => null, 'remoteUser' => 'EXTERN\\onbekend', 'email' => null, 'lastSeen' => null, 'state' => 'Disconnected'],
			],
			$entries
		);
		$this->assertSame(['Accept' => 'application/json', 'Citrix-CustomerId' => 'acme', 'Citrix-InstanceId' => 'site-1'], $this->requests[0][4]);
		$this->assertSame(['session-enumerate', 'user-mapping'], $adapter->getCapabilities());
	}

	public function testCitrixWithoutACustomerOrSiteCallsNothing(): void {
		$adapter = $this->adapter(class: CitrixDaasAdapter::class, status: 200, body: '{}');

		$this->assertSame([], $adapter->listEntries(settings: ['customerId' => 'acme']));
		$this->assertSame([], $adapter->listEntries(settings: ['customerId' => 'acme', 'siteId' => '  ']));
		$this->assertSame([], $this->requests);
	}

	public function testWithoutACredentialNothingIsCalled(): void {
		foreach ([IntuneAdapter::class, JamfProAdapter::class, CitrixDaasAdapter::class] as $class) {
			$adapter = $this->adapter(class: $class, status: 200, body: '{"value":[{"id":"x"}]}', credential: '');
			$this->assertNull($adapter->listEntries(settings: ['customerId' => 'a', 'siteId' => 'b']));
			$this->assertSame([], $adapter->list('r', 's', 'o', []));
		}

		$this->assertSame([], $this->requests);
	}

	public function testAFailedOrUnreadableAnswerListsNothing(): void {
		$this->assertSame([], $this->adapter(class: IntuneAdapter::class, status: 401, body: '{"value":[{"id":"x"}]}')->listEntries());
		$this->assertSame([], $this->adapter(class: IntuneAdapter::class, status: 200, body: 'not json')->listEntries());
		$this->assertSame([], $this->adapter(class: IntuneAdapter::class, status: 200, body: '{"value":"x"}')->listEntries());
		$this->assertSame([], $this->adapter(class: JamfProAdapter::class, status: 200, body: '{"results":[1,"x"]}')->listEntries());
		$this->assertSame([], $this->adapter(class: IntuneAdapter::class, status: 200, body: '', throws: true)->listEntries());
	}

	public function testTheRegistryListingIsTheEntries(): void {
		$adapter = $this->adapter(class: JamfProAdapter::class, status: 200, body: '{"results":[{"id":"1","general":{"name":"a"}}]}');

		$this->assertSame('a', $adapter->list('r', 's', 'o', [])[0]['name']);
	}

	/**
	 * An adapter over a broker that answers with a recorded body.
	 *
	 * @param class-string<AbstractWorkspaceAdapter> $class      The adapter class.
	 * @param int                                    $status     The answer's status.
	 * @param string                                 $body       The answer's body.
	 * @param string                                 $credential The configured credential id, '' for none.
	 * @param bool                                   $throws     Whether the broker refuses upstream.
	 *
	 * @return AbstractWorkspaceAdapter
	 */
	private function adapter(string $class, int $status, string $body, ?string $credential = null, bool $throws = false): AbstractWorkspaceAdapter {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = []) use ($status, $body, $throws): array {
				if ($throws === true) {
					throw new CredentialUpstreamException('upstream down');
				}
				$this->requests[] = [$credentialId, $appId, $method, $path, $headers];
				return ['status' => $status, 'headers' => [], 'body' => $body];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($credential ?? 'cred-' . explode('_credential_id', $key)[0])
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new $class($broker, $appConfig, $this->createMock(LoggerInterface::class), $l10n);
	}
}
