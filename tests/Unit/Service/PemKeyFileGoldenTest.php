<?php

/**
 * Every PEM-to-JWK conversion reads the key from a private temp file and leaves none behind.
 *
 * Pins, with real keys: the LTI and EUDI public JWKs (key material, kid, alg,
 * use, never a private part), the RS JWK the authentication service builds
 * from a source secret, and an LTI launch token signed with the stored key,
 * and that no temp key file of any of them survives the call, so the shared
 * temp-file handling can move without changing a key or leaking one.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authentication-twig/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the PEM-to-JWK conversions.
 */
final class PemKeyFileGoldenTest extends TestCase {

	/**
	 * Call a private method on an instance built without its constructor.
	 *
	 * @param class-string $class  The class.
	 * @param string       $method The method.
	 * @param array        $args   Its arguments.
	 *
	 * @return mixed
	 */
	private function invoke(string $class, string $method, array $args): mixed {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod($method)->invoke($reflection->newInstanceWithoutConstructor(), ...$args);

	}//end invoke()

	/**
	 * The temp key files currently present for a prefix.
	 *
	 * @param string $prefix The tempnam prefix.
	 *
	 * @return array<int, string>
	 */
	private function leftovers(string $prefix): array {
		return (glob(sys_get_temp_dir() . '/' . $prefix . '*') ?: []);

	}//end leftovers()

	/**
	 * A fresh PEM key pair.
	 *
	 * @param array $options openssl_pkey_new options.
	 *
	 * @return array{0: string, 1: string} The private and public PEM.
	 */
	private function keyPair(array $options): array {
		$key = openssl_pkey_new($options);
		openssl_pkey_export($key, $private);

		return [$private, openssl_pkey_get_details($key)['key']];

	}//end keyPair()

	/**
	 * The LTI public JWK carries the RSA material, kid, alg and use, and no private part.
	 *
	 * @return void
	 */
	public function testTheLtiPublicJwk(): void {
		[$private] = $this->keyPair(options: ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$before = $this->leftovers(prefix: 'oc-lti-key-');

		$jwk = $this->invoke(class: LtiKeyService::class, method: 'derivePublicJwk', args: [$private, 'kid-1', 'RS256']);

		$expected = JWKFactory::createFromKey($private, null, ['kid' => 'kid-1', 'alg' => 'RS256', 'use' => 'sig'])->toPublic()->jsonSerialize();
		$this->assertSame($expected, $jwk);
		$this->assertArrayNotHasKey('d', $jwk);
		$this->assertSame($before, $this->leftovers(prefix: 'oc-lti-key-'));

	}//end testTheLtiPublicJwk()

	/**
	 * The EUDI public JWK carries the EC material, kid, ES256 and use.
	 *
	 * @return void
	 */
	public function testTheEudiPublicJwk(): void {
		[, $public] = $this->keyPair(options: ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
		$before = $this->leftovers(prefix: 'oc-eudi-key-');

		$jwk = $this->invoke(class: EudiIssuerKeyService::class, method: 'derivePublicJwk', args: [$public, 'kid-2']);

		$expected = JWKFactory::createFromKey($public, null, ['kid' => 'kid-2', 'alg' => 'ES256', 'use' => 'sig'])->toPublic()->jsonSerialize();
		$this->assertSame($expected, $jwk);
		$this->assertSame($before, $this->leftovers(prefix: 'oc-eudi-key-'));

	}//end testTheEudiPublicJwk()

	/**
	 * The RS JWK of a source secret is the private key, with only `use`.
	 *
	 * @return void
	 */
	public function testTheSourceRsJwk(): void {
		[$private] = $this->keyPair(options: ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$before = $this->leftovers(prefix: 'oc_privatekey_');

		$jwk = $this->invoke(class: AuthenticationService::class, method: 'getRSJWK', args: [['secret' => base64_encode($private)]]);

		$this->assertSame(JWKFactory::createFromKey($private, null, ['use' => 'sig'])->jsonSerialize(), $jwk->jsonSerialize());
		$this->assertSame($before, $this->leftovers(prefix: 'oc_privatekey_'));

	}//end testTheSourceRsJwk()

	/**
	 * An LTI launch token is signed with the stored key under its kid and algorithm.
	 *
	 * @return void
	 */
	public function testTheLtiLaunchSignature(): void {
		[$private, $public] = $this->keyPair(options: ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$before = $this->leftovers(prefix: 'oc-lti-sign-');

		$token = $this->invoke(
			class: LtiLaunchService::class,
			method: 'signJwt',
			args: [['sub' => 'alice', 'iss' => 'https://platform.example'], ['privateKeySecret' => base64_encode($private), 'algorithm' => 'RS256', 'kid' => 'kid-3']]
		);

		$jws = (new CompactSerializer())->unserialize($token);
		$this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-3'], $jws->getSignature(0)->getProtectedHeader());
		$this->assertSame('{"sub":"alice","iss":"https:\/\/platform.example"}', $jws->getPayload());
		$this->assertTrue((new JWSVerifier(new AlgorithmManager([new RS256()])))->verifyWithKey($jws, JWKFactory::createFromKey($public), 0));
		$this->assertSame($before, $this->leftovers(prefix: 'oc-lti-sign-'));

	}//end testTheLtiLaunchSignature()
}//end class
