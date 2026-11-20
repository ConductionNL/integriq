<?php

/**
 * Unit tests for RegistrationFiles (form-submission-registration-targets D3).
 *
 * The consumers, the user manager and the root folder are the doubles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Registration\RegistrationFiles;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrationFiles::class)]
#[UsesClass(RegistrationRefusal::class)]
class RegistrationFilesTest extends TestCase {

	/**
	 * The consumer read double.
	 *
	 * @var DsoConnection&MockObject
	 */
	private DsoConnection&MockObject $consumers;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->consumers = $this->createMock(DsoConnection::class);
		$this->users     = $this->createMock(IUserManager::class);
		$this->root      = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * A consumer double carrying the given data.
	 *
	 * @param array<string, mixed> $data The consumer's data.
	 *
	 * @return ObjectEntity
	 */
	private function consumer(array $data): ObjectEntity {
		$consumer = $this->createMock(ObjectEntity::class);
		$consumer->method('getObject')->willReturn($data);

		return $consumer;
	}//end consumer()

	/**
	 * An account double.
	 *
	 * @param string $uid     The account id.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return IUser
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}//end user()

	/**
	 * The reader.
	 *
	 * @return RegistrationFiles
	 */
	private function files(): RegistrationFiles {
		return new RegistrationFiles($this->consumers, $this->users, $this->root);
	}//end files()

	public function testAFileIsReadFromTheCallingAppsOwnAccount(): void {
		$this->consumers->expects($this->once())->method('findConsumers')->with('submission-registration')->willReturn([
			$this->consumer(['name' => 'other-app', 'userId' => 'svc-other']),
			$this->consumer(['name' => 'Portaliq', 'userId' => 'svc-portaliq']),
		]);
		$this->users->method('get')->with('svc-portaliq')->willReturn($this->user('svc-portaliq'));
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF-1.7');
		$file->method('getName')->willReturn('inzending.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('getFirstNodeById')->with(1234)->willReturn($file);
		$this->root->expects($this->once())->method('getUserFolder')->with('svc-portaliq')->willReturn($folder);

		$read = $this->files()->read(app: 'portaliq', file: ['fileId' => 1234]);

		$this->assertSame(['content' => '%PDF-1.7', 'name' => 'inzending.pdf', 'mimeType' => 'application/pdf'], $read);
	}//end testAFileIsReadFromTheCallingAppsOwnAccount()

	public function testTheHandOversNameIsKeptAndTheAccountIsLookedUpOnce(): void {
		$this->consumers->expects($this->once())->method('findConsumers')->willReturn([$this->consumer(['name' => 'portaliq', 'userId' => 'svc'])]);
		$this->users->expects($this->once())->method('get')->willReturn($this->user('svc'));
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('x');
		$file->method('getName')->willReturn('1235');
		$file->method('getMimetype')->willReturn('image/png');
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($file);
		$this->root->method('getUserFolder')->willReturn($folder);
		$files = $this->files();

		$files->read(app: 'portaliq', file: ['fileId' => 1234]);
		$read = $files->read(app: 'portaliq', file: ['fileId' => 1235, 'name' => 'kaart.png']);

		$this->assertSame('kaart.png', $read['name']);
	}//end testTheHandOversNameIsKeptAndTheAccountIsLookedUpOnce()

	public function testAFileTheAccountCannotSeeIsAnInvalidHandOver(): void {
		$this->consumers->method('findConsumers')->willReturn([$this->consumer(['name' => 'portaliq', 'userId' => 'svc'])]);
		$this->users->method('get')->willReturn($this->user('svc'));
		$missing = $this->createMock(Folder::class);
		$missing->method('getFirstNodeById')->willReturn(null);
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($this->createMock(Folder::class));
		$broken = $this->createMock(Folder::class);
		$broken->method('getFirstNodeById')->willThrowException(new NotFoundException('gone'));
		$this->root->method('getUserFolder')->willReturnOnConsecutiveCalls($missing, $folder, $broken);
		$files = $this->files();

		foreach ([1, 2, 3] as $fileId) {
			try {
				$files->read(app: 'portaliq', file: ['fileId' => $fileId]);
				$this->fail('An unreadable file was read.');
			} catch (RegistrationRefusal $e) {
				$this->assertSame(RegistrationRefusal::INVALID_HANDOVER, $e->getReason());
				$this->assertFalse($e->isRetryable());
				$this->assertSame('File ' . $fileId . ' is not readable as the service account of portaliq.', $e->getMessage());
			}
		}
	}//end testAFileTheAccountCannotSeeIsAnInvalidHandOver()

	/**
	 * The consumer sets that name no single usable account.
	 *
	 * @return array<string, array{0: list<array<string, mixed>>, 1: IUser|null|false, 2: string}>
	 */
	public static function unusableAccounts(): array {
		return [
			'no consumer' => [[], false, '0 submission-registration consumers name portaliq'],
			'two consumers' => [[['name' => 'portaliq', 'userId' => 'a'], ['name' => 'portaliq', 'userId' => 'b']], false, '2 submission-registration consumers name portaliq'],
			'no user id' => [[['name' => 'portaliq']], false, 'does not exist or is disabled'],
			'unknown user' => [[['name' => 'portaliq', 'userId' => 'gone']], null, 'does not exist or is disabled'],
			'disabled user' => [[['name' => 'portaliq', 'userId' => 'off']], true, 'does not exist or is disabled'],
		];
	}//end unusableAccounts()

	/**
	 * Without exactly one consumer naming an enabled account, nothing is read.
	 *
	 * @param list<array<string, mixed>> $consumers The consumers' data.
	 * @param bool|null                  $user      Null: the account is unknown; true: it is disabled; false: never asked.
	 * @param string                     $message   Part of the refusal message.
	 *
	 * @dataProvider unusableAccounts
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusableAccounts')]
	public function testWithoutOneUsableAccountNothingIsRead(array $consumers, ?bool $user, string $message): void {
		$this->consumers->method('findConsumers')->willReturn(array_map(fn (array $data): ObjectEntity => $this->consumer($data), $consumers));
		if ($user === false) {
			$this->users->expects($this->never())->method('get');
		} else {
			$this->users->method('get')->willReturn($user === true ? $this->user('off', false) : null);
		}

		$this->root->expects($this->never())->method('getUserFolder');

		try {
			$this->files()->read(app: 'portaliq', file: ['fileId' => 1234]);
			$this->fail('A file was read without a usable account.');
		} catch (RegistrationRefusal $e) {
			$this->assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			$this->assertFalse($e->isRetryable());
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}//end testWithoutOneUsableAccountNothingIsRead()
}//end class
