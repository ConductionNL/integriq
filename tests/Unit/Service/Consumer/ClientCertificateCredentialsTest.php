<?php

/**
 * Unit tests for client certificate credentials: pinning by fingerprint or
 * by issuing CA and subject pattern, the consumer lookup by certificate, and
 * the bridge's mTLS check that resolves the consumer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use DateTimeImmutable;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\Integriq\Service\Auth\ClientCertificateReader;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Tests\Unit\Service\Auth\ClientCertificateFixtures;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\Integriq\Service\Consumer\ConsumerCredentials
 * @covers \OCA\Integriq\Service\Consumer\IntegriqConsumerSource
 * @covers \OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge
 * @uses   \OCA\Integriq\Service\Auth\ClientCertificate
 * @uses   \OCA\Integriq\Service\Auth\ClientCertificateReader
 * @uses   \OCA\Integriq\Exception\AuthenticationException
 */
final class ClientCertificateCredentialsTest extends TestCase {

	/**
	 * A fixture certificate, parsed.
	 *
	 * @param string $name The fixture name.
	 *
	 * @return ClientCertificate
	 */
	private static function cert(string $name): ClientCertificate {
		$certificate = ClientCertificateReader::parse(pem: ClientCertificateFixtures::pems()[$name]);
		self::assertNotNull($certificate);

		return $certificate;
	}//end cert()

	/**
	 * The credentials rules, over an app config and random source that are never needed for certificates.
	 *
	 * @return ConsumerCredentials
	 */
	private function credentials(): ConsumerCredentials {
		return new ConsumerCredentials($this->createMock(IAppConfig::class), $this->createMock(ISecureRandom::class));
	}//end credentials()

	/**
	 * A fingerprint pin stores the certificate's fingerprint, subject and OIN, and matches that certificate only.
	 *
	 * @return void
	 */
	public function testAFingerprintPinMatchesThatCertificateOnly(): void {
		$credentials = $this->credentials();
		$now = new DateTimeImmutable('2026-11-14T10:00:00+00:00');

		$entry = $credentials->certificateEntry(label: 'Zaaksysteem', certificate: self::cert(name: 'pkio'), now: $now);

		$this->assertSame(ConsumerCredentials::TYPE_CLIENT_CERTIFICATE, $entry['type']);
		$this->assertSame('Zaaksysteem', $entry['label']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $entry['createdAt']);
		$this->assertSame(self::cert(name: 'pkio')->fingerprint, $entry['fingerprint']);
		$this->assertSame(ClientCertificateFixtures::OIN, $entry['oin']);
		$this->assertArrayNotHasKey('keyHash', $entry);

		$list = [['type' => 'apiKey', 'keyHash' => 'x'], $entry];
		$this->assertSame(1, $credentials->matchingCertificate(credentials: $list, certificate: self::cert(name: 'pkio'), now: new DateTimeImmutable()));
		$this->assertNull($credentials->matchingCertificate(credentials: $list, certificate: self::cert(name: 'rogue'), now: new DateTimeImmutable()));
		$this->assertNull($credentials->matchingCertificate(credentials: $list, certificate: self::cert(name: 'plain'), now: new DateTimeImmutable()));
	}//end testAFingerprintPinMatchesThatCertificateOnly()

	/**
	 * An issuer pin matches a certificate that CA signed for a fitting subject,
	 * and refuses the same subject from another CA.
	 *
	 * @return void
	 */
	public function testAnIssuerPinNeedsTheCaSignatureAndTheSubject(): void {
		$credentials = $this->credentials();
		$entry = $credentials->certificateEntry(
			label: 'Gemeente Voorbeeld',
			issuer: self::cert(name: 'ca'),
			subjectPattern: '*/serialNumber=' . ClientCertificateFixtures::OIN . '*'
		);

		$this->assertSame(self::cert(name: 'ca')->fingerprint, $entry['issuerFingerprint']);
		$this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $entry['issuerCertificate']);
		$now = new DateTimeImmutable();
		$this->assertSame(0, $credentials->matchingCertificate(credentials: [$entry], certificate: self::cert(name: 'pkio'), now: $now));
		$this->assertNull($credentials->matchingCertificate(credentials: [$entry], certificate: self::cert(name: 'rogue'), now: $now), 'same subject, other CA');
		$this->assertNull($credentials->matchingCertificate(credentials: [$entry], certificate: self::cert(name: 'plain'), now: $now), 'same CA, other subject');
	}//end testAnIssuerPinNeedsTheCaSignatureAndTheSubject()

	/**
	 * An incomplete pin is no entry; an expired credential or a certificate
	 * outside its validity never matches; a non-list is no list.
	 *
	 * @return void
	 */
	public function testIncompleteExpiredAndInvalid(): void {
		$credentials = $this->credentials();
		$this->assertNull($credentials->certificateEntry(label: 'x'));
		$this->assertNull($credentials->certificateEntry(label: 'x', issuer: self::cert(name: 'ca')));
		$this->assertNull($credentials->certificateEntry(label: 'x', issuer: self::cert(name: 'ca'), subjectPattern: '  '));

		$pkio = self::cert(name: 'pkio');
		$entry = $credentials->certificateEntry(label: 'x', certificate: $pkio, expiresAt: '2026-01-01T00:00:00+00:00');
		$this->assertNull($credentials->matchingCertificate(credentials: [$entry], certificate: $pkio, now: new DateTimeImmutable('2026-06-01T00:00:00+00:00')));
		$live = $credentials->certificateEntry(label: 'x', certificate: $pkio);
		$this->assertNull($credentials->matchingCertificate(credentials: [$live], certificate: $pkio, now: (new DateTimeImmutable())->setTimestamp($pkio->validTo + 1)));
		$this->assertNull($credentials->matchingCertificate(credentials: ['a' => $live], certificate: $pkio, now: new DateTimeImmutable()));
		$this->assertNull($credentials->matchingCertificate(credentials: null, certificate: $pkio, now: new DateTimeImmutable()));
	}//end testIncompleteExpiredAndInvalid()

	/**
	 * A bridge over consumers, the first holding a pin on the PKIoverheid certificate.
	 *
	 * @param ObjectService $objects The object service.
	 *
	 * @return OpenRegisterCredentialBridge
	 */
	private function bridge(ObjectService $objects): OpenRegisterCredentialBridge {
		$authorization = new AuthorizationService(
			userManager: $this->createMock(IUserManager::class),
			userSession: $this->createMock(IUserSession::class),
			consumerMapper: (new ReflectionClass(ConsumerMapper::class))->newInstanceWithoutConstructor(),
			cacheFactory: $this->createMock(ICacheFactory::class),
			groupManager: $this->createMock(IGroupManager::class),
			request: $this->createMock(IRequest::class),
		);

		return new OpenRegisterCredentialBridge(
			authorization: $authorization,
			objectService: $objects,
			userSession: $this->createMock(IUserSession::class),
			credentials: $this->credentials()
		);
	}//end bridge()

	/**
	 * Consumers as OpenRegister returns them: one without credentials, one pinning the PKIoverheid certificate.
	 *
	 * @return array{0: ObjectEntity, 1: ObjectEntity}
	 */
	private function consumers(): array {
		$other = new ObjectEntity();
		$other->setUuid('consumer-0');
		$other->setObject(['id' => 'consumer-0', 'name' => 'ander-systeem']);

		$pinned = new ObjectEntity();
		$pinned->setUuid('consumer-1');
		$pinned->setObject(['id' => 'consumer-1', 'name' => 'zaaksysteem', 'credentials' => [$this->credentials()->certificateEntry(label: 'pkio', certificate: self::cert(name: 'pkio'))]]);

		return [$other, $pinned];
	}//end consumers()

	/**
	 * A pinned certificate passes as its consumer, and the use is recorded on the credential.
	 *
	 * @return void
	 */
	public function testAPinnedCertificatePassesAsItsConsumer(): void {
		[$other, $pinned] = $this->consumers();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [$other, $pinned]]);
		$objects->expects($this->once())->method('find')->with('consumer-1')->willReturn($pinned);
		$saved = null;
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $pinned): ObjectEntity {
				$saved = $object;
				return $pinned;
			}
		);
		$bridge = $this->bridge(objects: $objects);

		$bridge->authorizeClientCertificate(certificate: self::cert(name: 'pkio'), consumers: ['zaaksysteem']);

		$this->assertSame($pinned, $bridge->getResolvedConsumer());
		$this->assertIsString($saved['credentials'][0]['lastUsedAt']);
	}//end testAPinnedCertificatePassesAsItsConsumer()

	/**
	 * No certificate, an unpinned one, and a consumer the endpoint does not allow are refused, resolving nobody.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['results' => $this->consumers()]);
		$objects->method('find')->willReturn(null);
		$bridge = $this->bridge(objects: $objects);

		$messages = [];
		foreach ([[null, []], [self::cert(name: 'rogue'), []], [self::cert(name: 'pkio'), ['ander-systeem']]] as [$certificate, $allowed]) {
			try {
				$bridge->authorizeClientCertificate(certificate: $certificate, consumers: $allowed);
				$messages[] = 'passed';
			} catch (AuthenticationException $exception) {
				$messages[] = $exception->getMessage();
			}

			$this->assertNull($bridge->getResolvedConsumer());
		}

		$this->assertSame(
			[
				'No verified client certificate',
				'The client certificate is not pinned to a consumer',
				'The consumer of this client certificate may not call this endpoint',
			],
			$messages
		);
	}//end testRefusals()

	/**
	 * Allowed consumers match by uuid as well as by name; without the credentials rules nothing is pinned.
	 *
	 * @return void
	 */
	public function testAllowedByUuidAndNoRulesNoPin(): void {
		[$other, $pinned] = $this->consumers();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [$other, $pinned]]);
		$bridge = $this->bridge(objects: $objects);

		$bridge->authorizeClientCertificate(certificate: self::cert(name: 'pkio'), consumers: ['consumer-1']);
		$this->assertSame($pinned, $bridge->getResolvedConsumer());

		$source = new IntegriqConsumerSource(objectService: $objects);
		$this->assertNull($source->findByClientCertificate(certificate: self::cert(name: 'pkio')));
	}//end testAllowedByUuidAndNoRulesNoPin()
}//end class
