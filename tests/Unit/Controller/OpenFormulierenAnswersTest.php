<?php

/**
 * Characterisation tests for OpenFormulierenController's inbound payload
 * reading and its status and handoff answers.
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

use OCA\Integriq\Controller\OpenFormulierenController;
use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every inbound payload reading and every status/handoff answer body.
 */
class OpenFormulierenAnswersTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest
	 */
	private $request;

	/**
	 * The intake service double.
	 *
	 * @var OpenFormulierenIntakeService
	 */
	private $intake;

	/**
	 * The connection double.
	 *
	 * @var OpenFormulierenConnection
	 */
	private $connection;

	/**
	 * The session double.
	 *
	 * @var IUserSession
	 */
	private $session;

	/**
	 * Every action required.
	 *
	 * @var list<string>
	 */
	private array $actions = [];

	/**
	 * Every error logged.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->intake = $this->createMock(OpenFormulierenIntakeService::class);
		$this->connection = $this->createMock(OpenFormulierenConnection::class);
		$this->session = $this->createMock(IUserSession::class);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return OpenFormulierenController
	 */
	private function controller(): OpenFormulierenController {
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function ($user, string $action): void {
				$this->actions[] = $action;
			}
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new OpenFormulierenController('integriq', $this->request, $this->intake, $this->connection, $this->createMock(DsoConnectionAlerts::class), $this->session, $auth, $l, $logger);

	}//end controller()

	/**
	 * A signed-in caller.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));

	}//end signedIn()

	/**
	 * Authenticate as the connection and run the ingest in place.
	 *
	 * @return DsoIdentity
	 */
	private function authenticated(): DsoIdentity {
		$identity = new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'consumer-of');
		$this->connection->method('authenticate')->willReturn($identity);
		$this->connection->method('runAs')->willReturnCallback(static fn ($account, callable $operation): mixed => $operation());

		return $identity;

	}//end authenticated()

	/**
	 * The form uuid is read as a string when present, the auth block only
	 * when it is an array, and the stored record answers with its id.
	 *
	 * @return void
	 */
	public function testTheInboundPayloadIsReadAndTheRecordAnswered(): void {
		$identity = $this->authenticated();
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['form' => ['slug' => 's', 'uuid' => 42], 'auth' => ['bsn' => '1'], 'submission' => 'x', 'values' => 'v', 'attachments' => ['a']],
			['form' => ['slug' => 's'], 'auth' => 'not-an-array']
		);
		$calls = [];
		$this->intake->method('ingest')->willReturnCallback(
			function (...$args) use (&$calls): ObjectEntity {
				$calls[] = $args;
				$record = new ObjectEntity();
				$record->setUuid('rec-' . count($calls));
				$record->setObject(['status' => 'mapped', 'id' => 'kept']);
				return $record;
			}
		);

		$first = $this->controller()->inbound();
		$second = $this->controller()->inbound();

		$this->assertSame(['status' => 'mapped', 'id' => 'kept'], $first->getData());
		$this->assertSame(200, $second->getStatus());
		$this->assertSame(['s', '42', ['x'], ['v'], ['a'], ['bsn' => '1'], $identity], $calls[0]);
		$this->assertSame(['s', null, [], [], [], null, $identity], $calls[1]);

	}//end testTheInboundPayloadIsReadAndTheRecordAnswered()

	/**
	 * A failing ingest, a non-record and a record without uuid answer 503.
	 *
	 * @return void
	 */
	public function testAnUnstoredSubmissionAnswersFiveOhThree(): void {
		$this->authenticated();
		$this->request->method('getParams')->willReturn(['form' => ['slug' => 's'], 'submission' => ['uuid' => 'of-1']]);
		$this->intake->method('ingest')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('refused')),
			'not a record',
			new ObjectEntity()
		);

		foreach ([1, 2, 3] as $attempt) {
			$response = $this->controller()->inbound();
			$this->assertSame(503, $response->getStatus(), (string)$attempt);
			$this->assertSame('submission_not_stored', $response->getData()['error'], (string)$attempt);
		}

		$this->assertCount(3, $this->errors);

	}//end testAnUnstoredSubmissionAnswersFiveOhThree()

	/**
	 * Status and handoff check their own action, then the id; the bodies of
	 * every answer.
	 *
	 * @return void
	 */
	public function testStatusAndHandoffAnswers(): void {
		$this->signedIn();
		$this->intake->method('getSubmission')->willReturnCallback(
			static function (string $submissionUuid): array {
				if ($submissionUuid === 'gone') {
					throw new OpenFormulierenException('Submission gone');
				}

				return ['uuid' => $submissionUuid];
			}
		);
		$this->intake->method('handoff')->willReturnCallback(
			static function (string $submissionUuid): array {
				return match ($submissionUuid) {
					'ready' => ['status' => 'executed'],
					'early' => throw new OpenFormulierenException('Not mapped yet'),
					'undeclared' => throw new HandoffException(HandoffException::NOT_DECLARED, 'No handoff'),
					'busy' => throw new HandoffException(HandoffException::PROVIDER_UNAVAILABLE, 'Provider down'),
					'forbidden' => throw new NotAuthorizedException('Not yours'),
					default => throw new RuntimeException('Boom'),
				};
			}
		);
		$answer = fn ($response): array => [$response->getStatus(), $response->getData()];
		$controller = $this->controller();

		$this->assertSame([400, ['error' => 'missing_id', 'message' => 'The submission id is required']], $answer($controller->status()));
		$this->assertSame([200, ['uuid' => 'u-1']], $answer($controller->status('u-1')));
		$this->assertSame([404, ['error' => 'submission_not_found', 'message' => 'Submission gone']], $answer($controller->status('gone')));
		$this->assertSame([400, ['error' => 'missing_id', 'message' => 'The submission id is required']], $answer($controller->handoff()));
		$this->assertSame([200, ['status' => 'executed']], $answer($controller->handoff('ready')));
		$this->assertSame([400, ['error' => 'submission_not_ready', 'message' => 'Not mapped yet']], $answer($controller->handoff('early')));
		$this->assertSame([404, ['error' => HandoffException::NOT_DECLARED, 'message' => 'No handoff']], $answer($controller->handoff('undeclared')));
		$this->assertSame([409, ['error' => HandoffException::PROVIDER_UNAVAILABLE, 'message' => 'Provider down']], $answer($controller->handoff('busy')));
		$this->assertSame([403, ['error' => 'handoff_not_authorized', 'message' => 'Not yours']], $answer($controller->handoff('forbidden')));
		$this->assertSame([502, ['error' => 'handoff_failed', 'message' => 'Boom']], $answer($controller->handoff('other')));
		$this->assertSame(['[OpenFormulierenController] handoff failed unexpectedly: Boom'], $this->errors);
		$this->assertSame(
			array_merge(array_fill(0, 3, 'open-formulieren.status'), array_fill(0, 7, 'open-formulieren.handoff')),
			$this->actions
		);

	}//end testStatusAndHandoffAnswers()

	/**
	 * Without a session user both answer 401 before any action check.
	 *
	 * @return void
	 */
	public function testWithoutASessionUserStatusAndHandoffAnswerFourOhOne(): void {
		$controller = $this->controller();

		$this->assertSame([401, ['error' => 'Not authenticated']], [$controller->status('x')->getStatus(), $controller->status('x')->getData()]);
		$this->assertSame(401, $controller->handoff('x')->getStatus());
		$this->assertSame([], $this->actions);

	}//end testWithoutASessionUserStatusAndHandoffAnswerFourOhOne()

}//end class
