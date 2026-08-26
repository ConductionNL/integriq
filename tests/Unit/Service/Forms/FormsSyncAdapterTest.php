<?php

/**
 * Unit tests for FormsSyncAdapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-forms-connector/tasks.md#task-9-unit-tests--forms-services
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Forms;

use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the pagination and feature-detection layer, exercised against a
 * stubbed FormsClientInterface (no real Forms app required — proposal.md
 * Risk 1).
 *
 * @spec openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md
 */
class FormsSyncAdapterTest extends TestCase {

	/**
	 * @var FormsClientInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $client;

	/**
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var FormsSyncAdapter
	 */
	private FormsSyncAdapter $adapter;

	/**
	 * @var ObjectEntity
	 */
	private ObjectEntity $source;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createMock(FormsClientInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->adapter = new FormsSyncAdapter($this->client, $this->appManager, $this->logger);
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

	}//end setUp()

	/**
	 * isEnabled() reflects IAppManager::isEnabledForUser() only (REQ-001).
	 *
	 * @return void
	 */
	public function testIsEnabledReflectsAppManager(): void {
		$this->appManager->method('isEnabledForUser')->with('forms')->willReturn(true);

		$this->assertTrue($this->adapter->isEnabled());

	}//end testIsEnabledReflectsAppManager()

	/**
	 * assertEnabled() throws when Forms is disabled — the abort signal for
	 * both the discovery endpoints (409) and a configured run (REQ-001).
	 *
	 * @return void
	 */
	public function testAssertEnabledThrowsWhenDisabled(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$this->expectException(FormsFeatureDisabledException::class);

		$this->adapter->assertEnabled();

	}//end testAssertEnabledThrowsWhenDisabled()

	/**
	 * fetchAllSubmissions() exposes `id` at the top level and stops once a
	 * page returns fewer submissions than requested (REQ-002).
	 *
	 * @return void
	 */
	public function testFetchAllSubmissionsStopsOnShortPage(): void {
		$this->client->method('listSubmissions')->willReturnCallback(
			function ($source, $formId, $page, $pageSize) {
				if ($page === 1) {
					return [
						['id' => 1, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100, 'answers' => []],
						['id' => 2, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 101, 'answers' => []],
					];
				}

				return [];
			}
		);

		$submissions = $this->adapter->fetchAllSubmissions(source: $this->source, formId: 42, pageSize: 2);

		$this->assertCount(2, $submissions);
		$this->assertSame(1, $submissions[0]['id']);
		$this->assertSame(2, $submissions[1]['id']);

	}//end testFetchAllSubmissionsStopsOnShortPage()

	/**
	 * fetchAllSubmissions() stops (does not loop forever) when the upstream
	 * ignores pagination and keeps returning the same first submission.
	 *
	 * @return void
	 */
	public function testFetchAllSubmissionsStopsOnNonPaginatingUpstream(): void {
		$this->client->method('listSubmissions')->willReturn(
			[
				['id' => 1, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100, 'answers' => []],
				['id' => 2, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 101, 'answers' => []],
			]
		);

		$submissions = $this->adapter->fetchAllSubmissions(source: $this->source, formId: 42, pageSize: 2);

		// Two pages max: page 1 returns the batch, page 2 repeats the same
		// first submission id and the loop bails instead of looping forever.
		$this->assertCount(2, $submissions);

	}//end testFetchAllSubmissionsStopsOnNonPaginatingUpstream()

	/**
	 * fetchAllSubmissions() includes each submission's `answers` unchanged
	 * (REQ-002 — full submission fed into the mapping pipeline).
	 *
	 * @return void
	 */
	public function testFetchAllSubmissionsIncludesAnswers(): void {
		$answers = [
			['id' => 1, 'questionId' => 4, 'questionName' => null, 'text' => 'Red'],
			['id' => 2, 'questionId' => 4, 'questionName' => null, 'text' => 'Blue'],
		];
		$this->client->method('listSubmissions')->willReturn(
			[['id' => 1, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100, 'answers' => $answers]]
		);

		$submissions = $this->adapter->fetchAllSubmissions(source: $this->source, formId: 42, pageSize: 10);

		$this->assertSame($answers, $submissions[0]['answers']);

	}//end testFetchAllSubmissionsIncludesAnswers()

	/**
	 * fetchSubmission() delegates straight through to the client.
	 *
	 * @return void
	 */
	public function testFetchSubmissionDelegatesToClient(): void {
		$submission = ['id' => 5, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100, 'answers' => []];
		$this->client->expects($this->once())->method('getSubmission')
			->with($this->source, 42, 5)
			->willReturn($submission);

		$result = $this->adapter->fetchSubmission(source: $this->source, formId: 42, submissionId: 5);

		$this->assertSame($submission, $result);

	}//end testFetchSubmissionDelegatesToClient()

	/**
	 * fetchForm() delegates straight through to the client.
	 *
	 * @return void
	 */
	public function testFetchFormDelegatesToClient(): void {
		$form = ['id' => 42, 'title' => 'Contact form', 'questions' => []];
		$this->client->expects($this->once())->method('getForm')->with($this->source, 42)->willReturn($form);

		$result = $this->adapter->fetchForm(source: $this->source, formId: 42);

		$this->assertSame($form, $result);

	}//end testFetchFormDelegatesToClient()

	/**
	 * listQuestionsForEditor() extracts the `questions` list from getForm().
	 *
	 * @return void
	 */
	public function testListQuestionsForEditorExtractsQuestions(): void {
		$questions = [['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short']];
		$this->client->method('getForm')->willReturn(['id' => 42, 'title' => 'Contact form', 'questions' => $questions]);

		$result = $this->adapter->listQuestionsForEditor(source: $this->source, formId: 42);

		$this->assertSame($questions, $result);

	}//end testListQuestionsForEditorExtractsQuestions()
}//end class
