<?php

/**
 * The admin settings of the webhook connections: one consumer and account per webhook.
 *
 * Runs the real WebhookConnection over the connection world, so the consumer
 * is written as the administrator under RBAC and the account is checked with
 * the same rights check the webhook uses.
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
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-an-administrator-chooses-each-webhooks-account-req-cm-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\WebhookConnectionsSettingsController;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\Integriq\Tests\Helpers\WebhookWorld;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Listing, choosing and refusing a webhook's account.
 */
class WebhookConnectionsSettingsControllerTest extends TestCase {
	use WebhookWorld;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * An administrator is logged in; `rod-acc` may write ROD messages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'admin');
		$this->worldSession->setVolatileActiveUser($this->worldUser('admin'));
		$this->addAccount(uid: 'rod-acc', grants: ['create', 'update']);
		$this->params = [];

	}//end setUp()

	/**
	 * Build the controller over the world.
	 *
	 * @return WebhookConnectionsSettingsController
	 */
	private function controller(): WebhookConnectionsSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$objectService = $this->buildWorldObjectService();

		return new WebhookConnectionsSettingsController(
			request: $request,
			webhooks: $this->buildWorldWebhookConnection(objectService: $objectService),
			objectService: $objectService,
			groupManager: $groupManager,
			groups: $this->buildWorldIntakeGroups(),
			l: $this->webhookL10n(),
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * GET lists every webhook, never returns a secret, and says which has no usable account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-the-settings-list-every-webhook-without-its-secret
	 */
	public function testGetListsEveryWebhookWithoutItsSecret(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::rod(), userId: 'rod-acc', uuid: 'consumer-rod');

		$data = $this->controller()->getConfig()->getData();

		$rows = array_column($data['connections'], null, 'authorizationType');
		$this->assertSame(array_keys(WebhookProfiles::all()), array_keys($rows));
		$this->assertStringNotContainsString($this->worldSecret, (string)json_encode($data));
		$this->assertTrue($rows['rod-webhook']['configured']);
		$this->assertTrue($rows['rod-webhook']['secretConfigured']);
		$this->assertSame('ok', $rows['rod-webhook']['account']['state']);
		$this->assertSame('rod_message', $rows['rod-webhook']['schema']);
		$this->assertFalse($rows['peppol-webhook']['configured']);
		$this->assertSame('none', $rows['peppol-webhook']['account']['state']);

	}//end testGetListsEveryWebhookWithoutItsSecret()

	/**
	 * Choosing a valid account saves the consumer, as the administrator, and keeps the secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
	 */
	public function testChoosingAValidAccountSavesTheConsumer(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::rod(), userId: '', uuid: 'consumer-rod');
		$this->params = ['userId' => 'rod-acc', 'secret' => ''];

		$response = $this->controller()->setConfig(authorizationType: 'rod-webhook');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('rod-acc', $this->worldConsumers['consumer-rod']['userId']);
		$this->assertSame($this->worldSecret, $this->worldConsumers['consumer-rod']['authorizationConfiguration']['secret'], 'a blank secret keeps the stored one');
		$this->assertSame(['admin'], $this->writerUids());
		$this->assertSame([true], array_column($this->worldWrites, 'rbac'), 'the administrator writes under RBAC');

	}//end testChoosingAValidAccountSavesTheConsumer()

	/**
	 * A webhook without a consumer yet gets one when the administrator saves a secret and an account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
	 */
	public function testSavingCreatesTheConsumer(): void {
		$this->params = ['userId' => 'rod-acc', 'secret' => 'whsec_new', 'scheme' => 'openconnector'];

		$this->controller()->setConfig(authorizationType: 'rod-webhook');

		$this->assertCount(1, $this->worldConsumers);
		$consumer = array_values($this->worldConsumers)[0];
		$this->assertSame('rod-webhook', $consumer['authorizationType']);
		$this->assertSame('whsec_new', $consumer['authorizationConfiguration']['secret']);
		$this->assertSame('X-OpenConnector-Signature', $consumer['authorizationConfiguration']['header']);

	}//end testSavingCreatesTheConsumer()

	/**
	 * Unknown, disabled and right-less accounts, and an unknown webhook, are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-account-that-cannot-write-is-refused
	 */
	public function testUnusableAccountsAreRefused(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::rod(), userId: 'rod-acc', uuid: 'consumer-rod');
		$this->addAccount(uid: 'off', enabled: false);
		$this->addAccount(uid: 'reader', grants: ['read']);

		foreach (['ghost' => 'does not exist', 'off' => 'is disabled', 'reader' => 'lacks the create, update right'] as $uid => $message) {
			$this->params = ['userId' => $uid];
			$response = $this->controller()->setConfig(authorizationType: 'rod-webhook');
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $uid);
			$this->assertStringContainsString($message, $response->getData()['fieldErrors']['userId'], $uid);
		}

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->setConfig(authorizationType: 'apiKey')->getStatus());
		$this->assertSame('rod-acc', $this->worldConsumers['consumer-rod']['userId']);
		$this->assertSame([], $this->worldWrites);

	}//end testUnusableAccountsAreRefused()

	/**
	 * An administrator account saves, with a warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
	 */
	public function testAnAdministratorAccountSavesWithAWarning(): void {
		$this->params = ['userId' => 'admin', 'secret' => 's'];

		$response = $this->controller()->setConfig(authorizationType: 'intake-channel-verdicts');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('is an administrator', $response->getData()['warnings'][0]);

	}//end testAnAdministratorAccountSavesWithAWarning()

	/**
	 * What membership grants, read from the schema's real authorization block
	 * in the merged register: group id => actions.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 */
	private function grantFromTheRegister(string $schema): void {
		$block = RegisterSchemaValidator::descriptor()['components']['schemas'][$schema]['authorization'];
		$this->worldGroupGrants = [];
		foreach ($block as $action => $groups) {
			foreach ($groups as $group) {
				$this->worldGroupGrants[$group][] = $action;
			}
		}

	}//end grantFromTheRegister()

	/**
	 * An account without rights of its own joins the intake group its schema
	 * grants, and saves. Before the access rules the block granted nobody and
	 * the save answered 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#scenario-the-chosen-account-joins-the-intake-group
	 */
	public function testAChosenAccountJoinsTheIntakeGroupOfItsSchema(): void {
		$this->addAccount(uid: 'channel-acc', grants: []);
		$this->addAccount(uid: 'verdict-acc', grants: []);

		$this->grantFromTheRegister(schema: 'intake_message');
		$this->params = ['userId' => 'channel-acc', 'secret' => 's'];
		$channel = $this->controller()->setConfig(authorizationType: 'intake-channel-form-submission');

		$this->grantFromTheRegister(schema: 'verdict');
		$this->params = ['userId' => 'verdict-acc', 'secret' => 's'];
		$verdicts = $this->controller()->setConfig(authorizationType: 'intake-channel-verdicts');

		$this->assertSame(Http::STATUS_OK, $channel->getStatus(), json_encode($channel->getData()));
		$this->assertSame(Http::STATUS_OK, $verdicts->getStatus(), json_encode($verdicts->getData()));
		$this->assertSame(['channel-acc'], $this->worldGroupMembers['intakekanalen-intake']);
		$this->assertSame(['verdict-acc'], $this->worldGroupMembers['verdicts-intake']);
		$this->assertSame([], ($this->worldGroupMembers['intakekanalen-behandelaars'] ?? []), 'nobody becomes a handler');

	}//end testAChosenAccountJoinsTheIntakeGroupOfItsSchema()

	/**
	 * A refused account is not left behind in the intake group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#scenario-the-chosen-account-joins-the-intake-group
	 */
	public function testARefusedAccountDoesNotStayInTheIntakeGroup(): void {
		$this->worldGroupGrants = ['verdicts-intake' => ['create']];
		$this->addAccount(uid: 'half', grants: []);
		$this->params = ['userId' => 'half', 'secret' => 's'];

		$response = $this->controller()->setConfig(authorizationType: 'intake-channel-verdicts');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], ($this->worldGroupMembers['verdicts-intake'] ?? []));

	}//end testARefusedAccountDoesNotStayInTheIntakeGroup()

	/**
	 * Choosing another account takes the previous one out, unless another
	 * channel of the same intake group still acts as it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#scenario-the-chosen-account-joins-the-intake-group
	 */
	public function testThePreviousAccountLeavesUnlessAnotherChannelUsesIt(): void {
		$this->grantFromTheRegister(schema: 'intake_message');
		$this->addAccount(uid: 'old', grants: []);
		$this->addAccount(uid: 'shared', grants: []);
		$this->addAccount(uid: 'new', grants: []);
		$this->worldGroupMembers = ['intakekanalen-intake' => ['old', 'shared']];
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'form-submission'), userId: 'old', uuid: 'c-form');
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'teams'), userId: 'shared', uuid: 'c-teams');
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'messaging'), userId: 'shared', uuid: 'c-messaging');

		$this->params = ['userId' => 'new', 'secret' => ''];
		$this->assertSame(Http::STATUS_OK, $this->controller()->setConfig(authorizationType: 'intake-channel-form-submission')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller()->setConfig(authorizationType: 'intake-channel-teams')->getStatus());

		$this->assertNotContains('old', $this->worldGroupMembers['intakekanalen-intake']);
		$this->assertContains('shared', $this->worldGroupMembers['intakekanalen-intake'], 'messaging still acts as shared');
		$this->assertContains('new', $this->worldGroupMembers['intakekanalen-intake']);

	}//end testThePreviousAccountLeavesUnlessAnotherChannelUsesIt()

	/**
	 * GET names the handler group of the intake channels and the verdicts, and
	 * says whether it is empty; a webhook without one carries null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#scenario-an-empty-handler-group-is-announced-on-the-webhook
	 */
	public function testGetSaysWhetherTheHandlerGroupIsEmpty(): void {
		$this->addAccount(uid: 'handler', grants: []);
		$this->worldGroupMembers = ['verdicts-behandelaars' => ['handler']];

		$rows = [];
		foreach ($this->controller()->getConfig()->getData()['connections'] as $row) {
			$rows[$row['authorizationType']] = $row['handlerGroup'];
		}

		$this->assertSame(['id' => 'intakekanalen-behandelaars', 'empty' => true], $rows['intake-channel-form-submission']);
		$this->assertSame(['id' => 'verdicts-behandelaars', 'empty' => false], $rows['intake-channel-verdicts']);
		$this->assertNull($rows['rod-webhook']);

	}//end testGetSaysWhetherTheHandlerGroupIsEmpty()
}//end class
