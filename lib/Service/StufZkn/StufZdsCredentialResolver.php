<?php

/**
 * Integriq StUF-ZDS Credential Resolver.
 *
 * Resolves the WS-Security UsernameToken a StUF-ZKN source names for its
 * ZDS case messages. The password is stored encrypted on the source
 * (`configuration.authentication.wsse.encryptedPassword`, OCP ICrypto) and
 * decrypted only at send time; it is never logged or written to an audit row.
 *
 * Ported from dossiq's StufVaultService, which read `vault://` references
 * from app config. In integriq every source credential is encrypted on the
 * source itself, so the reference indirection is gone. Like the original,
 * this class only reads credentials; an administrator writes them through the
 * source form.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Decrypts a source's WS-Security UsernameToken.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
 */
class StufZdsCredentialResolver {

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Nextcloud's crypto service.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
	) {

	}//end __construct()

	/**
	 * The UsernameToken of a source, or null when it names none.
	 *
	 * @param array $sourceConfiguration The source's `configuration`.
	 *
	 * @return array{username:string,password:string}|null
	 *
	 * @throws StufZknProviderException When a user name is set but its password is missing or cannot be decrypted.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
	 */
	public function usernameToken(array $sourceConfiguration): ?array {
		$wsse     = (array)($sourceConfiguration['authentication']['wsse'] ?? []);
		$username = trim((string)($wsse['username'] ?? ''));
		if ($username === '') {
			return null;
		}

		$encrypted = (string)($wsse['encryptedPassword'] ?? '');
		if ($encrypted === '') {
			throw new StufZknProviderException(
				message: 'The source names a WS-Security user but no `authentication.wsse.encryptedPassword`; no plaintext fallback is permitted.'
			);
		}

		try {
			$password = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new StufZknProviderException(message: 'The stored WS-Security password could not be decrypted: ' . $exception->getMessage());
		}

		return ['username' => $username, 'password' => $password];
	}//end usernameToken()
}//end class
