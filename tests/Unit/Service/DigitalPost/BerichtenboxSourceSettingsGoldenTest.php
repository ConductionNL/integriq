<?php

/**
 * Characterisation test for BerichtenboxSourceSettings::apply.
 *
 * Pins the stored configuration, the warnings and every refusal. Keys and
 * certificates are generated per run and masked to labels, so the record
 * depends on neither the clock nor the OpenSSL build.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSettingsRefusal;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the settings apply.
 */
class BerichtenboxSourceSettingsGoldenTest extends TestCase {

	/**
	 * PEM text to a stable label.
	 *
	 * @var array<string, string>
	 */
	private array $labels = [];

	/**
	 * Generated PEMs by label.
	 *
	 * @var array<string, string>
	 */
	private array $pems = [];

	/**
	 * Blocks the resolver was asked to resolve.
	 *
	 * @var list<mixed>
	 */
	private array $resolved = [];

	/**
	 * Generate a key and a certificate for it.
	 *
	 * @param string      $label  The label.
	 * @param string|null $serial The subject serial number, or none.
	 *
	 * @return void
	 */
	private function generate(string $label, ?string $serial): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $keyPem);
		$subject = ['commonName' => 'golden ' . $label];
		if ($serial !== null) {
			$subject['serialNumber'] = $serial;
		}

		$x509 = openssl_csr_sign(openssl_csr_new($subject, $key), null, $key, 30);
		openssl_x509_export($x509, $certPem);
		$this->pems[$label . '-cert'] = trim($certPem);
		$this->pems[$label . '-key'] = trim($keyPem);
		$this->labels[trim($certPem)] = $label . '-cert';
		$this->labels[trim($keyPem)] = $label . '-key';
		$this->labels['sha256:' . openssl_x509_fingerprint($certPem, 'sha256')] = 'sha256:<' . $label . '>';
	}//end generate()

	/**
	 * Replace every generated PEM and fingerprint by its label.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function mask(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($entry) => $this->mask(value: $entry), $value);
		}

		if (is_string($value) === true) {
			return strtr($value, $this->labels);
		}

		return $value;
	}//end mask()

	/**
	 * The settings service.
	 *
	 * @return BerichtenboxSourceSettings
	 */
	private function settings(): BerichtenboxSourceSettings {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . $plain . ')');

		$resolver = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			function (array $block): MtlsCertificateBundle {
				$this->resolved[] = $this->mask(value: $block);
				$mtls = $block['mtls'];
				$cert = substr((string)$mtls['encryptedCertificate'], 4, -1);
				$key = substr((string)$mtls['encryptedPrivateKey'], 4, -1);
				if ($cert === 'unresolvable') {
					throw new MtlsConfigurationException(message: 'The certificate has expired.', errorCode: 'expired');
				}

				return new MtlsCertificateBundle(certificatePem: $cert, privateKeyPem: $key, passphrase: null);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new BerichtenboxSourceSettings($resolver, $crypto, $l10n, $this->createMock(IAppConfig::class));
	}//end settings()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$oin = '00000001234567890000';
		$this->generate(label: 'match', serial: $oin);
		$this->generate(label: 'other', serial: '00000009999999990000');
		$this->generate(label: 'bare', serial: null);

		$base = ['senderOin' => ' ' . $oin . ' ', 'adapterUrl' => 'https://adapter.example.test', 'wusEndpoint' => 'https://wus.example.test/check', 'cpaId' => 'cpa-1'];
		$certificate = static fn (string $label, array $extra = []): array => ($extra + ['certificatePem' => ' {' . $label . '-cert} ', 'privateKeyPem' => '{' . $label . '-key}']);
		$cases = [
			'json configuration and token' => [['slug' => 'bb', 'configuration' => '{"authentication":{"mode":"none"},"keep":"yes"}'], $base + ['adapterToken' => 'tok', 'unknown' => 'x']],
			'array configuration, types, empty token' => [['configuration' => ['providerId' => 'old']], ['berichtTypes' => ['a' => ' AB12 ', 'b' => '', 3 => 'X'], 'adapterToken' => '']],
			'no configuration, types not an array' => [[], ['berichtTypes' => 'AB12', 'service' => '  svc  ']],
			'bad oin' => [[], ['senderOin' => '123']],
			'bad adapter url' => [[], ['adapterUrl' => 'not a url']],
			'bad wus url' => [[], ['wusEndpoint' => 'nope']],
			'wus not https' => [[], ['wusEndpoint' => 'http://wus.example.test']],
			'berichttype too long' => [[], ['berichtTypes' => ['a' => 'ABCDEFGHI']]],
			'blank certificate' => [[], $base + ['certificate' => ['certificatePem' => '  ']]],
			'certificate not an array' => [[], $base + ['certificate' => 'pem']],
			'matching certificate' => [['configuration' => ['authentication' => ['encryptedToken' => 'old']]], $base + ['certificate' => $certificate('match', ['passphrase' => ' pw ', 'caBundlePem' => ' ca '])]],
			'other serial' => [[], $base + ['certificate' => $certificate('other'), 'adapterToken' => 'tok']],
			'no serial' => [[], $base + ['certificate' => $certificate('bare')]],
			'no oin' => [[], ['certificate' => $certificate('other')]],
			'key of another certificate' => [[], $base + ['certificate' => ['certificatePem' => '{match-cert}', 'privateKeyPem' => '{other-key}']]],
			'unresolvable' => [[], $base + ['certificate' => ['certificatePem' => 'unresolvable']]],
		];

		$record = [];
		foreach ($cases as $name => [$data, $params]) {
			$params = $this->withPems(value: $params);
			try {
				$record[$name] = $this->mask(value: $this->settings()->apply($data, $params));
			} catch (BerichtenboxSettingsRefusal $refusal) {
				$record[$name] = ['refused' => [$refusal->getField(), $refusal->getMessage()]];
			}
		}

		$record['resolved'] = $this->resolved;

		$path = __DIR__ . '/../../../fixtures/berichtenbox/source-settings-apply.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()

	/**
	 * Replace `{label}` placeholders by the generated PEMs.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function withPems(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($entry) => $this->withPems(value: $entry), $value);
		}

		if (is_string($value) === true) {
			return preg_replace_callback('/\{([a-z]+-(?:cert|key))\}/', fn (array $m): string => $this->pems[$m[1]], $value);
		}

		return $value;
	}//end withPems()
}//end class
