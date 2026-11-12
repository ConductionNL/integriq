<?php

/**
 * Unit tests for ApprovalNotifier.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Notification;

use OCA\Integriq\Notification\ApprovalNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalNotifier renders the HITL approval-pending notification and
 * refuses every notification that is not its own.
 *
 * @covers \OCA\Integriq\Notification\ApprovalNotifier
 */
class ApprovalNotifierTest extends TestCase {
	/**
	 * Language factory mock.
	 *
	 * @var IFactory&MockObject
	 */
	private IFactory&MockObject $factory;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urls;

	/**
	 * Subject under test.
	 *
	 * @var ApprovalNotifier
	 */
	private ApprovalNotifier $notifier;

	/**
	 * Build the notifier with an IL10N that marks and formats translations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => '[t]' . vsprintf($text, $params)
		);
		$this->factory = $this->createMock(IFactory::class);
		$this->factory->method('get')->willReturn($l10n);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('imagePath')->willReturn('/apps/integriq/img/app-dark.svg');
		$this->urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example' . $path
		);
		$this->notifier = new ApprovalNotifier(l10nFactory: $this->factory, urlGenerator: $this->urls);
	}//end setUp()

	/**
	 * Build a notification mock.
	 *
	 * @param string $app     App id
	 * @param string $subject Subject
	 * @param array  $params  Subject parameters
	 * @param array  $actions Actions
	 *
	 * @return INotification&MockObject
	 */
	private function notification(string $app, string $subject, array $params = [], array $actions = []): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getActions')->willReturn($actions);
		return $notification;
	}//end notification()

	/**
	 * Build an action mock with the given raw label that expects its parsed label.
	 *
	 * @param string $label  Raw label
	 * @param string $parsed Expected parsed label
	 *
	 * @return IAction&MockObject
	 */
	private function action(string $label, string $parsed): IAction&MockObject {
		$action = $this->createMock(IAction::class);
		$action->method('getLabel')->willReturn($label);
		$action->expects($this->once())->method('setParsedLabel')->with($parsed)->willReturnSelf();
		return $action;
	}//end action()

	/**
	 * The notifier is registered under the app id with the translated app name.
	 *
	 * @return void
	 */
	public function testIdAndName(): void {
		$this->assertSame('integriq', $this->notifier->getID());
		$this->assertSame('[t]Integriq', $this->notifier->getName());
	}//end testIdAndName()

	/**
	 * A notification of another app is refused before any translation.
	 *
	 * @return void
	 */
	public function testForeignAppIsRefused(): void {
		$notification = $this->notification('files', 'approval_pending');
		$notification->expects($this->never())->method('setParsedSubject');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'nl');
	}//end testForeignAppIsRefused()

	/**
	 * Another subject of this app is refused too.
	 *
	 * @return void
	 */
	public function testOtherSubjectIsRefused(): void {
		$notification = $this->notification('integriq', 'connection_failed');
		$notification->expects($this->never())->method('setParsedSubject');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'nl');
	}//end testOtherSubjectIsRefused()

	/**
	 * The subject names the approver group in plain and rich form, in the requested language.
	 *
	 * @return void
	 */
	public function testSubjectNamesTheApproverGroup(): void {
		$factory = $this->createMock(IFactory::class);
		$l10n    = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory->expects($this->once())->method('get')->with('integriq', 'de')->willReturn($l10n);
		$notifier = new ApprovalNotifier(l10nFactory: $factory, urlGenerator: $this->urls);

		$notification = $this->notification('integriq', 'approval_pending', ['approverGroup' => 'finance']);
		$notification->expects($this->once())->method('setParsedSubject')->with('Approval requested (finance)');
		$notification->expects($this->once())->method('setRichSubject')->with(
			'Approval requested ({group})',
			['group' => ['type' => 'highlight', 'id' => 'finance', 'name' => 'finance']]
		);
		$notification->expects($this->once())->method('setIcon')->with('https://nc.example/apps/integriq/img/app-dark.svg');

		$this->assertSame($notification, $notifier->prepare($notification, 'de'));
	}//end testSubjectNamesTheApproverGroup()

	/**
	 * A missing approver group renders as an empty group, not an error.
	 *
	 * @return void
	 */
	public function testMissingGroupRendersEmpty(): void {
		$notification = $this->notification('integriq', 'approval_pending');
		$notification->expects($this->once())->method('setParsedSubject')->with('[t]Approval requested ()');
		$notification->expects($this->once())->method('setRichSubject')->with(
			'[t]Approval requested ({group})',
			['group' => ['type' => 'highlight', 'id' => '', 'name' => '']]
		);

		$this->notifier->prepare($notification, 'en');
	}//end testMissingGroupRendersEmpty()

	/**
	 * The approve action is labelled Approve; every other action is labelled Reject.
	 *
	 * @return void
	 */
	public function testActionsAreLabelledApproveOrReject(): void {
		$approve = $this->action('approve', '[t]Approve');
		$reject  = $this->action('reject', '[t]Reject');
		$other   = $this->action('defer', '[t]Reject');

		$notification = $this->notification('integriq', 'approval_pending', ['approverGroup' => 'g'], [$approve, $reject, $other]);
		$parsed       = [];
		$notification->expects($this->exactly(3))->method('addParsedAction')->willReturnCallback(
			function (IAction $action) use (&$parsed, $notification) {
				$parsed[] = $action;
				return $notification;
			}
		);

		$this->notifier->prepare($notification, 'en');

		$this->assertSame([$approve, $reject, $other], $parsed);
	}//end testActionsAreLabelledApproveOrReject()
}//end class
