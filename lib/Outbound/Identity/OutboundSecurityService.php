<?php

/**
 * Integriq OutboundSecurityService.
 *
 * Signs outgoing mail with the identity's key, and encrypts it when a public
 * key is known for the recipient. When no recipient key is known the message
 * is signed and not encrypted, and the record says which of the two happened,
 * so nobody assumes an encryption they did not get.
 *
 * S/MIME runs through openssl, which is present on every Nextcloud host. PGP
 * needs the gnupg extension: where it is absent, this says so rather than
 * quietly signing with nothing.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Throwable;

/**
 * Signs and encrypts outgoing mail, and verifies signed inbound mail.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- openssl is a function API.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class OutboundSecurityService {

	/**
	 * The schema one recipient public key is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'recipient_key';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the recipient keys.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * Protect one message for one recipient.
	 *
	 * @param array<string,mixed> $identity The sending identity, which may carry S/MIME material.
	 * @param string $recipient The recipient address.
	 * @param string $message The MIME message to protect.
	 *
	 * @return array{signed:bool,encrypted:bool,payload:string,detail:string} What happened, and
	 *         the protected payload. `detail` always says why something did not happen.
	 */
	public function protect(array $identity, string $recipient, string $message): array {
		$result = ['signed' => false, 'encrypted' => false, 'payload' => $message, 'detail' => ''];

		if (($identity['signOutgoing'] ?? false) !== true) {
			$result['detail'] = 'This identity does not sign.';
			return $result;
		}

		$certificate = trim((string)($identity['smimeCertificate'] ?? ''));
		$privateKey = trim((string)($identity['smimePrivateKey'] ?? ''));
		if ($certificate === '' || $privateKey === '') {
			$result['detail'] = 'This identity is set to sign but carries no S/MIME certificate and key.';
			return $result;
		}

		$signed = $this->sign(message: $result['payload'], certificate: $certificate, privateKey: $privateKey);
		if ($signed === null) {
			$result['detail'] = 'Signing failed; the message was not sent signed.';
			return $result;
		}

		$result['signed'] = true;
		$result['payload'] = $signed;
		$result['detail'] = 'Signed.';

		$recipientKey = $this->recipientKey(recipient: $recipient);
		if ($recipientKey === null) {
			$result['detail'] = 'Signed, not encrypted: no public key is known for this recipient.';
			return $result;
		}

		$encrypted = $this->encrypt(message: $result['payload'], certificate: $recipientKey);
		if ($encrypted === null) {
			$result['detail'] = 'Signed, not encrypted: the recipient key could not be used.';
			return $result;
		}

		$result['encrypted'] = true;
		$result['payload'] = $encrypted;
		$result['detail'] = 'Signed and encrypted.';

		return $result;

	}//end protect()

	/**
	 * Verify a signed inbound message.
	 *
	 * @param string $message The MIME message as it arrived.
	 *
	 * @return array{verified:bool,detail:string} What the verification found.
	 */
	public function verify(string $message): array {
		if (function_exists('openssl_pkcs7_verify') === false) {
			return ['verified' => false, 'detail' => 'This host cannot verify S/MIME signatures.'];
		}

		$input = $this->tempFile(contents: $message);
		$signers = $this->tempFile(contents: '');
		try {
			$outcome = @openssl_pkcs7_verify($input, PKCS7_NOVERIFY, $signers);
		} catch (Throwable $exception) {
			$this->remove(paths: [$input, $signers]);
			return ['verified' => false, 'detail' => 'Verification failed: ' . $exception->getMessage()];
		}

		$certificates = (string)@file_get_contents($signers);
		$this->remove(paths: [$input, $signers]);

		if ($outcome !== true) {
			return ['verified' => false, 'detail' => 'The signature did not verify.'];
		}

		$detail = 'Signature verified.';
		if ($certificates !== '') {
			$detail = 'Signature verified against the sender certificate.';
		}

		return [
			'verified' => true,
			'detail' => $detail,
		];

	}//end verify()

	/**
	 * The public key known for one recipient, if any.
	 *
	 * @param string $recipient The recipient address.
	 *
	 * @return string|null The PEM certificate, or null.
	 */
	public function recipientKey(string $recipient): ?string {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => self::SCHEMA_KEY,
					'address' => strtolower(trim($recipient)),
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return null;
		}

		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			$key = $row->getObject();
			if (strtolower((string)($key['address'] ?? '')) !== strtolower(trim($recipient))) {
				continue;
			}

			$material = trim((string)($key['publicKey'] ?? ''));
			if ($material !== '') {
				return $material;
			}
		}

		return null;

	}//end recipientKey()

	/**
	 * Sign a message with the identity's S/MIME material.
	 *
	 * @param string $message The message.
	 * @param string $certificate The PEM certificate.
	 * @param string $privateKey The PEM private key.
	 *
	 * @return string|null The signed message, or null when signing failed.
	 */
	private function sign(string $message, string $certificate, string $privateKey): ?string {
		if (function_exists('openssl_pkcs7_sign') === false) {
			return null;
		}

		$input = $this->tempFile(contents: $message);
		$output = $this->tempFile(contents: '');
		try {
			$signed = @openssl_pkcs7_sign($input, $output, $certificate, $privateKey, []);
		} catch (Throwable) {
			$signed = false;
		}

		$payload = null;
		if ($signed === true) {
			$payload = (string)@file_get_contents($output);
		}

		$this->remove(paths: [$input, $output]);

		if ($payload === null || $payload === '') {
			return null;
		}

		return $payload;

	}//end sign()

	/**
	 * Encrypt a message to a recipient's certificate.
	 *
	 * @param string $message The message.
	 * @param string $certificate The recipient's PEM certificate.
	 *
	 * @return string|null The encrypted message, or null when encryption failed.
	 */
	private function encrypt(string $message, string $certificate): ?string {
		if (function_exists('openssl_pkcs7_encrypt') === false) {
			return null;
		}

		$input = $this->tempFile(contents: $message);
		$output = $this->tempFile(contents: '');
		try {
			$encrypted = @openssl_pkcs7_encrypt($input, $output, $certificate, []);
		} catch (Throwable) {
			$encrypted = false;
		}

		$payload = null;
		if ($encrypted === true) {
			$payload = (string)@file_get_contents($output);
		}

		$this->remove(paths: [$input, $output]);

		if ($payload === null || $payload === '') {
			return null;
		}

		return $payload;

	}//end encrypt()

	/**
	 * Write one temporary file.
	 *
	 * @param string $contents What to put in it.
	 *
	 * @return string The path.
	 */
	private function tempFile(string $contents): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'integriq-smime-');
		file_put_contents($path, $contents);

		return $path;

	}//end tempFile()

	/**
	 * Remove temporary files, key material and all.
	 *
	 * @param array<int,string> $paths The paths.
	 *
	 * @return void
	 */
	private function remove(array $paths): void {
		foreach ($paths as $path) {
			if ($path !== '' && file_exists($path) === true) {
				@unlink($path);
			}
		}

	}//end remove()

}//end class
