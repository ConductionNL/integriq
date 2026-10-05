<?php

/**
 * The connection notifier renders the text of the intake that raised it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Notification;

use OCA\Integriq\Notification\DsoConnectionNotifier;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * DsoConnectionNotifier::prepare() per channel.
 */
class DsoConnectionNotifierTest extends TestCase {

	/**
	 * Prepare a connection alert and return its parsed subject.
	 *
	 * @param array<string, string> $parameters The subject parameters.
	 *
	 * @return string The parsed subject.
	 */
	private function render(array $parameters): string {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc.example/settings/admin/integriq');
		$urls->method('imagePath')->willReturn('/apps/integriq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$parsed = '';
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('integriq');
		$notification->method('getSubject')->willReturn(DsoConnectionAlerts::SUBJECT);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $subject) use (&$parsed, $notification): INotification {
				$parsed = $subject;
				return $notification;
			}
		);
		$notification->method('setLink')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		(new DsoConnectionNotifier(l10nFactory: $factory, urlGenerator: $urls))->prepare($notification, 'nl');

		return $parsed;

	}//end render()

	/**
	 * An Open Formulieren alert names Open Formulieren, not DSO-LV.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testAnOpenFormulierenAlertNamesOpenFormulieren(): void {
		$this->assertSame(
			'Open Formulieren submissions are refused: the Open Formulieren connection has no usable account.',
			$this->render(['reason' => 'no_account', 'channel' => 'openformulieren'])
		);
		$this->assertSame(
			'Choose the account the Open Formulieren intake acts as.',
			$this->render(['reason' => 'choose_account', 'channel' => 'openformulieren'])
		);
		$this->assertSame(
			'An Open Formulieren submission could not be stored. Open Formulieren will deliver it again.',
			$this->render(['reason' => 'submission_not_stored', 'channel' => 'openformulieren'])
		);

	}//end testAnOpenFormulierenAlertNamesOpenFormulieren()

	/**
	 * An alert without a channel (every alert queued before this change) stays a DSO alert.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testAnAlertWithoutAChannelIsADsoAlert(): void {
		$this->assertSame(
			'DSO-LV pushes are refused: the DSO connection has no usable account.',
			$this->render(['reason' => 'no_account'])
		);

	}//end testAnAlertWithoutAChannelIsADsoAlert()

	/**
	 * An alert of a webhook on the consumer model names that webhook.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-connection-without-a-usable-account-refuses-with-503
	 */
	public function testAWebhookAlertNamesTheWebhook(): void {
		$this->assertSame(
			'Peppol deliveries are refused: the Peppol connection has no usable account.',
			$this->render(['reason' => 'no_account', 'channel' => 'peppol'])
		);
		$this->assertSame(
			'Choose the account the StUF-ZKN webhook acts as.',
			$this->render(['reason' => 'choose_account', 'channel' => 'stufzkn'])
		);
		$this->assertSame(
			'A Verdicts delivery could not be stored. The sender will deliver it again.',
			$this->render(['reason' => 'delivery_not_stored', 'channel' => 'intakeverdicts'])
		);

	}//end testAWebhookAlertNamesTheWebhook()
}//end class
