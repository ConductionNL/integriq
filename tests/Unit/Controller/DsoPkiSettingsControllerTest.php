<?php

/**
 * Tests for DsoPkiSettingsController: the DSO connection is the dso-stam consumer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DsoPkiSettingsController;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Read and write the connection; validate the account on save.
 */
class DsoPkiSettingsControllerTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Uids in the admin group.
	 *
	 * @var list<string>
	 */
	private array $admins = ['admin'];

	/**
	 * A world with an administrator active, as the settings page runs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->addAccount(uid: 'admin');
		$this->worldSession->setVolatileActiveUser($this->worldUser('admin'));
		$this->addAccount(uid: 'dso-intake');
		$this->params = [];
	}//end setUp()

	/**
	 * The controller over the world.
	 *
	 * @return DsoPkiSettingsController
	 */
	private function controller(): DsoPkiSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->admins, true));

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$objectService = $this->buildWorldObjectService();
		$logger = new NullLogger();

		return new DsoPkiSettingsController(
			request: $request,
			connection: $this->buildWorldConnection(objectService: $objectService),
			signatureVerifier: new DSOSignatureVerifierService(new WebhookSignatureService($logger), $logger),
			groupManager: $groupManager,
			groups: $this->buildWorldIntakeGroups(),
			l: $l,
			logger: $logger
		);
	}//end controller()

	/**
	 * GET never returns the HMAC secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-the-trust-configuration-is-never-returned
	 */
	public function testGetNeverReturnsTheSecret(): void {
		$this->addDsoConsumer(userId: 'dso-intake');

		$data = $this->controller()->getConfig()->getData();

		$this->assertTrue($data['hmacSecretConfigured']);
		$this->assertStringNotContainsString($this->worldSecret, (string)json_encode($data));
		$this->assertSame('dso-intake', $data['userId']);
		$this->assertSame('ok', $data['account']['state']);

	}//end testGetNeverReturnsTheSecret()

	/**
	 * No consumer: the section reads that no account is set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-no-account-set-is-shown-plainly
	 */
	public function testNoConsumerReadsAsNoAccount(): void {
		$data = $this->controller()->getConfig()->getData();

		$this->assertFalse($data['configured']);
		$this->assertSame('none', $data['account']['state']);

	}//end testNoConsumerReadsAsNoAccount()

	/**
	 * Saving a valid account creates the dso-stam consumer, as the administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-choosing-a-valid-account
	 */
	public function testSavingAValidAccountCreatesTheConnection(): void {
		$this->params = ['mode' => 'hmac', 'hmacSecret' => 'new-secret', 'userId' => 'dso-intake'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['warnings']);
		$this->assertCount(1, $this->worldConsumers);
		$consumer = array_values($this->worldConsumers)[0];
		$this->assertSame('dso-stam', $consumer['authorizationType']);
		$this->assertSame('dso-intake', $consumer['userId']);
		$this->assertSame('new-secret', $consumer['authorizationConfiguration']['hmacSecret']);
		$this->assertSame(['admin'], $this->writerUids(), 'the administrator writes the consumer');
		$this->assertSame([true], array_column($this->worldWrites, 'rbac'));

	}//end testSavingAValidAccountCreatesTheConnection()

	/**
	 * An empty secret keeps the stored one; the existing consumer is updated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function testAnEmptySecretKeepsTheStoredOne(): void {
		$this->addDsoConsumer(userId: '');
		$this->params = ['mode' => 'hmac', 'hmacSecret' => '', 'userId' => 'dso-intake'];

		$this->controller()->setConfig();

		$this->assertSame(['consumer-dso'], array_keys($this->worldConsumers));
		$this->assertSame($this->worldSecret, $this->worldConsumers['consumer-dso']['authorizationConfiguration']['hmacSecret']);
		$this->assertSame('dso-intake', $this->worldConsumers['consumer-dso']['userId']);

	}//end testAnEmptySecretKeepsTheStoredOne()

	/**
	 * Unknown, disabled and right-less accounts are refused with a field error,
	 * and the stored account stays as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-an-account-without-rights-is-refused
	 */
	public function testUnusableAccountsAreRefused(): void {
		$this->addDsoConsumer(userId: 'dso-intake');
		$this->addAccount(uid: 'off', enabled: false);
		$this->addAccount(uid: 'reader', grants: ['read']);

		foreach (['ghost' => 'does not exist', 'off' => 'is disabled', 'reader' => 'lacks the create, update right'] as $uid => $message) {
			$this->params = ['mode' => 'hmac', 'userId' => $uid];
			$response = $this->controller()->setConfig();

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid);
			$this->assertStringContainsString($message, $response->getData()['fieldErrors']['userId'], $uid);
		}

		$this->assertSame('dso-intake', $this->worldConsumers['consumer-dso']['userId']);
		$this->assertSame([], $this->worldWrites);

	}//end testUnusableAccountsAreRefused()

	/**
	 * An administrator account saves, with a warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function testAnAdministratorAccountSavesWithAWarning(): void {
		$this->params = ['mode' => 'hmac', 'hmacSecret' => 's', 'userId' => 'admin'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['warnings']);
		$this->assertStringContainsString('administrator', $response->getData()['warnings'][0]);

	}//end testAnAdministratorAccountSavesWithAWarning()
	/**
	 * An account without rights of its own joins dso-intake and saves: the
	 * authorization block grants that group create and update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/dso-omgevingsloket/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testAChosenAccountJoinsTheIntakeGroup(): void {
		$this->worldGroupGrants = ['dso-intake' => ['create', 'update']];
		$this->addAccount(uid: 'fresh', grants: []);
		$this->params = ['mode' => 'hmac', 'hmacSecret' => 's'] + ['userId' => 'fresh'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['fresh'], $this->worldGroupMembers['dso-intake']);
		$this->assertSame('fresh', array_values($this->worldConsumers)[0]['userId']);

	}//end testAChosenAccountJoinsTheIntakeGroup()

	/**
	 * A refused account is not left behind in dso-intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/dso-omgevingsloket/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testARefusedAccountDoesNotStayInTheIntakeGroup(): void {
		$this->addAccount(uid: 'reader', grants: ['read']);
		$this->params = ['mode' => 'hmac', 'hmacSecret' => 's'] + ['userId' => 'reader'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], ($this->worldGroupMembers['dso-intake'] ?? []));

	}//end testARefusedAccountDoesNotStayInTheIntakeGroup()

	/**
	 * Choosing another account takes the previous one out of dso-intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/dso-omgevingsloket/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testThePreviousAccountLeavesTheIntakeGroup(): void {
		$this->worldGroupGrants = ['dso-intake' => ['create', 'update']];
		$this->addAccount(uid: 'old', grants: []);
		$this->addAccount(uid: 'new', grants: []);
		$this->worldGroupMembers = ['dso-intake' => ['old']];
		$this->addDsoConsumer(userId: 'old');
		$this->params = ['mode' => 'hmac', 'hmacSecret' => 's'] + ['userId' => 'new'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['new'], $this->worldGroupMembers['dso-intake']);

	}//end testThePreviousAccountLeavesTheIntakeGroup()
}//end class
