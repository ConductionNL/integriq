<?php

/**
 * Beheer > Authenticatie's server side: the registrations with their readiness and
 * metadata address, the flag and Keepiq notes, and the Test action; admin only.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Auth\Idp\IdpBrokerConfigInterface;
use OCA\Integriq\Auth\Idp\IdpMetadataService;
use OCA\Integriq\Auth\Idp\IdpReadiness;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpRegistrationTester;
use OCA\Integriq\Controller\IdpRegistrationController;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
#[CoversClass(IdpRegistrationController::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(IdpReadiness::class)]
class IdpRegistrationControllerTest extends TestCase {

	/** @var IdpRegistrationService&MockObject */
	private IdpRegistrationService $registrations;

	/** @var IdpRegistrationTester&MockObject */
	private IdpRegistrationTester $tester;

	/** @var IdpBrokerConfigInterface&MockObject */
	private IdpBrokerConfigInterface $flag;

	/** @var IAppManager&MockObject */
	private IAppManager $apps;

	protected function setUp(): void {
		parent::setUp();
		$this->registrations = $this->createMock(IdpRegistrationService::class);
		$this->tester = $this->createMock(IdpRegistrationTester::class);
		$this->flag = $this->createMock(IdpBrokerConfigInterface::class);
		$this->apps = $this->createMock(IAppManager::class);
	}

	private function controller(): IdpRegistrationController {
		$metadata = $this->createMock(IdpMetadataService::class);
		$metadata->method('metadataUrl')->willReturnCallback(
			static fn (IdpRegistration $registration): string => 'https://nc.example/apps/integriq/api/idp/metadata/'
				.$registration->organisation().'/'.$registration->provider()
		);

		return new IdpRegistrationController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			registrations: $this->registrations,
			tester: $this->tester,
			metadata: $metadata,
			brokerConfig: $this->flag,
			appManager: $this->apps
		);
	}

	private static function registration(string $id, array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: $id,
			data: $data + [
				'organisation' => 'org-x',
				'provider' => 'digid',
				'kind' => 'saml-sp',
				'enabled' => true,
				'label' => 'DigiD',
				'idpMetadataUrl' => 'https://idp.example/md',
				'credentials' => ['signing' => 'cred-signing'],
			]
		);
	}

	public function testTheListCarriesEachRegistrationsStatusReasonsAndMetadataAddressButNoCredential(): void {
		$this->flag->method('isEnabled')->willReturn(false);
		$this->apps->method('isEnabledForAnyone')->with('keepiq')->willReturn(true);
		$this->registrations->method('all')->willReturn([self::registration('reg-1'), self::registration('reg-2', ['provider' => 'eherkenning', 'kind' => 'oidc-rp', 'label' => ''])]);
		$this->registrations->method('readiness')->willReturnCallback(
			static fn (IdpRegistration $registration): IdpReadiness => $registration->id === 'reg-1'
				? new IdpReadiness(status: IdpReadiness::FLAG_OFF, reasons: ['The identity provider broker is switched off.'])
				: new IdpReadiness(status: IdpReadiness::INCOMPLETE, reasons: ['Missing: issuer.'])
		);

		$data = $this->controller()->index()->getData();

		$this->assertFalse($data['brokerEnabled']);
		$this->assertTrue($data['keepiqAvailable']);
		$this->assertSame(
			[
				'id' => 'reg-1',
				'label' => 'DigiD',
				'organisation' => 'org-x',
				'provider' => 'digid',
				'kind' => 'saml-sp',
				'enabled' => true,
				'status' => 'flag-off',
				'reasons' => ['The identity provider broker is switched off.'],
				'metadataUrl' => 'https://nc.example/apps/integriq/api/idp/metadata/org-x/digid',
			],
			$data['registrations'][0]
		);
		$this->assertSame(['incomplete', ['Missing: issuer.']], [$data['registrations'][1]['status'], $data['registrations'][1]['reasons']]);
		$this->assertStringNotContainsString('cred-signing', json_encode($data));
	}

	public function testTheTestActionRunsTheChecksOfTheNamedRegistration(): void {
		$target = self::registration('reg-2');
		$this->registrations->method('all')->willReturn([self::registration('reg-1'), $target]);
		$checks = [['check' => 'metadata', 'passed' => true, 'reason' => '']];
		$this->tester->expects($this->once())->method('run')->with($this->identicalTo($target))->willReturn($checks);

		$response = $this->controller()->test(id: 'reg-2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'reg-2', 'checks' => $checks], $response->getData());
	}

	public function testAnUnknownRegistrationIsNotFound(): void {
		$this->registrations->method('all')->willReturn([self::registration('reg-1')]);
		$this->tester->expects($this->never())->method('run');

		$this->assertSame(404, $this->controller()->test(id: 'reg-9')->getStatus());
	}

	public function testBothActionsAreForAdministratorsOnly(): void {
		foreach (['index', 'test'] as $action) {
			$attributes = (new ReflectionMethod(IdpRegistrationController::class, $action))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $action);
			$this->assertSame([IntegriqAdmin::class], $attributes[0]->getArguments(), $action);
		}
	}
}
