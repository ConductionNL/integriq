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
}//end class
