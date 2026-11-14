<?php

/**
 * Tests for ConnectionHandoverService: a handed-over connection fills only empty
 * fields and keeps its secrets as verified broker references.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\ConnectionHandoverService;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\NestedValuePath;
use OCA\Integriq\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * A broker that mints numbered credentials and resolves them, optionally wrongly.
 */
class HandoverBrokerDouble {

	/**
	 * Minted secrets by credential id.
	 *
	 * @var array<string, string>
	 */
	public array $store = [];

	/**
	 * Every mint call: name, provider, owner, apps, scope, organisation.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $mints = [];

	/**
	 * Credential ids whose resolve answers a different value.
	 *
	 * @var list<string>
	 */
	public array $corrupt = [];

	/**
	 * Whether mint throws.
	 *
	 * @var bool
	 */
	public bool $mintThrows = false;

	/**
	 * Mint a credential.
	 *
	 * @param string      $name         The name.
	 * @param string      $provider     The provider.
	 * @param string      $owner        The owner.
	 * @param array       $allowedApps  The allowed apps.
	 * @param string|null $secret       The secret.
	 * @param string      $scope        The scope.
	 * @param string|null $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	public function mint(
		string $name,
		string $provider,
		string $owner,
		array $allowedApps = [],
		?string $secret = null,
		string $scope = 'personal',
		?string $organisation = null,
	): ObjectEntity {
		if ($this->mintThrows === true) {
			throw new RuntimeException('mint refused');
		}

		$id = 'cred-' . (count($this->store) + 1);
		$this->store[$id] = (string)$secret;
		$this->mints[] = [
			'name' => $name,
			'provider' => $provider,
			'owner' => $owner,
			'apps' => $allowedApps,
			'scope' => $scope,
			'organisation' => $organisation,
		];
		$entity = new ObjectEntity();
		$entity->setUuid($id);

		return $entity;

	}//end mint()

	/**
	 * Resolve a credential.
	 *
	 * @param string      $credentialId         The credential id.
	 * @param string      $appId                The app id.
	 * @param string|null $actingUserId         The acting user.
	 * @param string|null $actingOrganisationId The acting organisation.
	 *
	 * @return string|null
	 */
	public function resolveInjectable(
		string $credentialId,
		string $appId,
		?string $actingUserId = null,
		?string $actingOrganisationId = null,
	): ?string {
		if (in_array($credentialId, $this->corrupt, true) === true) {
			return 'something else';
		}

		return ($this->store[$credentialId] ?? null);

	}//end resolveInjectable()
}//end class

/**
 * A logger that keeps every message and context.
 */
class HandoverRecordingLogger extends AbstractLogger {

	/**
	 * Every log call, serialised.
	 *
	 * @var list<string>
	 */
	public array $lines = [];

	/**
	 * Record a log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->lines[] = $message . ' ' . json_encode($context);

	}//end log()
}//end class

/**
 * Tests for the handover of a connection onto an existing source.
 */
#[CoversClass(ConnectionHandoverService::class)]
#[UsesClass(NestedValuePath::class)]
class ConnectionHandoverServiceTest extends TestCase {

	/**
	 * The handed-over settings of the spec scenario.
	 *
	 * @var array<string, string>
	 */
	private const SETTINGS = [
		'baseUrl' => 'https://brp.example.nl/haalcentraal/api/brp',
		'clientId' => 'client-42',
		'clientSecret' => 's3cr3t-value',
		'certificate' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----",
		'privateKey' => "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----",
	];

	/**
	 * Object service double.
	 *
	 * @var MigrationSimulatingObjectService
	 */
	private MigrationSimulatingObjectService $objects;

	/**
	 * Broker double.
	 *
	 * @var HandoverBrokerDouble
	 */
	private HandoverBrokerDouble $broker;

	/**
	 * Logger double.
	 *
	 * @var HandoverRecordingLogger
	 */
	private HandoverRecordingLogger $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new MigrationSimulatingObjectService();
		$this->broker = new HandoverBrokerDouble();
		$this->logger = new HandoverRecordingLogger();

	}//end setUp()

	/**
	 * The service with the broker in its container (or a container without it).
	 *
	 * @param bool $withBroker Whether the container has the broker.
	 *
	 * @return ConnectionHandoverService
	 */
	private function service(bool $withBroker = true): ConnectionHandoverService {
		$container = $this->createMock(ContainerInterface::class);
		if ($withBroker === true) {
			$container->method('get')->with(InlineSecretMigrationExecutor::BROKER_CLASS)->willReturn($this->broker);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('no broker'));
		}

		return new ConnectionHandoverService(objectService: $this->objects, container: $container, logger: $this->logger);

	}//end service()

	/**
	 * Seed the empty BRP source, as the register ships it.
	 *
	 * @param string|null $organisation The source's organisation.
	 *
	 * @return void
	 */
	private function seedEmptySource(?string $organisation = 'org-1'): void {
		$this->objects->seed(
			uuid: 'src-brp',
			object: [
				'name' => 'BRP HaalCentraal Personen',
				'slug' => 'brp-haalcentraal',
				'location' => '',
				'configuration' => [
					'authentication' => ['tokenUrl' => 'https://oauth.example.nl/token', 'client_id' => '', 'client_secret' => ''],
					'cert' => '',
					'ssl_key' => '',
				],
			],
			owner: 'admin',
			organisation: $organisation
		);

	}//end seedEmptySource()

	/**
	 * The stored source data.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return $this->objects->stored['src-brp']['object'];

	}//end stored()

	/**
	 * Every part handed to an empty source is taken; the secrets become verified references.
	 *
	 * @return void
	 */
	public function testAnEmptySourceTakesEveryPartAndHoldsFourReferences(): void {
		$this->seedEmptySource();
		$settings = self::SETTINGS + ['caBundle' => "-----BEGIN CERTIFICATE-----\nCCC\n-----END CERTIFICATE-----"];

		$answer = $this->service()->takeOver(sourceSlug: 'brp-haalcentraal', settings: $settings);

		self::assertSame(
			[
				'baseUrl' => 'taken',
				'clientId' => 'taken',
				'clientSecret' => 'taken',
				'certificate' => 'taken',
				'privateKey' => 'taken',
				'caBundle' => 'taken',
			],
			$answer
		);
		$data = $this->stored();
		self::assertSame('https://brp.example.nl/haalcentraal/api/brp', $data['location']);
		self::assertSame('client-42', $data['configuration']['authentication']['client_id']);
		self::assertSame('https://oauth.example.nl/token', $data['configuration']['authentication']['tokenUrl']);

		$refs = [
			$data['configuration']['authentication']['client_secret'],
			$data['configuration']['cert'],
			$data['configuration']['ssl_key'],
			$data['configuration']['authentication']['mtls']['caBundle'],
		];
		foreach ($refs as $ref) {
			self::assertSame(['credentialRef'], array_keys($ref));
		}

		// Each reference resolves to exactly the secret handed over for that part.
		self::assertSame(self::SETTINGS['clientSecret'], $this->broker->store[$refs[0]['credentialRef']['credentialId']]);
		self::assertSame(self::SETTINGS['certificate'], $this->broker->store[$refs[1]['credentialRef']['credentialId']]);
		self::assertSame(self::SETTINGS['privateKey'], $this->broker->store[$refs[2]['credentialRef']['credentialId']]);
		self::assertSame($settings['caBundle'], $this->broker->store[$refs[3]['credentialRef']['credentialId']]);

		self::assertCount(1, $this->objects->saves);
		self::assertFalse($this->objects->saves[0]['_rbac']);

	}//end testAnEmptySourceTakesEveryPartAndHoldsFourReferences()

	/**
	 * Secrets are minted at organisation scope, for the broker's app id, with the source's owner.
	 *
	 * @return void
	 */
	public function testSecretsAreMintedForTheSourcesOrganisation(): void {
		$this->seedEmptySource();

		$this->service()->takeOver(sourceSlug: 'brp-haalcentraal', settings: ['clientSecret' => 'abc']);

		self::assertCount(1, $this->broker->mints);
		$mint = $this->broker->mints[0];
		self::assertSame('organisation', $mint['scope']);
		self::assertSame('org-1', $mint['organisation']);
		self::assertSame('admin', $mint['owner']);
		self::assertSame([InlineSecretMigrationExecutor::APP_ID], $mint['apps']);
		self::assertSame('generic-oauth2', $mint['provider']);
		self::assertSame('BRP HaalCentraal Personen — clientSecret', $mint['name']);

	}//end testSecretsAreMintedForTheSourcesOrganisation()

	/**
	 * A base URL already set is kept and the answer reports the conflict.
	 *
	 * @return void
	 */
	public function testAValueAlreadySetIsKeptAndReportedAsAConflict(): void {
		$this->seedEmptySource();
		$this->objects->stored['src-brp']['object']['location'] = 'https://own.example.nl/brp';

		$answer = $this->service()->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['baseUrl' => 'https://other.example.nl/brp', 'clientId' => 'client-42']
		);

		self::assertSame(['baseUrl' => 'conflict', 'clientId' => 'taken'], $answer);
		self::assertSame('https://own.example.nl/brp', $this->stored()['location']);
		self::assertSame('client-42', $this->stored()['configuration']['authentication']['client_id']);

	}//end testAValueAlreadySetIsKeptAndReportedAsAConflict()

	/**
	 * The same value already set is reported as already set, and nothing is saved.
	 *
	 * @return void
	 */
	public function testTheSameValueAlreadySetIsAlreadySetAndSavesNothing(): void {
		$this->seedEmptySource();
		$this->objects->stored['src-brp']['object']['location'] = 'https://brp.example.nl/haalcentraal/api/brp';

		$answer = $this->service()->takeOver(sourceSlug: 'brp-haalcentraal', settings: ['baseUrl' => self::SETTINGS['baseUrl']]);

		self::assertSame(['baseUrl' => 'already-set'], $answer);
		self::assertSame([], $this->objects->saves);

	}//end testTheSameValueAlreadySetIsAlreadySetAndSavesNothing()

	/**
	 * A secret the source already holds is never replaced and never minted.
	 *
	 * @return void
	 */
	public function testASecretAlreadyHeldIsNeverReplaced(): void {
		$this->seedEmptySource();
		$own = ['credentialRef' => ['credentialId' => 'admin-cred']];
		$this->objects->stored['src-brp']['object']['configuration']['authentication']['client_secret'] = $own;

		$answer = $this->service()->takeOver(sourceSlug: 'brp-haalcentraal', settings: ['clientSecret' => 'new-secret']);

		self::assertSame(['clientSecret' => 'already-set'], $answer);
		self::assertSame([], $this->broker->mints);
		self::assertSame($own, $this->stored()['configuration']['authentication']['client_secret']);

	}//end testASecretAlreadyHeldIsNeverReplaced()

	/**
	 * A reference that does not resolve to the same bytes is not written; the other parts still are.
	 *
	 * @return void
	 */
	public function testAVerifyMismatchFailsOnlyThatPart(): void {
		$this->seedEmptySource();
		$this->broker->corrupt = ['cred-1'];

		$answer = $this->service()->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['clientSecret' => 'abc', 'certificate' => 'pem']
		);

		self::assertSame(['clientSecret' => 'failed', 'certificate' => 'taken'], $answer);
		self::assertSame('', $this->stored()['configuration']['authentication']['client_secret']);
		self::assertSame(['credentialRef' => ['credentialId' => 'cred-2']], $this->stored()['configuration']['cert']);

	}//end testAVerifyMismatchFailsOnlyThatPart()

	/**
	 * A mint the broker refuses fails the part.
	 *
	 * @return void
	 */
	public function testARefusedMintFailsThePart(): void {
		$this->seedEmptySource();
		$this->broker->mintThrows = true;

		$answer = $this->service()->takeOver(sourceSlug: 'brp-haalcentraal', settings: ['privateKey' => 'pem']);

		self::assertSame(['privateKey' => 'failed'], $answer);
		self::assertSame([], $this->objects->saves);

	}//end testARefusedMintFailsThePart()

	/**
	 * A source without an organisation takes plain parts but never mints a personal secret.
	 *
	 * @return void
	 */
	public function testASourceWithoutOrganisationNeverMints(): void {
		$this->seedEmptySource(organisation: null);

		$answer = $this->service()->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['clientId' => 'client-42', 'clientSecret' => 'abc']
		);

		self::assertSame(['clientId' => 'taken', 'clientSecret' => 'failed'], $answer);
		self::assertSame([], $this->broker->mints);

	}//end testASourceWithoutOrganisationNeverMints()

	/**
	 * Without a broker, secret parts fail and plain parts are still taken.
	 *
	 * @return void
	 */
	public function testWithoutABrokerSecretsFail(): void {
		$this->seedEmptySource();

		$answer = $this->service(withBroker: false)->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['baseUrl' => 'https://brp.example.nl', 'clientSecret' => 'abc']
		);

		self::assertSame(['baseUrl' => 'taken', 'clientSecret' => 'failed'], $answer);
		self::assertSame('https://brp.example.nl', $this->stored()['location']);

	}//end testWithoutABrokerSecretsFail()

	/**
	 * A failed save fails every part that would have been taken.
	 *
	 * @return void
	 */
	public function testAFailedSaveFailsEveryPendingPart(): void {
		$this->seedEmptySource();
		$this->objects->stored['src-brp']['object']['location'] = 'https://own.example.nl';
		$this->objects->failSaveForUuid = 'src-brp';

		$answer = $this->service()->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['baseUrl' => 'https://other.example.nl', 'clientId' => 'c', 'clientSecret' => 'abc']
		);

		self::assertSame(['baseUrl' => 'conflict', 'clientId' => 'failed', 'clientSecret' => 'failed'], $answer);

	}//end testAFailedSaveFailsEveryPendingPart()

	/**
	 * An unknown source fails every given part; empty and unknown parts are not answered.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceFailsEveryGivenPart(): void {
		$this->seedEmptySource();

		$answer = $this->service()->takeOver(
			sourceSlug: 'no-such-source',
			settings: ['baseUrl' => 'https://x.example.nl', 'clientId' => '', 'shoeSize' => '42']
		);

		self::assertSame(['baseUrl' => 'failed'], $answer);
		self::assertSame([], $this->objects->saves);

	}//end testAnUnknownSourceFailsEveryGivenPart()

	/**
	 * No secret appears in any log line, whatever fails.
	 *
	 * @return void
	 */
	public function testNoSecretIsEverLogged(): void {
		$this->seedEmptySource();
		$this->broker->corrupt = ['cred-1'];
		$this->objects->failSaveForUuid = 'src-brp';

		$this->service()->takeOver(
			sourceSlug: 'brp-haalcentraal',
			settings: ['clientSecret' => 'very-secret-1', 'certificate' => 'very-secret-2']
		);

		self::assertNotSame([], $this->logger->lines);
		foreach ($this->logger->lines as $line) {
			self::assertStringNotContainsString('very-secret', $line);
		}

	}//end testNoSecretIsEverLogged()
}//end class
