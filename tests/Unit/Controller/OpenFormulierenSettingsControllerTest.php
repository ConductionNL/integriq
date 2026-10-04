<?php

/**
 * The Open Formulieren connection settings: the consumer and its account.
 *
 * Runs the real OpenFormulierenConnection over the DSO connection world, so
 * the consumer is written as the administrator under RBAC and the account is
 * checked through the same rights check the intake uses.
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
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-open-formulieren-connections-account-is-chosen-and-checked-by-an-administrator-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OpenFormulierenSettingsController;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The admin settings of the Open Formulieren connection.
 */
class OpenFormulierenSettingsControllerTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The administrators.
	 *
	 * @var list<string>
	 */
	private array $admins = ['admin'];

	/**
	 * An administrator is logged in; `of-intake` holds the rights.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'admin');
		$this->worldSession->setVolatileActiveUser($this->worldUser('admin'));
		$this->addAccount(uid: 'of-intake', grants: ['create', 'update']);
		$this->params = [];

	}//end setUp()

	/**
	 * Build the controller over the world.
	 *
	 * @return OpenFormulierenSettingsController The controller.
	 */
	private function controller(): OpenFormulierenSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->admins, true));

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new OpenFormulierenSettingsController(
			request: $request,
			connection: $this->buildWorldOpenFormulierenConnection(objectService: $this->buildWorldObjectService()),
			groupManager: $groupManager,
			groups: $this->buildWorldIntakeGroups(),
			l: $l,
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * GET never returns the secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-choosing-a-valid-account
	 */
	public function testGetNeverReturnsTheSecret(): void {
		$this->addOpenFormulierenConsumer(userId: 'of-intake');

		$data = $this->controller()->getConfig()->getData();

		$this->assertTrue($data['secretConfigured']);
		$this->assertStringNotContainsString($this->worldSecret, (string)json_encode($data));
		$this->assertSame('of-intake', $data['userId']);
		$this->assertSame('ok', $data['account']['state']);
		$this->assertSame('openconnector', $data['scheme']);

	}//end testGetNeverReturnsTheSecret()

	/**
	 * No consumer reads as no account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-account-set-is-shown-plainly
	 */
	public function testNoConsumerReadsAsNoAccount(): void {
		$data = $this->controller()->getConfig()->getData();

		$this->assertFalse($data['configured']);
		$this->assertSame('none', $data['account']['state']);

	}//end testNoConsumerReadsAsNoAccount()

	/**
	 * Saving a valid account creates the open-formulieren consumer, as the administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-choosing-a-valid-account
	 */
	public function testSavingAValidAccountCreatesTheConnection(): void {
		$this->params = ['scheme' => 'openconnector', 'secret' => 'whsec_new', 'userId' => 'of-intake'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['warnings']);
		$this->assertCount(1, $this->worldConsumers);
		$consumer = array_values($this->worldConsumers)[0];
		$this->assertSame('open-formulieren', $consumer['authorizationType']);
		$this->assertSame('of-intake', $consumer['userId']);
		$this->assertSame('whsec_new', $consumer['authorizationConfiguration']['secret']);
		$this->assertSame('X-OpenFormulieren-Signature', $consumer['authorizationConfiguration']['header']);
		$this->assertSame(['admin'], $this->writerUids(), 'the administrator writes the consumer');
		$this->assertSame([true], array_column($this->worldWrites, 'rbac'));

	}//end testSavingAValidAccountCreatesTheConnection()

	/**
	 * An empty secret keeps the stored one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-choosing-a-valid-account
	 */
	public function testAnEmptySecretKeepsTheStoredOne(): void {
		$this->addOpenFormulierenConsumer(userId: '');
		$this->params = ['scheme' => 'openconnector', 'secret' => '', 'userId' => 'of-intake'];

		$this->controller()->setConfig();

		$this->assertSame(['consumer-of'], array_keys($this->worldConsumers));
		$this->assertSame($this->worldSecret, $this->worldConsumers['consumer-of']['authorizationConfiguration']['secret']);
		$this->assertSame('of-intake', $this->worldConsumers['consumer-of']['userId']);

	}//end testAnEmptySecretKeepsTheStoredOne()

	/**
	 * Unknown, disabled and right-less accounts are refused with a field error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-an-account-without-rights-is-refused
	 */
	public function testUnusableAccountsAreRefused(): void {
		$this->addOpenFormulierenConsumer(userId: 'of-intake');
		$this->addAccount(uid: 'off', enabled: false);
		$this->addAccount(uid: 'reader', grants: ['read']);

		foreach (['ghost' => 'does not exist', 'off' => 'is disabled', 'reader' => 'lacks the create, update right'] as $uid => $message) {
			$this->params = ['scheme' => 'openconnector', 'userId' => $uid];
			$response = $this->controller()->setConfig();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid);
			$this->assertStringContainsString($message, $response->getData()['fieldErrors']['userId'], $uid);
		}

		$this->assertSame('of-intake', $this->worldConsumers['consumer-of']['userId']);
		$this->assertSame([], $this->worldWrites);

	}//end testUnusableAccountsAreRefused()

	/**
	 * An unknown signature scheme is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-choosing-a-valid-account
	 */
	public function testAnUnknownSchemeIsRefused(): void {
		$this->params = ['scheme' => 'md5', 'secret' => 's', 'userId' => 'of-intake'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->worldWrites);

	}//end testAnUnknownSchemeIsRefused()

	/**
	 * An administrator account saves with a warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-choosing-a-valid-account
	 */
	public function testAnAdministratorAccountSavesWithAWarning(): void {
		$this->params = ['scheme' => 'openconnector', 'secret' => 's', 'userId' => 'admin'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['warnings']);
		$this->assertStringContainsString('administrator', $response->getData()['warnings'][0]);

	}//end testAnAdministratorAccountSavesWithAWarning()
	/**
	 * An account without rights of its own joins openformulieren-intake and saves: the
	 * authorization block grants that group create and update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testAChosenAccountJoinsTheIntakeGroup(): void {
		$this->worldGroupGrants = ['openformulieren-intake' => ['create', 'update']];
		$this->addAccount(uid: 'fresh', grants: []);
		$this->params = ['scheme' => 'openconnector', 'secret' => 's'] + ['userId' => 'fresh'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['fresh'], $this->worldGroupMembers['openformulieren-intake']);
		$this->assertSame('fresh', array_values($this->worldConsumers)[0]['userId']);

	}//end testAChosenAccountJoinsTheIntakeGroup()

	/**
	 * A refused account is not left behind in openformulieren-intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testARefusedAccountDoesNotStayInTheIntakeGroup(): void {
		$this->addAccount(uid: 'reader', grants: ['read']);
		$this->params = ['scheme' => 'openconnector', 'secret' => 's'] + ['userId' => 'reader'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], ($this->worldGroupMembers['openformulieren-intake'] ?? []));

	}//end testARefusedAccountDoesNotStayInTheIntakeGroup()

	/**
	 * Choosing another account takes the previous one out of openformulieren-intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-the-chosen-intake-account-joins-the-intake-group
	 */
	public function testThePreviousAccountLeavesTheIntakeGroup(): void {
		$this->worldGroupGrants = ['openformulieren-intake' => ['create', 'update']];
		$this->addAccount(uid: 'old', grants: []);
		$this->addAccount(uid: 'new', grants: []);
		$this->worldGroupMembers = ['openformulieren-intake' => ['old']];
		$this->addOpenFormulierenConsumer(userId: 'old');
		$this->params = ['scheme' => 'openconnector', 'secret' => 's'] + ['userId' => 'new'];

		$response = $this->controller()->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['new'], $this->worldGroupMembers['openformulieren-intake']);

	}//end testThePreviousAccountLeavesTheIntakeGroup()

	/**
	 * While the handler group has no members, GET says so, and once it has one it does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-handler-group-notice/specs/intake-access/spec.md#scenario-an-empty-handler-group-is-announced
	 */
	public function testGetReportsWhetherTheHandlerGroupIsEmpty(): void {
		$this->addOpenFormulierenConsumer(userId: 'of-intake');

		$this->worldGroupMembers = [];
		$data = $this->controller()->getConfig()->getData();
		$this->assertSame(['id' => 'openformulieren-behandelaars', 'empty' => true], $data['handlerGroup'], 'a group that does not exist yet is empty');

		$this->worldGroupMembers = ['openformulieren-behandelaars' => []];
		$data = $this->controller()->getConfig()->getData();
		$this->assertTrue($data['handlerGroup']['empty'], 'an existing group without members is empty');

		$this->worldGroupMembers = ['openformulieren-behandelaars' => ['behandelaar']];
		$data = $this->controller()->getConfig()->getData();
		$this->assertSame(['id' => 'openformulieren-behandelaars', 'empty' => false], $data['handlerGroup']);

	}//end testGetReportsWhetherTheHandlerGroupIsEmpty()
}//end class
