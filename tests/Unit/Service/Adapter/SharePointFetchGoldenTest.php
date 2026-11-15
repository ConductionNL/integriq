<?php

/**
 * Golden tests for SharePointOnlineAdapter::fetchDocument.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every path of a document fetch: the download refusals (nothing stored),
 * no session, a fresh and an existing target, and a refused write.
 */
class SharePointFetchGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * Fetch over the given answer and folder state.
	 *
	 * @param array|null $answer      The broker answer.
	 * @param bool       $withUser    Whether a user is signed in.
	 * @param array      $existing    The paths that exist in the user folder.
	 * @param bool       $refuseWrite Whether writing the file is not permitted.
	 *
	 * @return array|null The fetch result.
	 */
	private function fetch(?array $answer, bool $withUser=true, array $existing=[], bool $refuseWrite=false): ?array {
		$this->seen = [];
		$broker     = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$arguments) use ($answer): array {
				$this->seen[] = ['request', $arguments[2], $arguments[3]];
				if ($answer === null) {
					throw new CredentialUpstreamException('upstream down');
				}

				return $answer;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('cred');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->seen[] = ['warning', $message];
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(
			function (string $path) use ($existing): bool {
				$this->seen[] = ['exists', $path];
				return in_array($path, $existing, true);
			}
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) use ($folder): Folder {
				$this->seen[] = ['newFolder', $path];
				return $folder;
			}
		);
		$old = $this->createMock(Node::class);
		$old->method('delete')->willReturnCallback(
			function (): void {
				$this->seen[] = ['delete'];
			}
		);
		$folder->method('get')->willReturn($old);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content) use ($refuseWrite): File {
				$this->seen[] = ['newFile', $path, $content];
				if ($refuseWrite === true) {
					throw new NotPermittedException('read-only');
				}

				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn('/alice/files/'.$path);
				$file->method('getSize')->willReturn(strlen((string) $content));
				return $file;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->seen[] = ['userFolder', $uid];
				return $folder;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($withUser === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		$adapter = new SharePointOnlineAdapter(credentialBroker: $broker, appConfig: $appConfig, logger: $logger, l10n: $l10n, rootFolder: $root, userSession: $session);
		return $adapter->fetchDocument('site 1', 'item/1', '../reports/Report.pdf');

	}//end fetch()

	/**
	 * Every path.
	 *
	 * @return void
	 */
	public function testEveryPath(): void {
		$request = ['request', 'GET', '/v1.0/sites/site%201/drive/items/item%2F1/content'];
		foreach ([['status' => 199, 'body' => 'x'], ['status' => 300, 'body' => 'x'], ['status' => 404, 'body' => 'x']] as $answer) {
			$this->assertNull($this->fetch($answer));
			$this->assertSame([$request], $this->seen);
		}

		$this->assertNull($this->fetch(null));
		$this->assertSame([$request, ['warning', 'sharepoint-online: brokered request failed — upstream down']], $this->seen);

		$ok = ['status' => 200, 'body' => 'bytes'];
		$this->assertNull($this->fetch($ok, false));
		$this->assertSame([$request], $this->seen);

		$folder = 'OpenConnector SharePoint Documents';
		$this->assertSame(['path' => '/alice/files/'.$folder.'/Report.pdf', 'size' => 5], $this->fetch($ok));
		$this->assertSame(
			[$request, ['userFolder', 'alice'], ['exists', $folder], ['newFolder', $folder], ['exists', $folder.'/Report.pdf'], ['newFile', $folder.'/Report.pdf', 'bytes']],
			$this->seen
		);

		$this->assertSame(['path' => '/alice/files/'.$folder.'/Report.pdf', 'size' => 5], $this->fetch(['status' => 299, 'body' => 'bytes'], true, [$folder, $folder.'/Report.pdf']));
		$this->assertSame(
			[['request', 'GET', '/v1.0/sites/site%201/drive/items/item%2F1/content'], ['userFolder', 'alice'], ['exists', $folder], ['exists', $folder.'/Report.pdf'], ['delete'], ['newFile', $folder.'/Report.pdf', 'bytes']],
			$this->seen
		);

		$this->assertNull($this->fetch($ok, true, [$folder], true));
		$this->assertSame(['warning', 'sharepoint-online: could not persist fetched document — read-only'], end($this->seen));

	}//end testEveryPath()
}//end class
