<?php

/**
 * Builds SFTP and FTPS adapters on an in-memory file server.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer;

use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\FileTransfer\RemoteFileClientFactory;
use OCA\Integriq\Service\Adapter\DataInfra\FtpsAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\SftpAdapter;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared wiring: the protocol seam is the only double that stands in for a server.
 */
abstract class FileTransferAdapterTestCase extends TestCase {

	/**
	 * The pinned fingerprint the fake server presents by default.
	 *
	 * @var string
	 */
	protected const PIN = 'SHA256:AbcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

	/**
	 * The fake server.
	 *
	 * @var FakeRemoteFileClient
	 */
	protected FakeRemoteFileClient $server;

	/**
	 * Credential references resolved, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected array $resolved = [];

	/**
	 * Hosts the egress guard was asked about.
	 *
	 * @var array<int,string>
	 */
	protected array $egressChecked = [];

	/**
	 * Fresh server per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeRemoteFileClient();
		$this->resolved = [];
		$this->egressChecked = [];
	}//end setUp()

	/**
	 * A pinned, brokered SFTP source.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed> The source.
	 */
	protected function source(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'source-1',
				'type' => 'sftp',
				'location' => 'sftp.example.nl',
				'rootPath' => '/outgoing',
				'hostKeyFingerprint' => self::PIN,
				'configuration' => [
					'authentication' => [
						'type' => 'basic',
						'username' => 'partner',
						'credentialRef' => ['credentialName' => 'partner-sftp'],
					],
				],
			],
			$overrides
		);
	}//end source()

	/**
	 * The adapter on the fake server.
	 *
	 * @param string           $protocol `sftp` or `ftps`.
	 * @param EgressGuard|null $guard    A guard, or one that allows everything.
	 *
	 * @return SftpAdapter|FtpsAdapter The adapter.
	 */
	protected function adapter(string $protocol = 'sftp', ?EgressGuard $guard = null): SftpAdapter|FtpsAdapter {
		$factory = $this->createMock(RemoteFileClientFactory::class);
		$factory->method('create')->willReturnCallback(fn (): RemoteFileClient => $this->server);

		$broker = $this->createMock(BrokeredCallService::class);
		$broker->method('resolveCredentialRef')->willReturnCallback(
			function (array $ref): string {
				$this->resolved[] = $ref;
				return 's3cret';
			}
		);

		if ($guard === null) {
			$guard = $this->createMock(EgressGuard::class);
			$guard->method('assertAllowed')->willReturnCallback(
				function (string $url): void {
					$this->egressChecked[] = $url;
				}
			);
		}

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'iqsftp'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$class = ($protocol === 'ftps' ? FtpsAdapter::class : SftpAdapter::class);

		return new $class(
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$factory,
			$broker,
			$guard,
			$temp,
			$this->createMock(ORObjectService::class),
			$l10n,
		);
	}//end adapter()
}//end class
