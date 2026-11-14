<?php

/**
 * The Forms client and adapter on malformed upstream lists, a per-user feature check and the editor form list.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-forms-connector/tasks.md#task-9-unit-tests--forms-services
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Forms;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Forms\FormsOcsClient
 * @covers \OCA\Integriq\Service\Forms\FormsSyncAdapter
 * @uses \OCA\Integriq\Service\Helper\OcsApi
 * @uses \OCA\Integriq\Service\Helper\OcsApiDispatcher
 * @uses \OCA\Integriq\Service\Helper\OcsPayload
 * @uses \OCA\Integriq\Service\Helper\OcsRequest
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */
class FormsMalformedPayloadTest extends TestCase {

	/**
	 * The call service double.
	 *
	 * @var CallService&MockObject
	 */
	private CallService $callService;

	/**
	 * The client under test.
	 *
	 * @var FormsOcsClient
	 */
	private FormsOcsClient $client;

	/**
	 * The Forms source.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $source;

	/**
	 * Set up the client over a call service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->callService = $this->createMock(CallService::class);
		$this->client      = new FormsOcsClient($this->callService, $this->createMock(LoggerInterface::class));
		$this->source      = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');
	}//end setUp()

	/**
	 * The call service answers this body with a 200.
	 *
	 * @param mixed $body The decoded body.
	 *
	 * @return void
	 */
	private function answers(mixed $body): void {
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getUuid')->willReturn('call-log-uuid-1');
		$callLog->method('getObject')->willReturn(['response' => ['statusCode' => 200, 'body' => json_encode($body)]]);
		$this->callService->method('call')->willReturn($callLog);
	}//end answers()

	/**
	 * Entries of a form list that are not objects are skipped.
	 *
	 * @return void
	 */
	public function testAFormListSkipsNonObjects(): void {
		$this->answers([['id' => 1, 'title' => 'Aanvraag'], 'garbage', 7, null, ['id' => '2']]);

		$this->assertSame(
			[['id' => 1, 'title' => 'Aanvraag'], ['id' => 2, 'title' => '']],
			$this->client->listForms(source: $this->source)
		);
	}//end testAFormListSkipsNonObjects()

	/**
	 * Questions that are not objects are skipped, and a question's name falls back to its text.
	 *
	 * @return void
	 */
	public function testAFormSkipsNonObjectQuestions(): void {
		$this->answers(['id' => 42, 'title' => 'Melding', 'questions' => ['x', ['id' => 3, 'text' => 'Adres', 'name' => 'adres', 'type' => 'short'], 5, ['id' => 4]]]);

		$this->assertSame(
			[
				'id' => 42,
				'title' => 'Melding',
				'questions' => [
					['id' => 3, 'text' => 'Adres', 'name' => 'adres', 'type' => 'short'],
					['id' => 4, 'text' => '', 'name' => '', 'type' => ''],
				],
			],
			$this->client->getForm(source: $this->source, formId: 42)
		);
	}//end testAFormSkipsNonObjectQuestions()

	/**
	 * Submissions and answers that are not objects are skipped; a named answer keeps its question name as a string.
	 *
	 * @return void
	 */
	public function testSubmissionsSkipNonObjectsAndKeepQuestionNames(): void {
		$this->answers(
			[
				'submissions' => [
					'garbage',
					[
						'id' => 5,
						'userId' => 'burger',
						'timestamp' => 1700000000,
						'answers' => [false, ['id' => 9, 'questionId' => 3, 'questionName' => 123, 'text' => 'Dorpsstraat 1']],
					],
				],
			]
		);

		$submissions = $this->client->listSubmissions(source: $this->source, formId: 42, page: 1, pageSize: 10);

		$this->assertCount(1, $submissions);
		$this->assertSame(5, $submissions[0]['id']);
		$this->assertSame(42, $submissions[0]['formId'], 'the requested form when the submission names none');
		$this->assertSame(
			[['id' => 9, 'questionId' => 3, 'questionName' => '123', 'text' => 'Dorpsstraat 1']],
			$submissions[0]['answers']
		);
	}//end testSubmissionsSkipNonObjectsAndKeepQuestionNames()

	/**
	 * With a user, the feature check asks whether Forms is enabled for that user.
	 *
	 * @return void
	 */
	public function testTheFeatureCheckIsPerUserWhenAUserIsGiven(): void {
		$user       = $this->createMock(IUser::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isEnabledForUser')->with('forms', $user)->willReturn(false);
		$adapter = new FormsSyncAdapter($this->createMock(FormsClientInterface::class), $appManager, $this->createMock(LoggerInterface::class));

		$this->assertFalse($adapter->isEnabled($user));
	}//end testTheFeatureCheckIsPerUserWhenAUserIsGiven()

	/**
	 * The editor's form list is the client's list for the source.
	 *
	 * @return void
	 */
	public function testTheEditorFormListIsTheClients(): void {
		$client = $this->createMock(FormsClientInterface::class);
		$client->expects($this->once())->method('listForms')->with($this->source)->willReturn([['id' => 1, 'title' => 'Aanvraag']]);
		$adapter = new FormsSyncAdapter($client, $this->createMock(IAppManager::class), $this->createMock(LoggerInterface::class));

		$this->assertSame([['id' => 1, 'title' => 'Aanvraag']], $adapter->listFormsForEditor($this->source));
	}//end testTheEditorFormListIsTheClients()
}//end class
