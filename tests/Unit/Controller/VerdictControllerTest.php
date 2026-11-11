<?php

/**
 * What the verdicts webhook answers and records for each delivery.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\VerdictController;
use OCA\Integriq\Outbound\Call\VerdictServiceInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The verdicts webhook: identification, the recorded fields and every answer.
 */
class VerdictControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var VerdictServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $verdicts;

	/**
	 * @var WebhookGateInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $gate;

	/**
	 * Mocks for the request, the verdict service and the gate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->verdicts = $this->createMock(VerdictServiceInterface::class);
		$this->gate = $this->createMock(WebhookGateInterface::class);

	}//end setUp()

	/**
	 * The controller over the mocks.
	 *
	 * @return VerdictController
	 */
	private function controller(): VerdictController {
		return new VerdictController(
			'integriq',
			$this->request,
			$this->createMock(IUserSession::class),
			$this->verdicts,
			$this->gate,
			$this->createMock(IL10N::class)
		);

	}//end controller()

	/**
	 * Let the gate identify the caller under the verdicts profile and run the delivery.
	 *
	 * @param array<string,mixed> $params The delivery's params.
	 *
	 * @return void
	 */
	private function identifyAs(array $params): void {
		$this->gate->method('identify')
			->with('intake-channel-verdicts', $this->anything(), $this->anything())
			->willReturn(new DsoIdentity($this->createMock(IUser::class), 'c-1'));
		$this->gate->method('deliver')->willReturnCallback(fn ($identity, callable $operation) => $operation());
		$this->request->method('getParams')->willReturn($params);

	}//end identifyAs()

	/**
	 * A full delivery is recorded field by field and answered 201 with its id.
	 *
	 * @return void
	 */
	public function testAVerdictIsRecordedAndAnswered201(): void {
		$this->identifyAs(params: ['objectRef' => 'o-1', 'state' => 'approved', 'source' => 'checker', 'reason' => 'ok', 'payload' => ['k' => 1]]);
		$verdict = new ObjectEntity();
		$verdict->setUuid('v-1');
		$this->verdicts->expects($this->once())->method('record')->with('o-1', 'approved', 'checker', 'ok', ['k' => 1])->willReturn($verdict);

		$response = $this->controller()->inbound();

		$this->assertSame([Http::STATUS_CREATED, ['id' => 'v-1', 'stored' => true]], [$response->getStatus(), $response->getData()]);

	}//end testAVerdictIsRecordedAndAnswered201()

	/**
	 * Missing fields are recorded empty, and a payload that is not a list as none.
	 *
	 * @return void
	 */
	public function testMissingFieldsAreRecordedEmpty(): void {
		$this->identifyAs(params: ['state' => 3, 'payload' => 'x']);
		$this->verdicts->expects($this->once())->method('record')->with('', '3', '', '', [])->willReturn(new ObjectEntity());

		$this->assertSame(['id' => '', 'stored' => true], $this->controller()->inbound()->getData());

	}//end testMissingFieldsAreRecordedEmpty()

	/**
	 * A verdict the service refuses answers 400 with its message.
	 *
	 * @return void
	 */
	public function testARefusedVerdictAnswers400(): void {
		$this->identifyAs(params: ['objectRef' => 'o-1']);
		$this->verdicts->method('record')->willThrowException(new InvalidArgumentException('unknown state'));
		$this->gate->expects($this->never())->method('notStored');

		$response = $this->controller()->inbound();

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'unknown state']], [$response->getStatus(), $response->getData()]);

	}//end testARefusedVerdictAnswers400()

	/**
	 * A refused write answers the gate's not-stored response for the verdicts profile.
	 *
	 * @return void
	 */
	public function testARefusedWriteAnswersNotStored(): void {
		$this->identifyAs(params: ['objectRef' => 'o-1']);
		$this->verdicts->method('record')->willThrowException(new RuntimeException('write refused'));
		$notStored = new JSONResponse(['error' => 'not stored'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())->method('notStored')->with('intake-channel-verdicts', 'write refused')->willReturn($notStored);

		$this->assertSame($notStored, $this->controller()->inbound());

	}//end testARefusedWriteAnswersNotStored()

	/**
	 * A refused identification is answered as is, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testARefusedIdentificationIsAnsweredAsIs(): void {
		$refusal = new JSONResponse(['error' => 'bad signature'], Http::STATUS_UNAUTHORIZED);
		$this->gate->method('identify')->willReturn($refusal);
		$this->gate->expects($this->never())->method('deliver');
		$this->verdicts->expects($this->never())->method('record');

		$this->assertSame($refusal, $this->controller()->inbound());

	}//end testARefusedIdentificationIsAnsweredAsIs()
}//end class
