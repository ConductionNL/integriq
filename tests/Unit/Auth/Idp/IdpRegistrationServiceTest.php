<?php

/**
 * Identity provider registrations: one per organisation and provider, and the
 * one readiness answer the screen shows and the adapter registry acts on.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\IdpBrokerConfigInterface;
use OCA\Integriq\Auth\Idp\IdpReadiness;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpRegistrationConflictException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Integriq\Auth\Idp\IdpRegistrationService
 * @covers \OCA\Integriq\Auth\Idp\IdpRegistration
 * @covers \OCA\Integriq\Auth\Idp\IdpReadiness
 * @covers \OCA\Integriq\Auth\Idp\IdpAdapterRegistry
 * @covers \OCA\Integriq\Exception\IdpRegistrationConflictException
 * @uses \OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter
 * @uses \OCA\Integriq\Exception\IdpSecretRefusedException
 * @uses \OCA\Integriq\Exception\IdpAssertionException
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class IdpRegistrationServiceTest extends TestCase {

	/**
	 * The stored idpRegistration objects.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Every object saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Roles whose secret the seam refuses, by reason.
	 *
	 * @var array<string, string>
	 */
	private array $refused = [];

	/**
	 * Whether Keepiq is enabled.
	 *
	 * @var boolean
	 */
	private bool $keepiq = true;

	/**
	 * Whether the broker flag is on.
	 *
	 * @var boolean
	 */
	private bool $flag = true;

	/**
	 * Every role the seam was asked for.
	 *
	 * @var list<string>
	 */
	private array $read = [];


	/**
	 * A stored registration.
	 *
	 * @param string               $uuid Its uuid.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end object()


	/**
	 * A ready OIDC registration's data, with overrides.
	 *
	 * @param array<string, mixed> $overrides Changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function oidc(array $overrides = []): array {
		return array_merge(
			[
				'organisation' => 'gemeente-x',
				'provider' => 'digid',
				'kind' => 'oidc-rp',
				'enabled' => true,
				'label' => 'DigiD via broker',
				'issuer' => 'https://broker.example/digid',
				'clientId' => 'integriq-x',
				'clientAuth' => 'private_key_jwt',
				'credentials' => ['signing' => 'cred-sign', 'salt' => 'cred-salt'],
			],
			$overrides
		);
	}//end oidc()


	/**
	 * The service over doubles of the store, the seam, the flag and the app manager.
	 *
	 * @return IdpRegistrationService
	 */
	private function service(): IdpRegistrationService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$found = [];
				foreach ($this->stored as $object) {
					$data = $object->getObject();
					foreach (['organisation', 'provider'] as $key) {
						if (($data[$key] ?? null) !== ($config['filters'][$key] ?? null)) {
							continue 2;
						}
					}

					$found[] = $object;
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register = null, mixed $schema = null, mixed $uuid = null): ObjectEntity {
				$this->saved[] = $object;
				return $this->object((string)($uuid ?? 'new-uuid'), $object);
			}
		);

		/** @var IdpSecretSeam&MockObject $seam */
		$seam = $this->createMock(IdpSecretSeam::class);
		$seam->method('read')->willReturnCallback(
			function (string $registration, string $organisation, string $role, string $reference): string {
				$this->read[] = $role;
				if (isset($this->refused[$role]) === true) {
					throw new IdpSecretRefusedException(reason: $this->refused[$role], detail: 'refused');
				}

				return 'secret-bytes';
			}
		);

		$config = $this->createMock(IdpBrokerConfigInterface::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->flag);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => $app === 'keepiq' && $this->keepiq);

		return new IdpRegistrationService($objects, $seam, $config, $apps);
	}//end service()


	/**
	 * Readiness for one registration's data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return IdpReadiness
	 */
	private function readiness(array $data): IdpReadiness {
		return $this->service()->readiness(registration: new IdpRegistration(id: 'reg-1', data: $data));
	}//end readiness()


	/**
	 * Each of the six statuses, with reasons that name properties and roles only.
	 *
	 * @return void
	 */
	public function testEachStatusWithItsReasons(): void {
		$this->assertSame(['status' => 'ready', 'reasons' => []], $this->readiness(self::oidc())->toArray());

		$this->assertSame(
			['status' => 'disabled', 'reasons' => ['The registration is not enabled.']],
			$this->readiness(self::oidc(['enabled' => false]))->toArray()
		);

		$this->assertSame(
			['status' => 'incomplete', 'reasons' => ['Missing: clientId.']],
			$this->readiness(self::oidc(['clientId' => '  ']))->toArray()
		);

		$this->refused = ['signing' => IdpSecretSeam::REASON_SECRET_MISSING];
		$missing = $this->readiness(self::oidc());
		$this->assertSame(['status' => 'secret-missing', 'reasons' => ['The signing credential cannot be read.']], $missing->toArray());
		$this->assertStringNotContainsString('cred-sign', implode(' ', $missing->reasons));

		$this->refused = [];
		$this->flag = false;
		$this->assertSame('flag-off', $this->readiness(self::oidc())->status);

		$this->keepiq = false;
		$this->assertSame(
			['status' => 'keepiq-missing', 'reasons' => ['Keepiq is not installed or not enabled.']],
			$this->readiness(self::oidc())->toArray()
		);
	}//end testEachStatusWithItsReasons()


	/**
	 * Disabled and incomplete are answered before any secret is read.
	 *
	 * @return void
	 */
	public function testNoSecretIsReadForADisabledOrIncompleteRegistration(): void {
		$this->readiness(self::oidc(['enabled' => false]));
		$this->readiness(self::oidc(['issuer' => '']));
		$this->keepiq = false;
		$this->readiness(self::oidc());

		$this->assertSame([], $this->read);
	}//end testNoSecretIsReadForADisabledOrIncompleteRegistration()


	/**
	 * A seam refusal for Keepiq reads as keepiq-missing.
	 *
	 * @return void
	 */
	public function testASeamKeepiqRefusalIsKeepiqMissing(): void {
		$this->refused = ['signing' => IdpSecretSeam::REASON_KEEPIQ_MISSING];

		$this->assertSame('keepiq-missing', $this->readiness(self::oidc())->status);
	}//end testASeamKeepiqRefusalIsKeepiqMissing()


	/**
	 * The properties and credential roles each kind needs.
	 *
	 * @return void
	 */
	public function testWhatEachKindNeeds(): void {
		$saml = new IdpRegistration(id: 'r', data: ['organisation' => 'o', 'provider' => 'digid', 'kind' => 'saml-sp']);
		$this->assertSame(['idpMetadataUrl'], $saml->missingProperties());
		$this->assertSame(['signing', 'salt'], $saml->requiredCredentialRoles());

		$encrypting = new IdpRegistration(id: 'r', data: ['kind' => 'saml-sp', 'pseudonymisation' => 'polymorphic', 'credentials' => ['decryption' => 'c']]);
		$this->assertSame(['signing', 'decryption', 'pseudonymKeys'], $encrypting->requiredCredentialRoles());

		$secretBasic = new IdpRegistration(id: 'r', data: ['kind' => 'oidc-rp', 'clientAuth' => 'client_secret_basic']);
		$this->assertSame(['clientSecret', 'salt'], $secretBasic->requiredCredentialRoles());

		$unknown = new IdpRegistration(id: 'r', data: ['organisation' => 'o', 'provider' => 'digid']);
		$this->assertSame(['kind'], $unknown->missingProperties());
		$this->assertSame('incomplete', $this->readiness(['enabled' => true, 'organisation' => 'o', 'provider' => 'digid', 'kind' => 'ldap'])->status);
	}//end testWhatEachKindNeeds()


	/**
	 * The typed accessors read strings only and fall back sensibly.
	 *
	 * @return void
	 */
	public function testTheAccessors(): void {
		$registration = new IdpRegistration(id: 'reg-9', data: ['label' => ' ', 'enabled' => 'yes', 'credentials' => 'nope', 'organisation' => ['x']]);

		$this->assertSame('reg-9', $registration->name());
		$this->assertFalse($registration->isEnabled());
		$this->assertSame('', $registration->credential(role: 'signing'));
		$this->assertSame('', $registration->organisation());
	}//end testTheAccessors()


	/**
	 * A second registration for the same organisation and provider is refused, naming the first.
	 *
	 * @return void
	 */
	public function testADuplicateRegistrationIsRefused(): void {
		$this->stored = [$this->object('reg-1', self::oidc())];

		try {
			$this->service()->save(data: self::oidc(['label' => 'Tweede']));
			$this->fail('A duplicate was saved.');
		} catch (IdpRegistrationConflictException $conflict) {
			$this->assertSame('reg-1', $conflict->existing);
			$this->assertSame('Organisation gemeente-x already has a digid registration: DigiD via broker. Edit that one instead.', $conflict->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testADuplicateRegistrationIsRefused()


	/**
	 * Editing the existing one, or saving another provider or organisation, is allowed.
	 *
	 * @return void
	 */
	public function testEditingAndOtherPairsAreSaved(): void {
		$this->stored = [$this->object('reg-1', self::oidc())];
		$service = $this->service();

		$this->assertSame('reg-1', $service->save(data: self::oidc(['label' => 'Nieuw']), uuid: 'reg-1')->id);
		$this->assertSame('new-uuid', $service->save(data: self::oidc(['provider' => 'eherkenning']))->id);
		$this->assertSame('new-uuid', $service->save(data: self::oidc(['organisation' => 'gemeente-y']))->id);
		$this->assertCount(3, $this->saved);
	}//end testEditingAndOtherPairsAreSaved()


	/**
	 * Lookups re-check the organisation and provider, and an empty one finds nothing.
	 *
	 * @return void
	 */
	public function testFindRechecksTheFilter(): void {
		$this->stored = [$this->object('reg-1', self::oidc()), $this->object('reg-2', self::oidc(['organisation' => 'gemeente-y']))];
		$service = $this->service();

		$this->assertSame('reg-2', $service->find(organisation: 'gemeente-y', provider: 'digid')?->id);
		$this->assertNull($service->find(organisation: 'gemeente-z', provider: 'digid'));
		$this->assertSame([], $service->findAll(organisation: '', provider: 'digid'));
	}//end testFindRechecksTheFilter()


	/**
	 * The registry binds a live adapter only for a ready registration of a kind it can build.
	 *
	 * @return void
	 */
	public function testTheRegistryBindsLiveOnlyWhenReady(): void {
		$this->stored = [$this->object('reg-1', self::oidc())];
		$live = $this->createMock(GovernmentIdpAdapterInterface::class);
		$built = [];
		$registry = new IdpAdapterRegistry(
			boundAdapter: new LogGovernmentIdpAdapter(logger: new NullLogger()),
			logger: new NullLogger(),
			registrations: $this->service(),
			kindAdapters: [
				'oidc-rp' => function (IdpRegistration $registration) use ($live, &$built): GovernmentIdpAdapterInterface {
					$built[] = $registration->id;
					return $live;
				},
			]
		);

		$this->assertSame($live, $registry->forRegistration(organisation: 'gemeente-x', provider: 'digid'));
		$this->assertSame(['reg-1'], $built);

		$this->assertInstanceOf(LogGovernmentIdpAdapter::class, $registry->forRegistration(organisation: 'gemeente-y', provider: 'digid'));

		$this->flag = false;
		$this->assertInstanceOf(LogGovernmentIdpAdapter::class, $registry->forRegistration(organisation: 'gemeente-x', provider: 'digid'));

		$this->flag = true;
		$this->stored = [$this->object('reg-1', self::oidc(['kind' => 'saml-sp', 'idpMetadataUrl' => 'https://idp.example/md']))];
		$this->assertInstanceOf(LogGovernmentIdpAdapter::class, $registry->forRegistration(organisation: 'gemeente-x', provider: 'digid'));
		$this->assertSame(['reg-1'], $built, 'no builder for saml-sp yet, so none is called');
	}//end testTheRegistryBindsLiveOnlyWhenReady()


	/**
	 * Without registrations the registry refuses, and an unknown provider throws.
	 *
	 * @return void
	 */
	public function testTheRegistryWithoutRegistrations(): void {
		$registry = new IdpAdapterRegistry(boundAdapter: new LogGovernmentIdpAdapter(logger: new NullLogger()), logger: new NullLogger());

		$adapter = $registry->forRegistration(organisation: 'gemeente-x', provider: 'eherkenning');
		$this->assertInstanceOf(LogGovernmentIdpAdapter::class, $adapter);
		$this->assertSame('eherkenning', $adapter->getProviderId());

		$this->expectException(IdpAssertionException::class);
		$registry->forRegistration(organisation: 'gemeente-x', provider: 'facebook');
	}//end testTheRegistryWithoutRegistrations()
}//end class
