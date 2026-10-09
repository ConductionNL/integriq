<?php

/**
 * ExchangeController: session, action and owner checks.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ExchangeController;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
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
 * REQ-006 and REQ-008 scenarios.
 */
class ExchangeControllerTest extends TestCase {

	/**
	 * @var ExchangeReadModel&MockObject
	 */
	private $readModel;

	/**
	 * @var ExchangeRejectionService&MockObject
	 */
	private $rejections;

	/**
	 * @var ActionAuthService&MockObject
	 */
	private $actionAuth;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Whether a user is logged in.
	 *
	 * @var bool
	 */
	private bool $loggedIn = true;

	/**
	 * Build the controller.
	 *
	 * @return ExchangeController The controller.
	 */
	private function controller(): ExchangeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->loggedIn ? $user : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ExchangeController('integriq', $request, $this->readModel, $this->rejections, $this->actionAuth, $session, $l10n);

	}//end controller()

	/**
	 * Set up doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->readModel = $this->createMock(ExchangeReadModel::class);
		$this->rejections = $this->createMock(ExchangeRejectionService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);

	}//end setUp()

	/**
	 * A user without the action gets 403 and nothing is read.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheAction(): void {
		$this->params = ['ownerApp' => 'learniq'];
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));
		$this->readModel->expects($this->never())->method('listJobs');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->jobs()->getStatus());

	}//end testAUserWithoutTheAction()

	/**
	 * Without a session: 401.
	 *
	 * @return void
	 */
	public function testWithoutASession(): void {
		$this->loggedIn = false;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->targets()->getStatus());

	}//end testWithoutASession()

	/**
	 * Reads require ownerApp and pass the filters through.
	 *
	 * @return void
	 */
	public function testReadsRequireAnOwnerApp(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->jobs()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->rejections()->getStatus());

		$this->params = ['ownerApp' => 'learniq', 'status' => 'failed', 'limit' => '500'];
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->anything(), 'exchange.read');
		$this->readModel->expects($this->once())->method('listJobs')
			->with('learniq', ['target' => '', 'status' => 'failed', 'ownerRef' => ''], 500, 0)
			->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->jobs()->getStatus());

	}//end testReadsRequireAnOwnerApp()

	/**
	 * Another app's job answers 404.
	 *
	 * @return void
	 */
	public function testAnotherAppsJob(): void {
		$this->params = ['ownerApp' => 'learniq'];
		$this->readModel->method('getJob')->with('learniq', 'job-of-dossiq')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->job('job-of-dossiq')->getStatus());

	}//end testAnotherAppsJob()

	/**
	 * Waive without a reason is 400; a waived one again is 409.
	 *
	 * @return void
	 */
	public function testWaiveWithoutAReason(): void {
		$this->actionAuth->expects($this->atLeastOnce())->method('requireAction')->with($this->anything(), 'exchange.waive');
		$this->rejections->expects($this->never())->method('waive');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->waive('rej-1')->getStatus());

	}//end testWaiveWithoutAReason()

	/**
	 * Waive of an unknown rejection is 404; of a discarded one is 409.
	 *
	 * @return void
	 */
	public function testWaiveUnknownAndConflict(): void {
		$this->params = ['reason' => 'Left the school.'];
		$this->rejections->method('find')->willReturnOnConsecutiveCalls(null, new ObjectEntity());
		$this->rejections->method('waive')->willThrowException(new InvalidMessageStateException('already discarded'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->waive('rej-x')->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->waive('rej-1')->getStatus());

	}//end testWaiveUnknownAndConflict()

	/**
	 * Resubmit answers with both ids, and 404 or 409 on the service's refusals.
	 *
	 * @return void
	 */
	public function testResubmit(): void {
		$this->actionAuth->expects($this->atLeastOnce())->method('requireAction')->with($this->anything(), 'exchange.resubmit');
		$this->rejections->method('resubmit')->willReturnCallback(
			static function (string $rejectionId, string $actor): array {
				if ($rejectionId === 'unknown') {
					throw new \InvalidArgumentException('No such rejection');
				}

				if ($rejectionId === 'replayed') {
					throw new InvalidMessageStateException('not failed');
				}

				return ['rejection' => new ObjectEntity(), 'rejectionId' => $rejectionId, 'jobId' => 'job-new'];
			}
		);

		$ok = $this->controller()->resubmit('rej-1');
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame(['rejectionId' => 'rej-1', 'jobId' => 'job-new'], $ok->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->resubmit('unknown')->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->resubmit('replayed')->getStatus());

	}//end testResubmit()
}//end class
