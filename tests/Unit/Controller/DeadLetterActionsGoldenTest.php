<?php

/**
 * The two dead-letter controllers replay, discard and bulk-apply the same way.
 *
 * Pins, for the CloudEvents dead-letter routes on EventsController and the
 * sync-item routes on SyncDeadLetterController: the single replay and
 * discard (the updated object, 404 with each controller's own sentence, 409
 * with the state message), the audited actor (the session uid, or `unknown`),
 * and the bulk routes (400 for a non-array or more than 100 ids, a per-id
 * outcome map, the actor read once), so the shared actions can move without
 * changing any outcome.
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
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use Exception;
use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Controller\SyncDeadLetterController;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\SyncItemDeadLetterServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the dead-letter actions of both controllers.
 */
final class DeadLetterActionsGoldenTest extends TestCase {

	/**
	 * Both controllers, their service, their property and their not-found and ids sentences.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: string, 3: string, 4: string}>
	 */
	public static function controllers(): array {
		return [
			'events' => [EventsController::class, EventServiceInterface::class, 'eventService', 'Message not found', 'ids must be an array of message UUIDs'],
			'sync items' => [SyncDeadLetterController::class, SyncItemDeadLetterServiceInterface::class, 'syncItemDeadLetterService', 'Entry not found', 'ids must be an array of entry UUIDs'],
		];

	}//end controllers()

	/**
	 * A controller over the given service, session user and request ids.
	 *
	 * @param class-string $class    The controller.
	 * @param string       $property The service property.
	 * @param object       $service  The service double.
	 * @param IUser|null   $user     The session user.
	 * @param mixed        $ids      What the request's `ids` parameter holds.
	 *
	 * @return object
	 */
	private function controller(string $class, string $property, object $service, ?IUser $user, mixed $ids = null): object {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => $key === 'ids' ? $ids : null);

		$reflection = new ReflectionClass($class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty($property)->setValue($controller, $service);
		$reflection->getProperty('l')->setValue($controller, $l10n);
		$reflection->getProperty('userSession')->setValue($controller, $session);
		(function (IRequest $request): void {
			$this->request = $request;
		})->call($controller, $request);

		return $controller;

	}//end controller()

	/**
	 * A user with a uid.
	 *
	 * @return IUser&MockObject
	 */
	private function alice(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;

	}//end alice()

	/**
	 * A replay and a discard answer the updated object, audited as the session user or `unknown`.
	 *
	 * @param class-string $class    The controller.
	 * @param class-string $service  The service interface.
	 * @param string       $property The service property.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testSingleActionsAnswerTheUpdatedObject(string $class, string $service, string $property): void {
		$updated = new ObjectEntity();
		$updated->setObject(['status' => 'replayed']);
		$double = $this->createMock($service);
		$double->expects($this->once())->method('replayMessage')->with('m-1', 'alice')->willReturn($updated);
		$double->expects($this->once())->method('discardMessage')->with('m-2', 'unknown')->willReturn($updated);

		$replayed = $this->controller(class: $class, property: $property, service: $double, user: $this->alice())->replay('m-1');
		$discarded = $this->controller(class: $class, property: $property, service: $double, user: null)->discard('m-2');

		$this->assertSame([200, ['status' => 'replayed']], [$replayed->getStatus(), $replayed->getData()]);
		$this->assertSame([200, ['status' => 'replayed']], [$discarded->getStatus(), $discarded->getData()]);

	}//end testSingleActionsAnswerTheUpdatedObject()

	/**
	 * An unknown id answers 404 with the controller's sentence, an invalid state 409 with its message.
	 *
	 * @param class-string $class    The controller.
	 * @param class-string $service  The service interface.
	 * @param string       $property The service property.
	 * @param string       $notFound The not-found sentence.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testSingleActionsRefuse(string $class, string $service, string $property, string $notFound): void {
		$double = $this->createMock($service);
		$double->method('replayMessage')->willThrowException(new DoesNotExistException('gone'));
		$double->method('discardMessage')->willThrowException(new InvalidMessageStateException('already discarded'));
		$controller = $this->controller(class: $class, property: $property, service: $double, user: $this->alice());

		$missing = $controller->replay('m-1');
		$conflict = $controller->discard('m-1');

		$this->assertSame([404, ['error' => $notFound]], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame([409, ['error' => 'already discarded']], [$conflict->getStatus(), $conflict->getData()]);

	}//end testSingleActionsRefuse()

	/**
	 * The bulk routes refuse a non-array and more than 100 ids before touching anything.
	 *
	 * @param class-string $class    The controller.
	 * @param class-string $service  The service interface.
	 * @param string       $property The service property.
	 * @param string       $notFound The not-found sentence.
	 * @param string       $notArray The ids sentence.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testBulkRefusesABadIdList(string $class, string $service, string $property, string $notFound, string $notArray): void {
		$double = $this->createMock($service);
		$double->expects($this->never())->method('replayMessage');
		$double->expects($this->never())->method('discardMessage');

		$notAList = $this->controller(class: $class, property: $property, service: $double, user: null, ids: 'a,b')->bulkReplay();
		$tooMany = $this->controller(class: $class, property: $property, service: $double, user: null, ids: range(1, 101))->bulkDiscard();

		$this->assertSame([400, ['error' => $notArray]], [$notAList->getStatus(), $notAList->getData()]);
		$this->assertSame([400, ['error' => 'A maximum of 100 ids may be processed per call']], [$tooMany->getStatus(), $tooMany->getData()]);
		$this->assertNotSame('', $notFound);

	}//end testBulkRefusesABadIdList()

	/**
	 * The bulk routes report each id's outcome, audited as one actor read once.
	 *
	 * @param class-string $class    The controller.
	 * @param class-string $service  The service interface.
	 * @param string       $property The service property.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testBulkReportsEachOutcome(string $class, string $service, string $property): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->exactly(2))->method('getUID')->willReturn('bob');
		$double = $this->createMock($service);
		$double->method('replayMessage')->willReturnCallback(
			function (string $id, string $actor): ObjectEntity {
				$this->assertSame('bob', $actor);
				return match ($id) {
					'ok' => new ObjectEntity(),
					'gone' => throw new DoesNotExistException('gone'),
					'done' => throw new InvalidMessageStateException('done'),
					default => throw new Exception('boom'),
				};
			}
		);
		$double->expects($this->once())->method('discardMessage')->with('7', 'bob')->willReturn(new ObjectEntity());

		$replayed = $this->controller(class: $class, property: $property, service: $double, user: $user, ids: ['ok', 'gone', 'done', 'odd'])->bulkReplay();
		$discarded = $this->controller(class: $class, property: $property, service: $double, user: $user, ids: [7])->bulkDiscard();

		$this->assertSame([200, ['results' => ['ok' => 'ok', 'gone' => 'not-found', 'done' => 'invalid-state', 'odd' => 'error']]], [$replayed->getStatus(), $replayed->getData()]);
		$this->assertSame(['results' => ['7' => 'ok']], $discarded->getData());

	}//end testBulkReportsEachOutcome()
}//end class
