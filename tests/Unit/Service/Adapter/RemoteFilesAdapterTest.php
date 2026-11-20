<?php

/**
 * The SFTP and FTPS adapters in the data infrastructure category.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\FtpsAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSessionFactory;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFilesAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\SftpAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers SftpAdapter, FtpsAdapter and their shared base.
 */
#[CoversClass(SftpAdapter::class)]
#[CoversClass(FtpsAdapter::class)]
#[CoversClass(RemoteFilesAdapter::class)]
#[UsesClass(AbstractCategoryAdapterProvider::class)]
#[UsesClass(RemoteFileEntry::class)]
final class RemoteFilesAdapterTest extends TestCase {

	/**
	 * The remote client.
	 *
	 * @var RemoteFileClient&MockObject
	 */
	private RemoteFileClient $client;

	/**
	 * The session factory.
	 *
	 * @var RemoteFileSessionFactory&MockObject
	 */
	private RemoteFileSessionFactory $factory;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client  = $this->createMock(RemoteFileClient::class);
		$this->factory = $this->createMock(RemoteFileSessionFactory::class);
	}//end setUp()

	/**
	 * Build an adapter.
	 *
	 * @param class-string<RemoteFilesAdapter> $class The adapter class.
	 *
	 * @return RemoteFilesAdapter
	 */
	private function adapter(string $class): RemoteFilesAdapter {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new $class(
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			$this->client,
			$this->factory
		);
	}//end adapter()

	/**
	 * Each adapter declares its id, protocol, capabilities and login modes.
	 *
	 * @return void
	 */
	public function testEachAdapterDeclaresItsContract(): void {
		$sftp = $this->adapter(class: SftpAdapter::class);
		$ftps = $this->adapter(class: FtpsAdapter::class);

		$this->assertSame(['data-infra-sftp', 'sftp', ['read', 'write', 'bulk-import'], ['basic', 'ssh-key']], [$sftp->getId(), $sftp->getProtocol(), $sftp->getCapabilities(), $sftp->authRequirements()['authModes']]);
		$this->assertSame(['data-infra-ftps', 'ftps', ['read', 'write', 'bulk-import'], ['basic']], [$ftps->getId(), $ftps->getProtocol(), $ftps->getCapabilities(), $ftps->authRequirements()['authModes']]);
		$this->assertArrayHasKey('hostKeyFingerprint', $sftp->authRequirements()['configSchema']);
		$this->assertSame('query-time', $sftp->getStorageStrategy());
		$this->assertNull($sftp->getRequiredApp());
	}//end testEachAdapterDeclaresItsContract()

	/**
	 * Availability and health follow whether the protocol can be spoken here.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsTheProtocol(): void {
		$this->factory->method('isAvailable')->willReturnMap([['sftp', false], ['ftps', true]]);
		$sftp = $this->adapter(class: SftpAdapter::class);
		$ftps = $this->adapter(class: FtpsAdapter::class);

		$this->assertFalse($sftp->isEnabled());
		$this->assertSame(['status' => 'unavailable', 'authStatus' => 'missing', 'message' => 'SFTP needs the phpseclib 3 library, which is not installed on this instance.'], $sftp->health());
		$this->assertTrue($ftps->isEnabled());
		$this->assertSame('ok', $ftps->health()['status']);
	}//end testAvailabilityFollowsTheProtocol()

	/**
	 * A listing needs a source of the adapter's own protocol.
	 *
	 * @return void
	 */
	public function testAListingNeedsASourceOfItsOwnProtocol(): void {
		$source = ['type' => 'sftp', 'location' => 'sftp.example.nl'];
		$this->client->expects($this->once())->method('listFiles')
			->with($source, 'outgoing', '*.csv')
			->willReturn([new RemoteFileEntry(name: 'a.csv', path: 'outgoing/a.csv', size: 3, modifiedAt: 5)]);

		$sftp = $this->adapter(class: SftpAdapter::class);
		$this->assertSame([], $sftp->list('', '', '', []), 'no source, nothing listed');
		$this->assertSame([], $this->adapter(class: FtpsAdapter::class)->list('', '', '', ['source' => $source]), 'an SFTP source is not the FTPS adapter\'s');
		$this->assertSame(
			[['name' => 'a.csv', 'path' => 'outgoing/a.csv', 'size' => 3, 'modifiedAt' => 5, 'isDirectory' => false]],
			$sftp->list('', '', '', ['source' => $source, 'path' => 'outgoing', 'pattern' => '*.csv'])
		);
	}//end testAListingNeedsASourceOfItsOwnProtocol()
}//end class
