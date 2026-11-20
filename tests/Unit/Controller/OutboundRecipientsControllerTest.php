<?php

/**
 * Unit tests for OutboundRecipientsController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OutboundRecipientsController;
use OCA\Integriq\Outbound\RecipientResolver;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the preview is the send's own resolution and leaves no trace.
 *
 * @covers \OCA\Integriq\Controller\OutboundRecipientsController
 * @uses   \OCA\Integriq\Outbound\RecipientResolver
 * @uses   \OCA\Integriq\Outbound\RecipientSelectionRefusedException
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
 */
class OutboundRecipientsControllerTest extends TestCase {

	/**
	 * A preview lists the three parts and the resolved list, with the suppression's reason.
	 *
	 * @return void
	 */
	public function testAPreviewListsWhoWillReceiveIt(): void {
		$response = $this->controller(
			params: [
				'standing' => ['aanvrager@example.org', 'buur@example.org'],
				'added' => [['address' => 'gemachtigde@example.org']],
				'suppressed' => [['address' => 'buur@example.org', 'reason' => 'Geen belanghebbende meer']],
			]
		)->preview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['aanvrager@example.org', 'gemachtigde@example.org'], array_column($data['resolved'], 'address'));
		$this->assertSame('Geen belanghebbende meer', $data['suppressed'][0]['reason']);
		$this->assertSame('handler', $data['suppressed'][0]['user']);
		$this->assertCount(2, $data['standing']);

	}//end testAPreviewListsWhoWillReceiveIt()

	/**
	 * A refused decision answers 422 with the refusal, naming the recipient.
	 *
	 * @return void
	 */
	public function testARefusedDecisionAnswers422(): void {
		$response = $this->controller(
			params: [
				'standing' => [['address' => 'aanvrager@example.org', 'required' => true, 'requiredBy' => 'Awb 3:41']],
				'suppressed' => [['address' => 'aanvrager@example.org', 'reason' => 'Niet nodig']],
			]
		)->preview();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(RecipientResolver::REFUSED_REQUIRED, $response->getData()['code']);
		$this->assertSame('aanvrager@example.org', $response->getData()['address']);
		$this->assertStringContainsString('Awb 3:41', $response->getData()['error']);

	}//end testARefusedDecisionAnswers422()

	/**
	 * Without a session the preview is refused.
	 *
	 * @return void
	 */
	public function testWithoutASessionThePreviewIsRefused(): void {
		$response = $this->controller(params: [], user: false)->preview();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testWithoutASessionThePreviewIsRefused()

	/**
	 * The controller over a request carrying the given parameters.
	 *
	 * @param array<string,mixed> $params The request body.
	 * @param bool                $user   Whether a user is logged in.
	 *
	 * @return OutboundRecipientsController The controller.
	 */
	private function controller(array $params, bool $user = true): OutboundRecipientsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$account = null;
		if ($user === true) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn('handler');
		}

		$session->method('getUser')->willReturn($account);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new OutboundRecipientsController('integriq', $request, $session, new RecipientResolver($l10n), $l10n);

	}//end controller()
}//end class
