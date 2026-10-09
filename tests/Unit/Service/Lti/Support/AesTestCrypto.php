<?php

/**
 * A real, reversible ICrypto for unit tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti\Support;

use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Implements the real OCP\Security\ICrypto interface with AES-256-CBC plus an
 * HMAC, the same encrypt-then-MAC shape as core's OC\Security\Crypto. It
 * exists so a test can prove that ciphertext hides the plaintext: a fake that
 * only base64-encodes would pass an "is it readable" check by accident.
 */
class AesTestCrypto implements ICrypto {
	/**
	 * Constructor.
	 *
	 * @param string $secret The instance secret this crypto derives its keys from.
	 */
	public function __construct(
		private readonly string $secret = 'integriq-unit-test-secret',
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $message  The message to authenticate.
	 * @param string $password The password; the instance secret when empty.
	 *
	 * @return string
	 */
	public function calculateHMAC(string $message, string $password = ''): string {
		return hash_hmac('sha256', $message, $this->keyFor(password: $password), true);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $plaintext The plaintext.
	 * @param string $password  The password; the instance secret when empty.
	 *
	 * @return string
	 */
	public function encrypt(string $plaintext, string $password = ''): string {
		$iv = random_bytes(16);
		$ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $this->keyFor(password: $password), OPENSSL_RAW_DATA, $iv);
		if ($ciphertext === false) {
			throw new RuntimeException('encryption failed');
		}

		$hmac = bin2hex($this->calculateHMAC(message: $ciphertext . $iv, password: $password));

		return bin2hex($ciphertext) . '|' . bin2hex($iv) . '|' . $hmac . '|3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $authenticatedCiphertext The ciphertext from {@see encrypt()}.
	 * @param string $password                The password; the instance secret when empty.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the HMAC does not match or decryption fails.
	 */
	public function decrypt(string $authenticatedCiphertext, string $password = ''): string {
		$parts = explode('|', $authenticatedCiphertext);
		if (count($parts) !== 4) {
			throw new RuntimeException('authenticated ciphertext could not be decoded');
		}

		$ciphertext = (string)hex2bin($parts[0]);
		$iv = (string)hex2bin($parts[1]);
		$expected = bin2hex($this->calculateHMAC(message: $ciphertext . $iv, password: $password));
		if (hash_equals($expected, $parts[2]) === false) {
			throw new RuntimeException('HMAC does not match');
		}

		$plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $this->keyFor(password: $password), OPENSSL_RAW_DATA, $iv);
		if ($plaintext === false) {
			throw new RuntimeException('decryption failed');
		}

		return $plaintext;
	}

	/**
	 * The key bytes for a password, falling back to the instance secret.
	 *
	 * @param string $password The password, or '' for the instance secret.
	 *
	 * @return string
	 */
	private function keyFor(string $password): string {
		return hash('sha256', ($password === '' ? $this->secret : $password), true);
	}
}
