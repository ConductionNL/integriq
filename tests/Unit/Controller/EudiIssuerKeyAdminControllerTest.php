<?php

/**
 * Unit tests for EudiIssuerKeyAdminController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EudiIssuerKeyAdminController;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the Beheer > Authenticatie EUDI issuer key admin endpoints.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */
class EudiIssuerKeyAdminControllerTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|EudiIssuerKeyService
	 */
	private $keyService;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|EudiCredentialOfferService
	 */
	private $offerService;

	/**
	 * @var EudiIssuerKeyAdminController
	 */
	private EudiIssuerKeyAdminController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->keyService = $this->createMock(EudiIssuerKeyService::class);
		$this->offerService = $this->createMock(EudiCredentialOfferService::class);
		$this->offerService->method('resolveOrganisationId')->willReturn('org-1');

		$this->controller = new EudiIssuerKeyAdminController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->keyService,
			$this->offerService,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * status() resolves the active key for the currently-active organisation.
	 *
	 * @return void
	 */
	public function testStatusResolvesActiveKeyForActiveOrganisation(): void {
		$this->keyService->expects($this->once())
			->method('resolveActiveKey')
			->with('org-1')
			->willReturn(['kid' => 'kid-1', 'publicKeyPem' => 'pem', 'privateKeyPem' => 'should-never-leak']);

		$response = $this->controller->status();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('kid-1', $data['kid']);
		$this->assertArrayNotHasKey('privateKeyPem', $data, 'status() must never leak private key material');

	}//end testStatusResolvesActiveKeyForActiveOrganisation()

	/**
	 * generateKey() delegates to the key service scoped to the active organisation.
	 *
	 * @return void
	 */
	public function testGenerateKeyDelegatesToKeyServiceForActiveOrganisation(): void {
		$this->keyService->expects($this->once())
			->method('generateKey')
			->with('org-1')
			->willReturn(['kid' => 'kid-1', 'publicKeyPem' => 'pem', 'algorithm' => 'ES256']);

		$response = $this->controller->generateKey();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('kid-1', $response->getData()['kid']);

	}//end testGenerateKeyDelegatesToKeyServiceForActiveOrganisation()

	/**
	 * rotateKey() delegates to the key service and surfaces a failure as 400.
	 *
	 * @return void
	 */
	public function testRotateKeyFailureReturns400(): void {
		$this->keyService->method('rotateKey')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller->rotateKey();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testRotateKeyFailureReturns400()
	/**
	 * A controller wired to the given logger, sharing the key and offer mocks.
	 *
	 * @param LoggerInterface $logger The logger to wire in.
	 *
	 * @return EudiIssuerKeyAdminController The controller.
	 */
	private function controllerWithLogger(LoggerInterface $logger): EudiIssuerKeyAdminController {
		return new EudiIssuerKeyAdminController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->keyService,
			$this->offerService,
			$logger
		);

	}//end controllerWithLogger()

	/**
	 * status() answers 500 with a generic error and logs the cause when the
	 * active key cannot be resolved; the exception message is not leaked.
	 *
	 * @return void
	 */
	public function testStatusFailureReturns500AndLogsTheCause(): void {
		$this->keyService->expects($this->once())
			->method('resolveActiveKey')
			->with('org-1')
			->willThrowException(new RuntimeException('store unreachable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'EudiIssuerKeyAdminController: failed to resolve active key',
				['exception' => 'store unreachable']
			);

		$response = $this->controllerWithLogger(logger: $logger)->status();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Unable to resolve the current issuer key'], $response->getData());

	}//end testStatusFailureReturns500AndLogsTheCause()

	/**
	 * generateKey() answers 400 with a generic error and logs the cause when
	 * the key service throws.
	 *
	 * @return void
	 */
	public function testGenerateKeyFailureReturns400AndLogsTheCause(): void {
		$this->keyService->expects($this->once())
			->method('generateKey')
			->with('org-1')
			->willThrowException(new RuntimeException('key already exists'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'EudiIssuerKeyAdminController: key generation failed',
				['exception' => 'key already exists']
			);

		$response = $this->controllerWithLogger(logger: $logger)->generateKey();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Key generation failed'], $response->getData());

	}//end testGenerateKeyFailureReturns400AndLogsTheCause()

	/**
	 * rotateKey() returns the new key entry for the active organisation and
	 * logs nothing on success.
	 *
	 * @return void
	 */
	public function testRotateKeySuccessReturnsTheNewEntry(): void {
		$entry = ['kid' => 'kid-2', 'publicKeyPem' => 'pem-2', 'algorithm' => 'ES256'];
		$this->keyService->expects($this->once())
			->method('rotateKey')
			->with('org-1')
			->willReturn($entry);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$response = $this->controllerWithLogger(logger: $logger)->rotateKey();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($entry, $response->getData());

	}//end testRotateKeySuccessReturnsTheNewEntry()
}//end class
