<?php

/**
 * Integriq — a Berichtenbox letter without a result is reported, never given a guessed status.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealth;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * D7 and the certificate expiry warning.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */
class BerichtenboxHealthTest extends TestCase {
	/**
	 * An entity double.
	 *
	 * @param array<string,mixed> $data Its object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn('u');

		return $entity;
	}//end entity()

	/**
	 * Letters waiting more than 24 hours are counted; younger, simulated and finished ones are not; certificates near expiry are named.
	 *
	 * @return void
	 */
	public function testLettersWithoutAResultAndExpiringCertificatesAreReported(): void {
		$crypto = new AesTestCrypto();
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'x'], $key), null, $key, 10);
		openssl_x509_export($x509, $pem);
		openssl_pkey_export($key, $keyPem);

		$now = new DateTimeImmutable('2026-10-08T12:00:00Z');
		$source = $this->entity([
			'slug' => 'berichtenbox',
			'configuration' => [
				'providerId' => 'berichtenbox',
				'authentication' => ['mode' => 'mtls', 'mtls' => ['encryptedCertificate' => $crypto->encrypt($pem), 'encryptedPrivateKey' => $crypto->encrypt($keyPem)]],
			],
		]);
		$letters = [
			$this->entity(['providerId' => 'berichtenbox', 'status' => 'sent', 'simulated' => false, 'created' => '2026-10-07T10:00:00+00:00']),
			$this->entity(['providerId' => 'berichtenbox', 'status' => 'sent', 'simulated' => false, 'created' => '2026-10-08T10:00:00+00:00']),
			$this->entity(['providerId' => 'berichtenbox', 'status' => 'sent', 'simulated' => true, 'created' => '2026-10-01T10:00:00+00:00']),
			$this->entity(['providerId' => 'berichtenbox', 'status' => 'delivered', 'simulated' => false, 'created' => '2026-10-01T10:00:00+00:00']),
			$this->entity(['providerId' => 'postex', 'status' => 'sent', 'simulated' => false, 'created' => '2026-10-01T10:00:00+00:00']),
		];

		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config) => ['results' => ($config['filters']['schema'] === 'source' ? [$source] : $letters)]
		);
		$store = $this->getMockBuilder(ConnectionStore::class)->disableOriginalConstructor()->onlyMethods(['readSourceRaw'])->getMock();
		$store->method('readSourceRaw')->willReturnArgument(0);
		$account = $this->getMockBuilder(DigitalPostAccount::class)->disableOriginalConstructor()->onlyMethods(['describe', 'runOrRefuse'])->getMock();
		$account->method('describe')->willReturn(['state' => 'ok']);
		$account->method('runOrRefuse')->willReturnCallback(static function (string $what, callable $operation): void {
			$operation();
		});

		$findings = (new BerichtenboxHealth($objectService, $store, new MtlsConfigResolver($crypto), $account))->findings($now);

		$this->assertSame(1, $findings['sources']);
		$this->assertSame(1, $findings['waiting']);
		$this->assertSame('expires-soon', $findings['certificates'][0]['problem']);
		$this->assertSame('berichtenbox', $findings['certificates'][0]['slug']);
	}//end testLettersWithoutAResultAndExpiringCertificatesAreReported()
}//end class
