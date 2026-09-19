<?php

/**
 * Integriq — ownership controller tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OwnershipController;
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\OwnershipState;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The read a consuming app uses, and the delete it is refused.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */
class OwnershipControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param OwnershipState $state The ownership the read answers with.
	 * @param OrObjectService|null $objectService The object service double.
	 * @param IUser|null $user The signed-in account, or null for none.
	 *
	 * @return OwnershipController The controller under test.
	 */
	/**
	 * An object service that answers with a readable object.
	 *
	 * `show()` resolves the object through the ordinary scoped read before it
	 * will disclose ownership, so a test asserting the ownership answer has to
	 * present an object the caller can actually read.
	 *
	 * @return OrObjectService The double.
	 */
	private function readable(): OrObjectService {
		$entity = new ObjectEntity();
		$entity->setObject(['id' => 'obj-1']);

		$service = $this->createMock(OrObjectService::class);
		$service->method('find')->willReturn($entity);

		return $service;
	}//end readable()

	private function controller(
		OwnershipState $state,
		?OrObjectService $objectService = null,
		?IUser $user = null,
	): OwnershipController {
		$ownership = $this->getMockBuilder(RecordOwnershipService::class)
			->disableOriginalConstructor()
			->onlyMethods(['forObject'])
			->getMock();
		$ownership->method('forObject')->willReturn($state);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OwnershipController(
			'integriq',
			$this->createMock(IRequest::class),
			$ownership,
			new LocalDeleteGuard(),
			($objectService ?? $this->createMock(OrObjectService::class)),
			$session
		);
	}//end controller()

	/**
	 * A source-owned record.
	 *
	 * @return OwnershipState The state.
	 */
	private function owned(): OwnershipState {
		return new OwnershipState(
			OwnershipState::MODE_SOURCE,
			'brp-haalcentraal',
			'999993653',
			null,
			true,
			false,
			null,
			'sync-1',
			'BRP personen'
		);
	}//end owned()

	/**
	 * A signed-in account.
	 *
	 * @return IUser The double.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar1');

		return $user;
	}//end user()

	/**
	 * One read answers the ownership question for a consuming app.
	 *
	 * @return void
	 */
	public function testAnObjectTheCallerCannotReadAnswersLocalRatherThanItsOwnership(): void {
		// THE DISCLOSURE TEST. The ownership answer names the synchronisation,
		// the source id and when the record was last seen. Asking for an id the
		// caller cannot read used to return all three.
		//
		// The answer is `local` rather than a 404 on purpose: an unknown object
		// answers `local` by spec, so an unreadable one answering the same way
		// makes the two indistinguishable — the endpoint cannot be used to
		// discover that a record exists or who maintains it.
		$unreadable = $this->createMock(OrObjectService::class);
		$unreadable->method('find')->willReturn(null);

		$data = $this->controller($this->owned(), $unreadable)->show('obj-1')->getData();

		$this->assertSame('local', $data['mode']);
		$this->assertNull($data['source']);
		$this->assertNull($data['originId']);
	}//end testAnObjectTheCallerCannotReadAnswersLocalRatherThanItsOwnership()

	public function testOneReadAnswersOwnership(): void {
		$data = $this->controller($this->owned(), $this->readable())->show('obj-1')->getData();

		$this->assertSame('source', $data['mode']);
		$this->assertSame('brp-haalcentraal', $data['source']);
		$this->assertSame('999993653', $data['originId']);
		$this->assertFalse($data['absentAtSource']);
	}//end testOneReadAnswersOwnership()

	/**
	 * An object nobody maintains answers local, and the call succeeds.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectAnswersLocalRatherThanFailing(): void {
		$response = $this->controller(OwnershipState::local())->show('obj-unknown');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('local', $response->getData()['mode']);
	}//end testAnUnknownObjectAnswersLocalRatherThanFailing()

	/**
	 * A handler cannot quietly remove a BRP person: the delete is refused and
	 * the message names the synchronisation.
	 *
	 * @return void
	 */
	public function testADeleteOfASourceOwnedRecordIsRefused(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller($this->owned(), $objectService, $this->user())
			->destroy('obj-1', 'integriq', 'contact');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('BRP personen', $response->getData()['error']);
	}//end testADeleteOfASourceOwnedRecordIsRefused()

	/**
	 * An override with a typed reason deletes, and the statement comes back.
	 *
	 * @return void
	 */
	public function testAnOverrideWithAReasonDeletesAndRecordsTheStatement(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->once())->method('deleteObject');

		$response = $this->controller($this->owned(), $objectService, $this->user())
			->destroy('obj-1', 'integriq', 'contact', 'duplicate row');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['deleted']);
		$this->assertSame('duplicate row', $data['override']['reason']);
		$this->assertSame('behandelaar1', $data['override']['user']);
	}//end testAnOverrideWithAReasonDeletesAndRecordsTheStatement()

	/**
	 * An override carrying no reason deletes nothing.
	 *
	 * @return void
	 */
	public function testAnOverrideWithAnEmptyReasonDeletesNothing(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller($this->owned(), $objectService, $this->user())
			->destroy('obj-1', 'integriq', 'contact', '  ');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('requires a reason', $response->getData()['error']);
	}//end testAnOverrideWithAnEmptyReasonDeletesNothing()

	/**
	 * An anonymous request, the least privileged principal that reaches this
	 * route, deletes nothing at all.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestDeletesNothing(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller(OwnershipState::local(), $objectService, null)
			->destroy('obj-1', 'integriq', 'contact');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousRequestDeletesNothing()

	/**
	 * A misspelled policy is refused before it is stored, naming the key and
	 * the values it accepts.
	 *
	 * @return void
	 */
	public function testAMisspelledPolicyIsRefusedBeforeItIsStored(): void {
		$response = $this->controller(OwnershipState::local())
			->validatePolicy(['disappearancePolicy' => 'remove']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['valid']);
		$this->assertSame('disappearancePolicy', $data['key']);
		$this->assertSame(['delete', 'markEnded', 'keepAndFlag'], $data['accepted']);
	}//end testAMisspelledPolicyIsRefusedBeforeItIsStored()

	/**
	 * An accepted policy passes, and says which one it read.
	 *
	 * @return void
	 */
	public function testAnAcceptedPolicyPasses(): void {
		$response = $this->controller(OwnershipState::local())
			->validatePolicy(['disappearancePolicy' => 'markEnded']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('markEnded', $response->getData()['policy']);
	}//end testAnAcceptedPolicyPasses()
}//end class
