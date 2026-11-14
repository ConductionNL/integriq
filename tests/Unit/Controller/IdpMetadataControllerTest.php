<?php

/**
 * The public metadata endpoint: the answers it gives, the one 404 for
 * anything not published, 503 for an unreadable certificate, and its route
 * attributes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Auth\Idp\IdpMetadataService;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Controller\IdpMetadataController;
use OCA\Integriq\Exception\IdpMetadataUnavailableException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * IdpMetadataController over a mocked metadata service.
 */
#[CoversClass(IdpMetadataController::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(IdpMetadataUnavailableException::class)]
class IdpMetadataControllerTest extends TestCase {

	private IdpMetadataService&MockObject $metadata;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->metadata = $this->createMock(IdpMetadataService::class);

	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return IdpMetadataController
	 */
	private function controller(): IdpMetadataController {
		return new IdpMetadataController(appName: 'integriq', request: $this->createMock(IRequest::class), metadata: $this->metadata);

	}//end controller()

	/**
	 * A registration of a kind.
	 *
	 * @param string $kind       The kind.
	 * @param string $clientAuth The OIDC client authentication.
	 *
	 * @return IdpRegistration
	 */
	private function registration(string $kind, string $clientAuth = 'private_key_jwt'): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-1',
			data: ['organisation' => 'org-x', 'provider' => 'digid', 'kind' => $kind, 'enabled' => true, 'clientAuth' => $clientAuth]
		);

	}//end registration()

	/**
	 * A SAML registration answers its metadata XML as SAML metadata.
	 *
	 * @return void
	 */
	public function testASamlRegistrationAnswersItsMetadataXml(): void {
		$this->metadata->method('published')->with('org-x', 'digid')->willReturn($this->registration(kind: IdpRegistration::KIND_SAML));
		$this->metadata->method('samlMetadata')->willReturn('<md:EntityDescriptor/>');

		$response = $this->controller()->metadata(organisation: 'org-x', provider: 'digid');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<md:EntityDescriptor/>', $response->getData());
		// getHeaders() needs a running server; the header the controller set is read directly.
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$this->assertSame(IdpMetadataController::SAML_METADATA_TYPE, $headers['Content-Type']);

	}//end testASamlRegistrationAnswersItsMetadataXml()

	/**
	 * An OIDC registration answers its client document.
	 *
	 * @return void
	 */
	public function testAnOidcRegistrationAnswersItsClientDocument(): void {
		$this->metadata->method('published')->willReturn($this->registration(kind: IdpRegistration::KIND_OIDC));
		$this->metadata->method('oidcClient')->willReturn(['client_id' => 'client-x']);

		$response = $this->controller()->metadata(organisation: 'org-x', provider: 'digid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['client_id' => 'client-x'], $response->getData());

	}//end testAnOidcRegistrationAnswersItsClientDocument()

	/**
	 * Anything not published answers the same throttled 404.
	 *
	 * @return void
	 */
	public function testAnythingNotPublishedAnswersTheSameThrottled404(): void {
		$this->metadata->method('published')->willReturnCallback(
			fn (string $organisation): ?IdpRegistration => ($organisation === 'org-unknown-kind' ? $this->registration(kind: 'ldap') : null)
		);
		$controller = $this->controller();

		$answers = [
			$controller->metadata(organisation: 'org-missing', provider: 'digid'),
			$controller->metadata(organisation: 'org-unknown-kind', provider: 'digid'),
			$controller->jwks(organisation: 'org-missing', provider: 'digid'),
		];

		foreach ($answers as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'Not found'], $response->getData());
			$this->assertTrue($response->isThrottled());
			$this->assertSame(['action' => IdpMetadataController::BRUTE_FORCE_ACTION], $response->getThrottleMetadata());
		}

	}//end testAnythingNotPublishedAnswersTheSameThrottled404()

	/**
	 * An unreadable certificate answers 503 with nothing of the credential.
	 *
	 * @return void
	 */
	public function testAnUnreadableCertificateAnswers503(): void {
		$this->metadata->method('published')->willReturn($this->registration(kind: IdpRegistration::KIND_SAML));
		$this->metadata->method('samlMetadata')->willThrowException(new IdpMetadataUnavailableException(detail: 'reg-1 signing cred-signing'));

		$response = $this->controller()->metadata(organisation: 'org-x', provider: 'digid');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['message' => 'The metadata is temporarily unavailable'], $response->getData());

	}//end testAnUnreadableCertificateAnswers503()

	/**
	 * The JWKS is published only for an OIDC registration that signs its client assertion.
	 *
	 * @return void
	 */
	public function testTheJwksIsOnlyForAPrivateKeyJwtClient(): void {
		$registrations = [
			'saml' => $this->registration(kind: IdpRegistration::KIND_SAML),
			'basic' => $this->registration(kind: IdpRegistration::KIND_OIDC, clientAuth: 'client_secret_basic'),
			'jwt' => $this->registration(kind: IdpRegistration::KIND_OIDC),
		];
		$this->metadata->method('published')->willReturnCallback(static fn (string $organisation): IdpRegistration => $registrations[$organisation]);
		$this->metadata->expects($this->once())->method('jwks')->with($registrations['jwt'])->willReturn(['keys' => [['kty' => 'RSA']]]);
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->jwks(organisation: 'saml', provider: 'digid')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->jwks(organisation: 'basic', provider: 'digid')->getStatus());
		$this->assertSame(['keys' => [['kty' => 'RSA']]], $controller->jwks(organisation: 'jwt', provider: 'digid')->getData());

	}//end testTheJwksIsOnlyForAPrivateKeyJwtClient()

	/**
	 * Both endpoints are public, without CSRF, rate limited and brute-force protected.
	 *
	 * @return void
	 */
	public function testTheEndpointsArePublicAndThrottled(): void {
		foreach (['metadata', 'jwks'] as $method) {
			$attributes = array_map(
				static fn ($attribute): string => $attribute->getName(),
				(new ReflectionMethod(IdpMetadataController::class, $method))->getAttributes()
			);
			$this->assertSame(
				[NoCSRFRequired::class, PublicPage::class, AnonRateLimit::class, BruteForceProtection::class],
				$attributes,
				$method
			);
		}

	}//end testTheEndpointsArePublicAndThrottled()
}//end class
