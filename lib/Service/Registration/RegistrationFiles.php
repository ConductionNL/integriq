<?php

/**
 * Integriq RegistrationFiles.
 *
 * Reads the files a hand-over names as the calling app's service account.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use OCA\Integriq\Service\Dso\DsoConnection;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Throwable;

/**
 * Reads a hand-over's files (design D3) as the calling app's service account.
 *
 * The account is the `userId` of the one consumer of authorization type
 * `submission-registration` whose `name` is the calling app. A file is read
 * from that account's own files only, so a hand-over cannot name a file the
 * app's account cannot see. Without exactly one such consumer, an enabled
 * account, or a readable file, nothing is read and the hand-over is refused.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class RegistrationFiles {

	/**
	 * The consumer authorization type that names an app's service account.
	 */
	public const AUTHORIZATION_TYPE = 'submission-registration';

	/**
	 * The accounts already resolved, by app.
	 *
	 * @var array<string,IUser>
	 */
	private array $accounts = [];

	/**
	 * Constructor.
	 *
	 * @param DsoConnection $consumers   Reads the consumers raw.
	 * @param IUserManager  $userManager Resolves the consumer's account.
	 * @param IRootFolder   $rootFolder  The account's files.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly DsoConnection $consumers,
		private readonly IUserManager $userManager,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * Read one file of a hand-over as the app's service account.
	 *
	 * @param string              $app  The calling app.
	 * @param array<string,mixed> $file The file reference: `fileId`, optional `name`.
	 *
	 * @return array{content:string,name:string,mimeType:string}
	 *
	 * @throws RegistrationRefusal When the app has no usable account or the file cannot be read as it.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function read(string $app, array $file): array {
		$account = $this->account(app: $app);
		$fileId  = (int)($file['fileId'] ?? 0);

		$node = null;
		try {
			$node = $this->rootFolder->getUserFolder(userId: $account->getUID())->getFirstNodeById(id: $fileId);
		} catch (Throwable) {
			$node = null;
		}

		if ($node instanceof File === false) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::INVALID_HANDOVER,
				retryable: false,
				message: 'File ' . $fileId . ' is not readable as the service account of ' . $app . '.'
			);
		}

		$content = $node->getContent();
		$name    = trim((string)($file['name'] ?? ''));
		if ($name === '') {
			$name = $node->getName();
		}

		return [
			'content' => $content,
			'name' => $name,
			'mimeType' => $node->getMimetype(),
		];
	}//end read()

	/**
	 * The enabled service account of an app.
	 *
	 * @param string $app The calling app.
	 *
	 * @return IUser
	 *
	 * @throws RegistrationRefusal When there is not exactly one consumer for the app or its account is unusable.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function account(string $app): IUser {
		if (isset($this->accounts[$app]) === true) {
			return $this->accounts[$app];
		}

		$userIds = [];
		foreach ($this->consumers->findConsumers(authorizationType: self::AUTHORIZATION_TYPE) as $consumer) {
			$data = $consumer->getObject();
			if (strtolower((string)($data['name'] ?? '')) === strtolower($app)) {
				$userIds[] = (string)($data['userId'] ?? '');
			}
		}

		if (count($userIds) !== 1) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: count($userIds) . ' ' . self::AUTHORIZATION_TYPE . ' consumers name ' . $app . '; exactly one must name its service account.'
			);
		}

		$account = null;
		if ($userIds[0] !== '') {
			$account = $this->userManager->get($userIds[0]);
		}

		if ($account === null || $account->isEnabled() === false) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The service account of ' . $app . ' does not exist or is disabled.'
			);
		}

		$this->accounts[$app] = $account;

		return $account;
	}//end account()
}//end class
