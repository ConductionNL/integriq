<?php

/**
 * ExchangeController: the job, rejection and target reads, and the refusals
 * every endpoint shares.
 *
 * Every endpoint answers 401 without a session and 403 without its action,
 * before anything is read or written; the reads need the owning app and pass
 * its filters and paging on. The read model, the rejection service and the
 * action check are doubles.
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

use OCA\Integriq\Controller\ExchangeController;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeReadModelInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Controller\ExchangeController
 */
class ExchangeControllerReadsTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Whether a user is signed in.
	 *
	 * @var bool
	 */
	private bool $signedIn = true;

	/**
	 * The read model.
	 *
	 * @var ExchangeReadModelInterface&MockObject
	 */
	private ExchangeReadModelInterface&MockObject $readModel;

	/**
	 * The rejection service.
	 *
	 * @var ExchangeRejectionServiceInterface&MockObject
	 */
	private ExchangeRejectionServiceInterface&MockObject $rejections;

	/**
	 * The action check.
	 *
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface&MockObject $actionAuth;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->params     = [];
		$this->signedIn   = true;
		$this->readModel  = $this->createMock(ExchangeReadModelInterface::class);
		$this->rejections = $this->createMock(ExchangeRejectionServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);

	}//end setUp()

	/**
	 * The controller over the doubles.
	 *
	 * @return ExchangeController
	 */
	private function controller(): ExchangeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->signedIn === true ? $user : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ExchangeController('integriq', $request, $this->readModel, $this->rejections, $this->actionAuth, $session, $l10n);

	}//end controller()

	/**
	 * Every endpoint, called once.
	 *
	 * @return array<string, callable(ExchangeController): \OCP\AppFramework\Http\JSONResponse>
	 */
	private static function endpoints(): array {
		return [
			'jobs' => fn (ExchangeController $c) => $c->jobs(),
			'job' => fn (ExchangeController $c) => $c->job('j1'),
			'rejections' => fn (ExchangeController $c) => $c->rejections(),
			'targets' => fn (ExchangeController $c) => $c->targets(),
			'resubmit' => fn (ExchangeController $c) => $c->resubmit('r1'),
			'waive' => fn (ExchangeController $c) => $c->waive('r1'),
		];

	}//end endpoints()

	public function testEveryEndpointRefusesACallerWithoutASession(): void {
		$this->signedIn = false;
		$this->params   = ['ownerApp' => 'learniq', 'reason' => 'r'];
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->readModel->expects($this->never())->method($this->anything());
		$this->rejections->expects($this->never())->method($this->anything());

		foreach (self::endpoints() as $name => $call) {
			$response = $call($this->controller());
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $name);
			$this->assertSame(['error' => 'Not authenticated'], $response->getData(), $name);
		}

	}//end testEveryEndpointRefusesACallerWithoutASession()

	public function testEveryEndpointRefusesAUserWithoutItsAction(): void {
		$this->params = ['ownerApp' => 'learniq', 'reason' => 'r'];
		$asked        = [];
		$this->actionAuth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use (&$asked): void {
				$asked[] = $action;
				throw new OCSForbiddenException('Missing ' . $action);
			}
		);
		$this->readModel->expects($this->never())->method($this->anything());
		$this->rejections->expects($this->never())->method($this->anything());

		foreach (self::endpoints() as $name => $call) {
			$response = $call($this->controller());
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $name);
		}

		$this->assertSame(
			['exchange.read', 'exchange.read', 'exchange.read', 'exchange.read', 'exchange.resubmit', 'exchange.waive'],
			$asked
		);

	}//end testEveryEndpointRefusesAUserWithoutItsAction()

	public function testTheJobAndRejectionReadsNeedTheOwningApp(): void {
		$this->readModel->expects($this->never())->method($this->anything());

		foreach (['job' => fn ($c) => $c->job('j1'), 'rejections' => fn ($c) => $c->rejections()] as $name => $call) {
			$response = $call($this->controller());
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $name);
			$this->assertSame(['error' => 'The ownerApp parameter is required'], $response->getData(), $name);
		}

	}//end testTheJobAndRejectionReadsNeedTheOwningApp()

	public function testAJobIsReadForItsOwnerOr404(): void {
		$this->params = ['ownerApp' => 'learniq'];
		$this->readModel->expects($this->exactly(2))->method('getJob')
			->with('learniq', 'j1')
			->willReturnOnConsecutiveCalls(['id' => 'j1', 'status' => 'done'], null);

		$this->assertSame(['id' => 'j1', 'status' => 'done'], $this->controller()->job('j1')->getData());

		$missing = $this->controller()->job('j1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Exchange job not found'], $missing->getData());

	}//end testAJobIsReadForItsOwnerOr404()

	public function testRejectionsAreListedWithTheirFiltersAndPaging(): void {
		$this->params = ['ownerApp' => 'learniq', 'status' => 'open', 'target' => 'duo', 'jobId' => 'j1', 'limit' => '10', 'offset' => '20'];
		$this->readModel->expects($this->once())->method('listRejections')
			->with('learniq', ['status' => 'open', 'target' => 'duo', 'jobId' => 'j1'], 10, 20)
			->willReturn(['results' => [['id' => 'r1']], 'total' => 1]);

		$this->assertSame(['results' => [['id' => 'r1']], 'total' => 1], $this->controller()->rejections()->getData());

	}//end testRejectionsAreListedWithTheirFiltersAndPaging()

	public function testRejectionsDefaultToTheFirstFifty(): void {
		$this->params = ['ownerApp' => 'learniq'];
		$this->readModel->expects($this->once())->method('listRejections')
			->with('learniq', ['status' => '', 'target' => '', 'jobId' => ''], 50, 0)
			->willReturn([]);

		$this->controller()->rejections();

	}//end testRejectionsDefaultToTheFirstFifty()

	public function testTheTargetsAreListed(): void {
		$this->readModel->method('targets')->willReturn([['id' => 'duo']]);

		$this->assertSame(['results' => [['id' => 'duo']]], $this->controller()->targets()->getData());

	}//end testTheTargetsAreListed()

	public function testAWaiveInTheWrongStateIsAConflict(): void {
		$this->params = ['reason' => 'Handled by phone'];
		$entry        = new ObjectEntity();
		$this->rejections->method('find')->with('r1')->willReturn($entry);
		$this->rejections->method('waive')->with($entry, 'coordinator', 'Handled by phone')
			->willThrowException(new InvalidMessageStateException('Already waived'));

		$response = $this->controller()->waive('r1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Already waived'], $response->getData());

	}//end testAWaiveInTheWrongStateIsAConflict()
}//end class
