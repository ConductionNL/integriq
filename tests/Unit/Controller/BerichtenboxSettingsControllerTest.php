<?php

/**
 * Integriq — the Berichtenbox settings store the certificate encrypted and never answer it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\BerichtenboxSettingsController;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettingsInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-DPA-004.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
class BerichtenboxSettingsControllerTest extends TestCase {
	private const OIN = '00000001234567890000';

	/** @var array<int,array<string,mixed>> */
	private array $saved = [];

	/**
	 * A self-signed certificate and its key, with the OIN as serial number.
	 *
	 * @param string $serial The subject serialNumber.
	 * @param int $days Validity.
	 *
	 * @return array{0:string,1:string}
	 */
	private function certificate(string $serial = self::OIN, int $days = 30): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $keyPem);

		if ($days < 0) {
			// openssl_csr_sign() refuses a negative validity ("Days must be between
			// 0 and ...", returns false), so an already-expired certificate is a
			// fixture: self-signed, same subject shape, valid 2024-01-01 to
			// 2025-01-01, its private key discarded. The resolver refuses expiry
			// before it checks the key, so a fresh key is enough here.
			$pem = (string)file_get_contents(__DIR__ . '/../../fixtures/berichtenbox/expired-sender.crt');

			return [$pem, $keyPem];
		}

		$csr = openssl_csr_new(['commonName' => 'integriq test sender', 'serialNumber' => $serial], $key);
		$x509 = openssl_csr_sign($csr, null, $key, $days);
		openssl_x509_export($x509, $pem);

		return [$pem, $keyPem];
	}//end certificate()

	/**
	 * The controller over doubles, with the request parameters given.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param AesTestCrypto $crypto The crypto.
	 *
	 * @return BerichtenboxSettingsController
	 */
	private function controller(array $params, AesTestCrypto $crypto): BerichtenboxSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$store = $this->getMockBuilder(ConnectionStore::class)->disableOriginalConstructor()->onlyMethods(['findSourceBySlug', 'readSourceRaw'])->getMock();
		$store->method('findSourceBySlug')->willReturn(null);
		$store->method('readSourceRaw')->willReturnArgument(0);

		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getObject')->willReturn($object);
				$entity->method('getUuid')->willReturn('src-1');

				return $entity;
			}
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $p));

		return new BerichtenboxSettingsController(
			$request,
			$store,
			$objectService,
			new BerichtenboxSourceSettings(new MtlsConfigResolver($crypto), $crypto, $l, $this->createMock(IAppConfig::class)),
			$l,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * An upload stores the certificate encrypted, names it by fingerprint, and answers no secret.
	 *
	 * @return void
	 */
	public function testAnUploadedCertificateIsStoredEncryptedAndNeverAnswered(): void {
		[$pem, $key] = $this->certificate();
		$crypto = new AesTestCrypto();

		$response = $this->controller(
			[
				'senderOin' => self::OIN,
				'adapterUrl' => 'http://ebms:8080/service/rest/v19/ebms',
				'wusEndpoint' => 'https://wus.example/BerichtenboxValidatieService',
				'berichtTypes' => ['besluit' => 'BESLUIT'],
				'certificate' => ['certificatePem' => $pem, 'privateKeyPem' => $key],
				'adapterToken' => 's3cret',
			],
			$crypto
		)->setConfig('berichtenbox');

		$this->assertSame(200, $response->getStatus());
		$stored = json_encode($this->saved[0]);
		$this->assertStringNotContainsString('BEGIN CERTIFICATE', (string)$stored);
		$this->assertStringNotContainsString('PRIVATE KEY', (string)$stored);
		$this->assertStringNotContainsString('s3cret', (string)$stored);
		$config = $this->saved[0]['configuration'];
		$this->assertSame('berichtenbox', $config['providerId']);
		$this->assertSame('mtls', $config['authentication']['mode']);
		$this->assertSame($pem, $crypto->decrypt($config['authentication']['mtls']['encryptedCertificate']) . "\n");
		$this->assertStringStartsWith('sha256:', $config['certificateRef']);

		$answer = json_encode($response->getData());
		$this->assertStringNotContainsString('encrypted', (string)$answer);
		$this->assertStringNotContainsString('PRIVATE KEY', (string)$answer);
		$this->assertSame(self::OIN, $response->getData()['source']['certificate']['serialNumber']);
		$this->assertTrue($response->getData()['source']['adapterToken']);
		$this->assertSame([], $response->getData()['warnings']);
	}//end testAnUploadedCertificateIsStoredEncryptedAndNeverAnswered()

	/**
	 * A key that does not belong to the certificate is refused at upload.
	 *
	 * @return void
	 */
	public function testAKeyThatDoesNotBelongToTheCertificateIsRefused(): void {
		[$pem] = $this->certificate();
		[, $otherKey] = $this->certificate();

		$response = $this->controller(['certificate' => ['certificatePem' => $pem, 'privateKeyPem' => $otherKey]], new AesTestCrypto())->setConfig('berichtenbox');

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('certificate', $response->getData()['fieldErrors']);
		$this->assertSame([], $this->saved);
	}//end testAKeyThatDoesNotBelongToTheCertificateIsRefused()

	/**
	 * An expired certificate is refused at upload, as a send would refuse it.
	 *
	 * @return void
	 */
	public function testAnExpiredCertificateIsRefused(): void {
		[$pem, $key] = $this->certificate(days: -1);

		$response = $this->controller(['certificate' => ['certificatePem' => $pem, 'privateKeyPem' => $key]], new AesTestCrypto())->setConfig('berichtenbox');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('expired', $response->getData()['fieldErrors']['certificate']);
		$this->assertSame([], $this->saved);
	}//end testAnExpiredCertificateIsRefused()

	/**
	 * A certificate whose serial number is not the sender OIN is stored with a warning.
	 *
	 * @return void
	 */
	public function testACertificateForAnotherOinIsStoredWithAWarning(): void {
		[$pem, $key] = $this->certificate(serial: '00000009999999999999');

		$response = $this->controller(['senderOin' => self::OIN, 'certificate' => ['certificatePem' => $pem, 'privateKeyPem' => $key]], new AesTestCrypto())->setConfig('berichtenbox');

		$this->assertSame(200, $response->getStatus());
		$this->assertStringContainsString('00000009999999999999', $response->getData()['warnings'][0]);
	}//end testACertificateForAnotherOinIsStoredWithAWarning()

	/**
	 * An OIN that is not 20 digits, a BerichtType over 8 characters and a plain-http WUS endpoint are refused.
	 *
	 * @return void
	 */
	public function testWrongFieldsAreRefused(): void {
		$crypto = new AesTestCrypto();

		$this->assertArrayHasKey('senderOin', $this->controller(['senderOin' => '123'], $crypto)->setConfig('b')->getData()['fieldErrors']);
		$this->assertArrayHasKey('berichtTypes', $this->controller(['berichtTypes' => ['besluit' => 'TOOLONGTYPE']], $crypto)->setConfig('b')->getData()['fieldErrors']);
		$this->assertArrayHasKey('wusEndpoint', $this->controller(['wusEndpoint' => 'http://wus.example/x'], $crypto)->setConfig('b')->getData()['fieldErrors']);
		$this->assertSame([], $this->saved);
	}//end testWrongFieldsAreRefused()

	/**
	 * A source entity double whose raw object and uuid are given.
	 *
	 * @param array<string,mixed> $object The raw object.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object, string $uuid): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($object);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end entity()

	/**
	 * The controller over plain doubles of every collaborator.
	 *
	 * @param ConnectionStoreInterface $store The store.
	 * @param OrObjectService $objectService The object service.
	 * @param BerichtenboxSourceSettingsInterface $settings The settings.
	 * @param LoggerInterface|null $logger The logger.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return BerichtenboxSettingsController
	 */
	private function overDoubles(
		ConnectionStoreInterface $store,
		OrObjectService $objectService,
		BerichtenboxSourceSettingsInterface $settings,
		?LoggerInterface $logger = null,
		array $params = []
	): BerichtenboxSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));

		return new BerichtenboxSettingsController(
			$request,
			$store,
			$objectService,
			$settings,
			$l,
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end overDoubles()

	/**
	 * Reading an unknown source answers 404 with no source and whether sends are live.
	 *
	 * @return void
	 */
	public function testGetConfigForAnUnknownSourceAnswers404(): void {
		$store = $this->createMock(ConnectionStoreInterface::class);
		$store->expects($this->once())->method('findSourceBySlug')->with('nope')->willReturn(null);
		$store->expects($this->never())->method('readSourceRaw');
		$settings = $this->createMock(BerichtenboxSourceSettingsInterface::class);
		$settings->method('live')->willReturn(true);
		$settings->expects($this->never())->method('describe');

		$response = $this->overDoubles($store, $this->createMock(OrObjectService::class), $settings)->getConfig('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['source' => null, 'live' => true], $response->getData());
	}//end testGetConfigForAnUnknownSourceAnswers404()

	/**
	 * Reading a known source describes its raw object, never the stored entity as is.
	 *
	 * @return void
	 */
	public function testGetConfigDescribesTheRawSource(): void {
		$found = $this->entity(['slug' => 'berichtenbox'], 'src-1');
		$raw = $this->entity(['slug' => 'berichtenbox', 'configuration' => ['senderOin' => self::OIN]], 'src-1');
		$store = $this->createMock(ConnectionStoreInterface::class);
		$store->method('findSourceBySlug')->with('berichtenbox')->willReturn($found);
		$store->expects($this->once())->method('readSourceRaw')->with($found)->willReturn($raw);
		$settings = $this->createMock(BerichtenboxSourceSettingsInterface::class);
		$settings->method('live')->willReturn(false);
		$settings->expects($this->once())->method('describe')
			->with(['slug' => 'berichtenbox', 'configuration' => ['senderOin' => self::OIN]])
			->willReturn(['senderOin' => self::OIN]);

		$response = $this->overDoubles($store, $this->createMock(OrObjectService::class), $settings)->getConfig('berichtenbox');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['source' => ['senderOin' => self::OIN], 'live' => false], $response->getData());
	}//end testGetConfigDescribesTheRawSource()

	/**
	 * A slug that is not lower-case letters, digits and hyphens is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnInvalidSlugIsRefused(): void {
		$store = $this->createMock(ConnectionStoreInterface::class);
		$store->expects($this->never())->method('findSourceBySlug');
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->never())->method('saveObject');

		$response = $this->overDoubles($store, $objectService, $this->createMock(BerichtenboxSourceSettingsInterface::class))->setConfig('Bad_Slug');

		$message = 'A source slug is lower-case letters, digits and hyphens.';
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['errors' => [$message], 'fieldErrors' => ['slug' => $message]], $response->getData());
	}//end testAnInvalidSlugIsRefused()

	/**
	 * An existing source is updated in place: its raw object is the base and its uuid is kept.
	 *
	 * @return void
	 */
	public function testAnExistingSourceIsUpdatedUnderItsUuid(): void {
		$existing = ['slug' => 'berichtenbox', 'name' => 'Ours', 'configuration' => ['cpaId' => 'cpa-1']];
		$found = $this->entity(['slug' => 'berichtenbox'], 'src-9');
		$saved = $this->entity(['saved' => true], 'src-9');
		$store = $this->createMock(ConnectionStoreInterface::class);
		$store->method('findSourceBySlug')->willReturn($found);
		$store->method('readSourceRaw')->willReturnMap(
			[
				[$found, $this->entity($existing, 'src-9')],
				[$saved, $this->entity(['stored' => 1], 'src-9')],
			]
		);
		$settings = $this->createMock(BerichtenboxSourceSettingsInterface::class);
		$settings->expects($this->once())->method('apply')
			->with($existing, $this->callback(static fn (array $params) => $params['cpaId'] === 'cpa-2' && $params['certificate'] === null))
			->willReturn(['data' => ['applied' => true], 'warnings' => ['w']]);
		$settings->method('describe')->with(['stored' => 1])->willReturn(['described' => true]);
		$settings->method('live')->willReturn(true);
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->once())->method('saveObject')
			->with(['applied' => true], ConnectionStoreInterface::REGISTER, 'source', 'src-9')
			->willReturn($saved);

		$response = $this->overDoubles($store, $objectService, $settings, null, ['cpaId' => 'cpa-2'])->setConfig('berichtenbox');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['source' => ['described' => true], 'warnings' => ['w'], 'live' => true], $response->getData());
	}//end testAnExistingSourceIsUpdatedUnderItsUuid()

	/**
	 * A save that fails answers 400 with the reason and logs it without the payload.
	 *
	 * @return void
	 */
	public function testAFailedSaveAnswers400AndIsLogged(): void {
		$store = $this->createMock(ConnectionStoreInterface::class);
		$store->method('findSourceBySlug')->willReturn(null);
		$store->expects($this->never())->method('readSourceRaw');
		$settings = $this->createMock(BerichtenboxSourceSettingsInterface::class);
		$settings->method('apply')->willReturn(['data' => ['slug' => 'berichtenbox'], 'warnings' => []]);
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->once())->method('saveObject')
			->with(['slug' => 'berichtenbox'], ConnectionStoreInterface::REGISTER, 'source', null)
			->willThrowException(new RuntimeException('schema missing'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[BerichtenboxSettingsController] the Berichtenbox source was not saved', ['exception' => 'schema missing']);

		$response = $this->overDoubles($store, $objectService, $settings, $logger)->setConfig('berichtenbox');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['errors' => ['The Berichtenbox source was not saved: schema missing']], $response->getData());
	}//end testAFailedSaveAnswers400AndIsLogged()
}//end class
