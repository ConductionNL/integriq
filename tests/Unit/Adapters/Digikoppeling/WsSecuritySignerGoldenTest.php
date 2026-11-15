<?php

/**
 * Golden tests for WsSecuritySigner: the exact structure sign() produces, and
 * every way verify() refuses or accepts an envelope.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the signed structure and the verify verdicts.
 */
class WsSecuritySignerGoldenTest extends TestCase {

	private const ENVELOPE = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header><x:h xmlns:x="urn:x">1</x:h></soap:Header><soap:Body><vraag xmlns="http://stuf">payload</vraag></soap:Body></soap:Envelope>';

	private const NO_HEADER = '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><a>b</a></s:Body></s:Envelope>';

	/**
	 * A fresh certificate and key, as PEM.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function keyPair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'golden'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, $certPem);
		openssl_pkey_export($key, $keyPem);

		return [$certPem, $keyPem];

	}//end keyPair()

	/**
	 * The signed XML with the random id, the digest, the signature and the certificate masked.
	 *
	 * @param string $signed The signed envelope.
	 *
	 * @return string
	 */
	private static function masked(string $signed): string {
		$signed = (string)preg_replace('/body-[0-9a-f]{16}/', 'BODY-ID', $signed);
		$signed = (string)preg_replace('#(<ds:DigestValue>)[^<]*(</ds:DigestValue>)#', '$1DIGEST$2', $signed);
		$signed = (string)preg_replace('#(<ds:SignatureValue>)[^<]*(</ds:SignatureValue>)#', '$1SIGNATURE$2', $signed);

		return (string)preg_replace('#(<ds:X509Certificate>)[^<]*(</ds:X509Certificate>)#', '$1CERT$2', $signed);

	}//end masked()

	/**
	 * sign() builds the same structure into an existing header and into a missing one.
	 *
	 * @return void
	 */
	public function testSignedStructureIsUnchanged(): void {
		[$cert, $key] = self::keyPair();
		$signer = new WsSecuritySigner();

		$this->assertSame('ad9b920bd80bb5640e70f5f6a1853452bc57ef8c3ccb180acdd80a27fa440580', hash('sha256', self::masked($signer->sign(self::ENVELOPE, $cert, $key))));
		$this->assertSame('937bac40aaab1b7ae2571af121ec917669a2a07d0de2c120c411b8635060021a', hash('sha256', self::masked($signer->sign(self::NO_HEADER, $cert, $key))));

	}//end testSignedStructureIsUnchanged()

	/**
	 * sign() refuses malformed XML, a missing Body and bad key material, in that order.
	 *
	 * @return void
	 */
	public function testSignRefusals(): void {
		[$cert, $key] = self::keyPair();
		$signer = new WsSecuritySigner();
		$messages = [];
		foreach ([['<oops', $key], ['<e/>', $key], ['<e/>', 'nope'], [self::ENVELOPE, 'nope']] as [$xml, $pem]) {
			try {
				$signer->sign($xml, $cert, $pem);
				$messages[] = 'signed';
			} catch (DigikoppelingException $exception) {
				$messages[] = $exception->getMessage();
			}
		}

		$this->assertSame(
			[
				'Cannot sign: the SOAP envelope is not well-formed XML.',
				'Cannot sign: the SOAP envelope has no Body element.',
				'Cannot sign: the SOAP envelope has no Body element.',
				'Cannot sign: the PKIoverheid private key material is invalid.',
			],
			$messages
		);

	}//end testSignRefusals()

	/**
	 * Each way of presenting an envelope and its verdict.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function presentations(): array {
		return [
			'as signed' => ['as-signed', true],
			'pinned to its own certificate' => ['pin-own', true],
			'malformed' => ['malformed', false],
			'unsigned' => ['unsigned', false],
			'reference to another id' => ['other-id', false],
			'body without id' => ['no-body-id', false],
			'tampered body' => ['tampered', false],
			'no certificate' => ['no-cert', false],
			'empty pinned certificate' => ['pin-empty', false],
			'unreadable certificate' => ['bad-cert', false],
			'signature not base64' => ['bad-base64', false],
			'other signature' => ['other-signature', false],
			'pinned to another certificate' => ['pin-other', false],
		];

	}//end presentations()

	/**
	 * verify() answers each presentation as before.
	 *
	 * @param string $case     The presentation.
	 * @param bool   $expected The verdict.
	 *
	 * @return void
	 */
	#[DataProvider('presentations')]
	public function testVerifyVerdict(string $case, bool $expected): void {
		[$cert, $key] = self::keyPair();
		[$otherCert] = self::keyPair();
		$signer = new WsSecuritySigner();
		$signed = $signer->sign(self::ENVELOPE, $cert, $key);
		$pin = null;
		$xml = $signed;
		switch ($case) {
			case 'pin-own':
				$pin = $cert;
				break;
			case 'malformed':
				$xml = substr($signed, 0, 80);
				break;
			case 'unsigned':
				$xml = self::ENVELOPE;
				break;
			case 'other-id':
				$xml = (string)preg_replace('/URI="#body-[0-9a-f]{16}"/', 'URI="#body-0000000000000000"', $signed);
				break;
			case 'no-body-id':
				$xml = (string)preg_replace('/ wsu:Id="body-[0-9a-f]{16}"/', '', $signed);
				break;
			case 'tampered':
				$xml = str_replace('payload', 'tampered', $signed);
				break;
			case 'no-cert':
				$xml = (string)preg_replace('#<ds:X509Certificate>[^<]*</ds:X509Certificate>#', '<ds:X509Certificate></ds:X509Certificate>', $signed);
				break;
			case 'pin-empty':
				$pin = '';
				break;
			case 'bad-cert':
				$xml = (string)preg_replace('#<ds:X509Certificate>[^<]*</ds:X509Certificate>#', '<ds:X509Certificate>bm90IGEgY2VydA==</ds:X509Certificate>', $signed);
				break;
			case 'bad-base64':
				$xml = (string)preg_replace('#<ds:SignatureValue>[^<]*</ds:SignatureValue>#', '<ds:SignatureValue>!!!</ds:SignatureValue>', $signed);
				break;
			case 'other-signature':
				$xml = (string)preg_replace('#<ds:SignatureValue>[^<]*</ds:SignatureValue>#', '<ds:SignatureValue>' . base64_encode(str_repeat('x', 256)) . '</ds:SignatureValue>', $signed);
				break;
			case 'pin-other':
				$pin = $otherCert;
				break;
		}

		$this->assertSame($expected, $signer->verify($xml, $pin));

	}//end testVerifyVerdict()
}//end class
