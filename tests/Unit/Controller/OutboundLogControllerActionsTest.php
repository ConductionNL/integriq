<?php

/**
 * Unit tests for OutboundLogController's body, retry, forward and last-contact actions.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\OutboundLogController;
use OCA\Integriq\Outbound\ForwardServiceInterface;
use OCA\Integriq\Outbound\LastContactQueryInterface;
use OCA\Integriq\Outbound\MessageBodyReaderInterface;
use OCA\Integriq\Outbound\OutboundRetryServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading a body, retrying messages, forwarding one and asking for the last contact.
 *
 * @covers \OCA\Integriq\Controller\OutboundLogController
 */
final class OutboundLogControllerActionsTest extends TestCase {

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var MessageBodyReaderInterface&MockObject */
	private MessageBodyReaderInterface $bodies;

	/** @var OutboundRetryServiceInterface&MockObject */
	private OutboundRetryServiceInterface $retries;

	/** @var ForwardServiceInterface&MockObject */
	private ForwardServiceInterface $forwards;

	/** @var LastContactQueryInterface&MockObject */
	private LastContactQueryInterface $lastContact;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->actionAuth  = $this->createMock(ActionAuthServiceInterface::class);
		$this->bodies      = $this->createMock(MessageBodyReaderInterface::class);
		$this->retries     = $this->createMock(OutboundRetryServiceInterface::class);
		$this->forwards    = $this->createMock(ForwardServiceInterface::class);
		$this->lastContact = $this->createMock(LastContactQueryInterface::class);
		$user              = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$this->user = $user;
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return OutboundLogController
	 */
	private function controller(array $params = []): OutboundLogController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new OutboundLogController('integriq', $request, $session, $this->actionAuth, $this->bodies, $this->retries, $this->forwards, $this->lastContact, $l);
	}//end controller()

	/**
	 * Retry, forward and last contact refuse without a signed-in user.
	 *
	 * @return void
	 */
	public function testActionsRefuseWithoutAUser(): void {
		$this->user = null;
		$this->actionAuth->expects($this->never())->method('requireAction');
		$controller = $this->controller(['subjectRef' => 's', 'recipient' => 'r']);

		foreach ([$controller->retry('m-1'), $controller->forward('m-1'), $controller->lastContact()] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testActionsRefuseWithoutAUser()

	/**
	 * A body is read for the signed-in user; an unknown message answers 404.
	 *
	 * @return void
	 */
	public function testBodyIsReadForTheUser(): void {
		$this->bodies->method('read')->willReturnCallback(function (string $id, IUser $user): array {
			$this->assertSame($this->user, $user);
			if ($id === 'nope') {
				throw new DoesNotExistException('gone');
			}
			return ['body' => 'hello'];
		});
		$controller = $this->controller();

		$this->assertSame(['body' => 'hello'], $controller->body('m-1')->getData());
		$missing = $controller->body('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'No such message.'], $missing->getData());
	}//end testBodyIsReadForTheUser()

	/**
	 * Retrying one message needs the retry action and retries it as the user.
	 *
	 * @return void
	 */
	public function testRetryOfOneMessage(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, OutboundLogController::ACTION_RETRY);
		$this->retries->expects($this->once())->method('retryAll')->with(['m-1'], 'ann')->willReturn(['m-1' => 'queued']);

		$this->assertSame(['m-1' => 'queued'], $this->controller(['messages' => ['other']])->retry('m-1')->getData());
	}//end testRetryOfOneMessage()

	/**
	 * Retrying a batch reads the message ids from the request as strings.
	 *
	 * @return void
	 */
	public function testRetryOfABatch(): void {
		$this->retries->expects($this->once())->method('retryAll')->with(['5', 'm-2'], 'ann')->willReturn([]);

		$this->controller(['messages' => [5, 'm-2']])->retry();
	}//end testRetryOfABatch()

	/**
	 * A retry without messages answers 400, an unknown message 404.
	 *
	 * @return void
	 */
	public function testRetryFailures(): void {
		foreach ([[], ['messages' => []], ['messages' => 'm-1']] as $params) {
			$response = $this->controller($params)->retry();
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'Name the messages to retry.'], $response->getData());
		}

		$this->retries->method('retryAll')->willThrowException(new DoesNotExistException('gone'));
		$this->assertSame(404, $this->controller()->retry('nope')->getStatus());
	}//end testRetryFailures()

	/**
	 * Forwarding needs the forward action and answers 201 with the forward record.
	 *
	 * @return void
	 */
	public function testForwardRecordsTheForward(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, OutboundLogController::ACTION_FORWARD);
		$this->forwards->expects($this->once())->method('forward')
			->with('m-1', ['bob@example.org'], 'ann', 'FYI', 'email')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['kind' => 'forward'], 'f-1'));

		$response = $this->controller(['recipients' => ['bob@example.org'], 'note' => 'FYI', 'channel' => 'email'])->forward('m-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'f-1', 'message' => ['kind' => 'forward']], $response->getData());
	}//end testForwardRecordsTheForward()

	/**
	 * Recipients that are not a list are sent as none; no channel and no note are sent as such.
	 *
	 * @return void
	 */
	public function testForwardDefaults(): void {
		$this->forwards->expects($this->once())->method('forward')
			->with('m-1', [], 'ann', '', null)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'f-2'));

		$this->controller(['recipients' => 'bob@example.org'])->forward('m-1');
	}//end testForwardDefaults()

	/**
	 * A refused forward answers 400 with the reason, an unknown message 404.
	 *
	 * @return void
	 */
	public function testForwardFailures(): void {
		$this->forwards->method('forward')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('No recipients')),
			$this->throwException(new DoesNotExistException('gone'))
		);
		$controller = $this->controller();

		$refused = $controller->forward('m-1');
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame(['error' => 'No recipients'], $refused->getData());

		$missing = $controller->forward('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'No such message.'], $missing->getData());
	}//end testForwardFailures()

	/**
	 * The last contact with a recipient about a subject is answered from the query.
	 *
	 * @return void
	 */
	public function testLastContactAsksTheQuery(): void {
		$this->lastContact->expects($this->once())->method('lastContact')->with('zaak-1', 'bob')->willReturn(['at' => '2026-01-01']);

		$this->assertSame(['at' => '2026-01-01'], $this->controller(['subjectRef' => 'zaak-1', 'recipient' => 'bob'])->lastContact()->getData());
	}//end testLastContactAsksTheQuery()
}//end class
