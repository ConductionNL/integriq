<?php

/**
 * Unit tests for EndpointWorkspaceController and WorkspaceUserMatcher.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-endpoint-workspace-adapters/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EndpointWorkspaceController;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\WorkspaceUserMatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Devices with their owner, a session without a user, a source without a
 * credential, and the admin check.
 *
 * @covers \OCA\Integriq\Controller\EndpointWorkspaceController
 * @covers \OCA\Integriq\Service\Adapter\EndpointWorkspace\WorkspaceUserMatcher
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\AbstractWorkspaceAdapter
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter
 * @uses   \OCA\Integriq\Service\Adapter\EndpointWorkspace\SessionHostRef
 * @uses   \OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider
 */
final class EndpointWorkspaceControllerTest extends TestCase {

	/**
	 * Sources by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sources = [];

	/**
	 * The broker's answer body per path prefix.
	 *
	 * @var array<string, string>
	 */
	private array $answers = [];

	/**
	 * Configured credential ids by app config key.
	 *
	 * @var array<string, string>
	 */
	private array $credentials = [];

	/**
	 * Broker calls made, by path.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->credentials = ['intune_credential_id' => 'c-i', 'citrix-daas_credential_id' => 'c-c', 'azure-virtual-desktop_credential_id' => 'c-a'];
		$this->answers = [
			'/v1.0/deviceManagement' => json_encode(['value' => [
				['id' => 'd-1', 'deviceName' => 'LT-0042', 'userPrincipalName' => 'Jan@Gemeente.nl', 'emailAddress' => 'Jan@Gemeente.nl', 'lastSyncDateTime' => '2026-11-12T08:15:00Z', 'complianceState' => 'compliant'],
			]]),
			'/cvad/manage/Sessions' => json_encode(['Items' => [
				['Id' => 's-1', 'SessionState' => 'Active', 'User' => ['SamName' => 'EXTERN\\onbekend']],
			]]),
			'/subscriptions/' => json_encode(['value' => [
				['name' => 'hp/sh/1', 'properties' => ['userPrincipalName' => 'jan', 'sessionState' => 'Active', 'createTime' => '2026-11-13T07:00:00Z']],
			]]),
		];
		$this->sources = [
			'src-intune' => ['configuration' => ['workspaceAdapter' => 'intune', 'userMapping' => ['ncUserAttribute' => 'email', 'remoteUserAttribute' => 'upn', 'transformChain' => ['lowercase']]]],
			'src-citrix' => ['configuration' => ['workspaceAdapter' => 'citrix-daas', 'customerId' => 'acme', 'siteId' => 'site-1']],
			'src-jamf' => ['configuration' => ['workspaceAdapter' => 'jamf-pro']],
			'src-avd' => ['configuration' => ['workspaceAdapter' => 'azure-virtual-desktop', 'subscriptionId' => 's', 'resourceGroup' => 'r', 'hostPool' => 'hp', 'sessionHost' => 'sh']],
			'src-api' => ['configuration' => 'x'],
		];
	}

	public function testAnIntuneSourceListsDevicesWithTheUserTheOwnerMapsTo(): void {
		$data = $this->controller()->index('src-intune')->getData();

		$this->assertSame(['intune', 'device', false], [$data['adapter'], $data['kind'], $data['credentialMissing']]);
		$this->assertCount(1, $data['entries']);
		$entry = $data['entries'][0];
		$this->assertSame(['LT-0042', '2026-11-12T08:15:00Z'], [$entry['name'], $entry['lastSeen']]);
		$this->assertSame(['uid' => 'jan', 'displayName' => 'Jan de Vries'], $entry['user']);
		$this->assertTrue($entry['userFound']);
	}

	public function testACitrixSessionForAnUnknownAccountSaysNoUserMatched(): void {
		$data = $this->controller()->index('src-citrix')->getData();

		$this->assertSame('session', $data['kind']);
		$this->assertNull($data['entries'][0]['user']);
		$this->assertFalse($data['entries'][0]['userFound']);
	}

	public function testASourceWithoutACredentialExplainsAndCallsNothing(): void {
		$response = $this->controller()->index('src-jamf');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['credentialMissing']);
		$this->assertSame([], $response->getData()['entries']);
		$this->assertStringContainsString('no credential', $response->getData()['message']);
		$this->assertSame([], $this->calls);

		unset($this->credentials['azure-virtual-desktop_credential_id']);
		$this->assertTrue($this->controller()->index('src-avd')->getData()['credentialMissing']);
		$this->assertSame([], $this->calls);
	}

	public function testAnAzureVirtualDesktopSourceListsItsSessions(): void {
		$entry = $this->controller()->index('src-avd')->getData()['entries'][0];

		$this->assertSame(['session', 'hp/sh/1', 'jan', 'Active'], [$entry['kind'], $entry['id'], $entry['remoteUser'], $entry['state']]);
		$this->assertSame('jan', $entry['user']['uid']);
	}

	public function testASourceThatIsNoWorkspaceManagerOrDoesNotExistIsRefused(): void {
		$controller = $this->controller();

		$this->assertSame(400, $controller->index('src-api')->getStatus());
		$this->assertSame(404, $controller->index('missing')->getStatus());
		$this->assertSame([], $this->calls);
	}

	public function testOnlyAnAdministratorSeesTheEntries(): void {
		$this->assertSame(401, $this->controller(uid: null)->index('src-intune')->getStatus());
		$this->assertSame(403, $this->controller(uid: 'alice', admin: false)->index('src-intune')->getStatus());
		$this->assertSame([], $this->calls);
	}

	public function testTheMatcherNeverGuessesAUser(): void {
		$matcher = new WorkspaceUserMatcher($this->userManager());

		$this->assertNull($matcher->match(entry: ['email' => 'shared@gemeente.nl']));
		$this->assertNull($matcher->match(entry: ['remoteUser' => '  ']));
		$this->assertNull($matcher->match(entry: ['remoteUser' => 'jan'], mapping: ['remoteUserAttribute' => 'objectGuid']));
		$this->assertNull($matcher->match(entry: ['remoteUser' => 'jan'], mapping: ['ncUserAttribute' => 'displayName']));
		$this->assertSame('jan', $matcher->match(entry: ['remoteUser' => 'GEMEENTE\\Jan'], mapping: ['remoteUserAttribute' => 'samAccountName', 'transformChain' => ['strip-netbios', 'lowercase']])['uid']);
		$this->assertSame('jan', $matcher->match(entry: ['remoteUser' => 'jan@elders.nl'], mapping: ['remoteUserAttribute' => 'upn', 'ncUserAttribute' => 'uid', 'transformChain' => ['strip-domain', 'unknown']])['uid']);
	}

	/**
	 * The controller over fake sources, broker and users.
	 *
	 * @param string|null $uid   The session user.
	 * @param bool        $admin Whether that user is an administrator.
	 *
	 * @return EndpointWorkspaceController
	 */
	private function controller(?string $uid = 'admin', bool $admin = true): EndpointWorkspaceController {
		$session = $this->createMock(IUserSession::class);
		$sessionUser = null;
		if ($uid !== null) {
			$sessionUser = $this->createMock(IUser::class);
			$sessionUser->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($sessionUser);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null): ObjectEntity {
				$this->assertSame(['integriq', 'source'], [$register, $schema]);
				if (isset($this->sources[$id]) === false) {
					throw new DoesNotExistException('no');
				}
				$entity = new ObjectEntity();
				$entity->setObject($this->sources[$id]);
				return $entity;
			}
		);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path): array {
				$this->calls[] = $path;
				foreach ($this->answers as $prefix => $body) {
					if (str_starts_with($path, $prefix) === true) {
						return ['status' => 200, 'headers' => [], 'body' => $body];
					}
				}
				return ['status' => 404, 'headers' => [], 'body' => ''];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => ($this->credentials[$key] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new EndpointWorkspaceController(
			'integriq',
			$this->createMock(IRequest::class),
			$session,
			$groups,
			$objects,
			new IntuneAdapter($broker, $appConfig, $logger, $l),
			new JamfProAdapter($broker, $appConfig, $logger, $l),
			new CitrixDaasAdapter($broker, $appConfig, $logger, $l),
			new AzureVirtualDesktopAdapter($broker, $appConfig, $logger, $l),
			new WorkspaceUserMatcher($this->userManager()),
			$l
		);
	}

	/**
	 * Users: jan (jan@gemeente.nl), and two sharing shared@gemeente.nl.
	 *
	 * @return IUserManager
	 */
	private function userManager(): IUserManager {
		$jan = $this->createMock(IUser::class);
		$jan->method('getUID')->willReturn('jan');
		$jan->method('getDisplayName')->willReturn('Jan de Vries');
		$other = $this->createMock(IUser::class);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'jan' ? $jan : null));
		$users->method('getByEmail')->willReturnCallback(
			fn (string $email): array => match ($email) {
				'jan@gemeente.nl' => [$jan],
				'shared@gemeente.nl' => [$jan, $other],
				default => [],
			}
		);

		return $users;
	}
}
