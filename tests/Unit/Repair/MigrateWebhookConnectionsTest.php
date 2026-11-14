<?php

/**
 * The repair step that moves each webhook's source trust into its consumer.
 *
 * Runs the real WebhookTrustMigrator over the connection world, so the
 * source read and the consumer write go through the same fake OpenRegister
 * the webhooks use.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateWebhookConnections;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\Integriq\Service\Intake\WebhookTrustMigratorInterface;
use OCA\Integriq\Service\Intake\WebhookTrustMigrator;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Each webhook gets one consumer, from its own source, with no account.
 */
class MigrateWebhookConnectionsTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The alerts raised, as `channel/reason`.
	 *
	 * @var list<string>
	 */
	private array $alerted = [];

	/**
	 * A fresh world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->alerted = [];

	}//end setUp()

	/**
	 * A source with a webhook secret becomes its webhook's consumer, without an account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
	 */
	public function testEachSourceTrustBecomesItsWebhooksConsumer(): void {
		$this->addSource(type: 'peppol', secret: 'whsec_peppol');
		$this->addSource(type: 'rod', secret: 'whsec_rod', header: 'X-Rod-Signature');
		$this->addSource(type: 'intake-channel', secret: 'whsec_form', channelId: 'form-submission');
		$this->addSource(type: 'intake-channel', secret: 'whsec_verdicts', channelId: 'verdicts');

		$this->step()->run($this->createMock(IOutput::class));

		$byType = [];
		foreach ($this->worldConsumers as $consumer) {
			$byType[$consumer['authorizationType']] = $consumer;
		}

		ksort($byType);
		$this->assertSame(['intake-channel-form-submission', 'intake-channel-verdicts', 'peppol-webhook', 'rod-webhook'], array_keys($byType));
		$this->assertSame('whsec_peppol', $byType['peppol-webhook']['authorizationConfiguration']['secret']);
		$this->assertSame('X-OpenConnector-Signature', $byType['peppol-webhook']['authorizationConfiguration']['header']);
		$this->assertSame('X-Rod-Signature', $byType['rod-webhook']['authorizationConfiguration']['header']);
		$this->assertSame('whsec_form', $byType['intake-channel-form-submission']['authorizationConfiguration']['secret']);
		$this->assertSame('whsec_verdicts', $byType['intake-channel-verdicts']['authorizationConfiguration']['secret']);
		$this->assertSame(['', '', '', ''], array_column($byType, 'userId'), 'no account is guessed');
		$this->assertSame([true, true, true, true], array_column($this->worldWrites, 'system'), 'the writes are system operations');
		sort($this->alerted);
		$this->assertSame(
			['intakeformsubmission/choose_account', 'intakeverdicts/choose_account', 'peppol/choose_account', 'rod/choose_account'],
			$this->alerted
		);

	}//end testEachSourceTrustBecomesItsWebhooksConsumer()

	/**
	 * A second run, an existing consumer, a disabled source and a source without a secret create nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
	 */
	public function testItIsIdempotentAndSkipsUnusableSources(): void {
		$this->addSource(type: 'peppol', secret: 'whsec_peppol');
		$this->addSource(type: 'sms', secret: '');
		$this->addSource(type: 'oso', secret: 'whsec_off', enabled: false);
		$this->worldConsumers['existing'] = ['name' => 'ROD', 'authorizationType' => 'rod-webhook', 'userId' => 'rod-acc'];
		$this->addSource(type: 'rod', secret: 'whsec_rod');

		$step = $this->step();
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$types = array_column($this->worldConsumers, 'authorizationType');
		sort($types);
		$this->assertSame(['peppol-webhook', 'rod-webhook'], $types);
		$this->assertSame('rod-acc', $this->worldConsumers['existing']['userId'], 'an existing consumer is never touched');
		$this->assertSame(['peppol/choose_account'], $this->alerted);

	}//end testItIsIdempotentAndSkipsUnusableSources()

	/**
	 * An admin-only source with a webhook secret.
	 *
	 * @param string      $type      The source type.
	 * @param string      $secret    The webhook secret, or ''.
	 * @param string|null $header    The signature header, or null for the default.
	 * @param string|null $channelId The intake channel id, or null.
	 * @param bool        $enabled   Whether the source is enabled.
	 *
	 * @return void
	 */
	private function addSource(string $type, string $secret, ?string $header = null, ?string $channelId = null, bool $enabled = true): void {
		$signature = ['scheme' => 'openconnector', 'secret' => $secret];
		if ($header !== null) {
			$signature['header'] = $header;
		}

		$configuration = ['webhookSignature' => $signature];
		if ($channelId !== null) {
			$configuration['channelId'] = $channelId;
		}

		$this->addOther(
			schema: 'source',
			uuid: 'source-' . $type . '-' . count($this->worldOthers),
			data: ['name' => $type, 'type' => $type, 'isEnabled' => $enabled, 'configuration' => $configuration]
		);

	}//end addSource()

	/**
	 * The step over the world.
	 *
	 * @return MigrateWebhookConnections
	 */
	private function step(): MigrateWebhookConnections {
		$objectService = $this->buildWorldObjectService();

		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->alerted[] = $channel . '/' . $reason;
				return true;
			}
		);

		$migrator = new WebhookTrustMigrator(
			webhooks: $this->buildWorldWebhookConnection(objectService: $objectService),
			objectService: $objectService,
			alerts: $alerts
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($migrator);

		return new MigrateWebhookConnections(container: $container, webhookProfiles: new WebhookProfiles());

	}//end step()
	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testItIsNamed(): void {
		$step = new MigrateWebhookConnections(container: $this->createMock(ContainerInterface::class), webhookProfiles: new WebhookProfiles());

		$this->assertSame('Move the trust of the signed public webhooks into their consumers', $step->getName());

	}//end testItIsNamed()

	/**
	 * Without OpenRegister the step warns and migrates nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepIsSkipped(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no migrator'));
		$profiles = $this->createMock(WebhookProfilesInterface::class);
		$profiles->expects($this->never())->method('profiles');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Webhook connection migration skipped: OpenRegister is not available (no migrator).');
		$output->expects($this->never())->method('info');

		(new MigrateWebhookConnections(container: $container, webhookProfiles: $profiles))->run($output);

	}//end testWithoutOpenRegisterTheStepIsSkipped()

	/**
	 * A webhook whose migration fails is reported and the next one is still
	 * migrated; only a created consumer asks for an account.
	 *
	 * @return void
	 */
	public function testAFailedMigrationIsReportedAndTheRestContinue(): void {
		$broken = new WebhookProfile(authorizationType: 'peppol-webhook', channel: 'peppol', label: 'Peppol', schema: 'peppol_message');
		$created = new WebhookProfile(authorizationType: 'rod-webhook', channel: 'rod', label: 'ROD', schema: 'rod_message');
		$existing = new WebhookProfile(authorizationType: 'sms-webhook', channel: 'sms', label: 'SMS', schema: 'sms_message');
		$profiles = $this->createMock(WebhookProfilesInterface::class);
		$profiles->method('profiles')->willReturn([$broken, $created, $existing]);
		$migrator = $this->createMock(WebhookTrustMigratorInterface::class);
		$migrator->expects($this->exactly(3))->method('migrate')->willReturnCallback(
			static function (WebhookProfile $profile): bool {
				if ($profile->label === 'Peppol') {
					throw new \RuntimeException('source unreadable');
				}

				return $profile->label === 'ROD';
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(WebhookTrustMigratorInterface::class)->willReturn($migrator);
		$lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $m) use (&$lines): void {
			$lines[] = 'warning: ' . $m;
		});
		$output->method('info')->willReturnCallback(static function (string $m) use (&$lines): void {
			$lines[] = 'info: ' . $m;
		});

		(new MigrateWebhookConnections(container: $container, webhookProfiles: $profiles))->run($output);

		$this->assertSame(
			[
				'warning: The Peppol connection was not migrated: source unreadable',
				'info: Created the rod-webhook consumer from the source webhook trust. Choose the account the ROD webhook acts as.',
			],
			$lines
		);

	}//end testAFailedMigrationIsReportedAndTheRestContinue()
}//end class
