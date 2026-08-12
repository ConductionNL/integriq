<?php

/**
 * Unit tests for FormsOcsClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Forms
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

namespace OCA\OpenConnector\Tests\Unit\Service\Forms;

use OCA\OpenConnector\Exception\FormsNotFoundException;
use OCA\OpenConnector\Exception\FormsPermissionDeniedException;
use OCA\OpenConnector\Exception\FormsUpstreamException;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\Forms\FormsOcsClient;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the v3-REST Forms API client.
 *
 * @spec openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md
 */
class FormsOcsClientTest extends TestCase {

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var FormsOcsClient
	 */
	private FormsOcsClient $client;

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

		$this->callService = $this->createMock(CallService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->client = new FormsOcsClient($this->callService, $this->logger);
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

	}//end setUp()

	/**
	 * Build a mocked CallLog ObjectEntity whose `getObject()` returns the given
	 * status code + JSON-encoded body, matching CallService::call()'s real shape.
	 *
	 * @param int $statusCode The HTTP status code to report.
	 * @param mixed $body The decoded body to JSON-encode (or null for an empty body).
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockCallLog(int $statusCode, mixed $body = null) {
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getUuid')->willReturn('call-log-uuid-1');
		$callLog->method('getObject')->willReturn(
			[
				'response' => [
					'statusCode' => $statusCode,
					'body' => ($body === null ? '' : json_encode($body)),
				],
			]
		);

		return $callLog;
	}//end mockCallLog()

	/**
	 * A successful getForm() call normalises the questions list.
	 *
	 * @return void
	 */
	public function testGetFormNormalisesQuestions(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), $this->stringContains('/forms/42'), 'GET', $this->anything())
			->willReturn(
				$this->mockCallLog(
					200,
					[
						'id' => 42,
						'title' => 'Contact form',
						'questions' => [
							['id' => 7, 'text' => 'Company name', 'type' => 'short'],
						],
					]
				)
			);

		$result = $this->client->getForm(source: $this->source, formId: 42);

		$this->assertSame(42, $result['id']);
		$this->assertSame('Contact form', $result['title']);
		$this->assertCount(1, $result['questions']);
		$this->assertSame(7, $result['questions'][0]['id']);
		$this->assertSame('short', $result['questions'][0]['type']);
		// 'name' falls back to 'text' when the upstream payload has none.
		$this->assertSame('Company name', $result['questions'][0]['name']);

	}//end testGetFormNormalisesQuestions()

	/**
	 * A successful getSubmission() call normalises the answers list.
	 *
	 * @return void
	 */
	public function testGetSubmissionNormalisesAnswers(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), $this->stringContains('/forms/42/submissions/5'), 'GET', $this->anything())
			->willReturn(
				$this->mockCallLog(
					200,
					[
						'id' => 5,
						'formId' => 42,
						'userId' => 'admin',
						'timestamp' => 1700000000,
						'answers' => [
							['id' => 1, 'questionId' => 7, 'text' => 'Acme BV'],
						],
					]
				)
			);

		$result = $this->client->getSubmission(source: $this->source, formId: 42, submissionId: 5);

		$this->assertSame(5, $result['id']);
		$this->assertSame(42, $result['formId']);
		$this->assertCount(1, $result['answers']);
		$this->assertSame(7, $result['answers'][0]['questionId']);
		$this->assertSame('Acme BV', $result['answers'][0]['text']);
		$this->assertNull($result['answers'][0]['questionName']);

	}//end testGetSubmissionNormalisesAnswers()

	/**
	 * listSubmissions() forwards limit/offset and normalises each submission.
	 *
	 * @return void
	 */
	public function testListSubmissionsForwardsPaginationAndNormalises(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->anything(),
				$this->stringContains('/forms/42/submissions'),
				'GET',
				$this->callback(static fn (array $config) => ($config['query']['limit'] ?? null) === 10 && ($config['query']['offset'] ?? null) === 0)
			)
			->willReturn(
				$this->mockCallLog(
					200,
					['submissions' => [['id' => 1, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 1, 'answers' => []]]]
				)
			);

		$result = $this->client->listSubmissions(source: $this->source, formId: 42, page: 1, pageSize: 10);

		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]['id']);

	}//end testListSubmissionsForwardsPaginationAndNormalises()

	/**
	 * listSubmissions() tolerates a bare-array response (no `submissions` wrapper key).
	 *
	 * @return void
	 */
	public function testListSubmissionsToleratesBareArrayResponse(): void {
		$this->callService->method('call')->willReturn(
			$this->mockCallLog(200, [['id' => 9, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 1, 'answers' => []]])
		);

		$result = $this->client->listSubmissions(source: $this->source, formId: 42, page: 1, pageSize: 10);

		$this->assertCount(1, $result);
		$this->assertSame(9, $result[0]['id']);

	}//end testListSubmissionsToleratesBareArrayResponse()

	/**
	 * listForms() normalises each form entry.
	 *
	 * @return void
	 */
	public function testListFormsReturnsNormalisedEntries(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(200, [['id' => 42, 'title' => 'Contact form']]));

		$result = $this->client->listForms(source: $this->source);

		$this->assertSame([['id' => 42, 'title' => 'Contact form']], $result);

	}//end testListFormsReturnsNormalisedEntries()

	/**
	 * A 403 response is mapped to FormsPermissionDeniedException.
	 *
	 * @return void
	 */
	public function test403IsMappedToPermissionDeniedException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(403, ['message' => 'forbidden']));

		$this->expectException(FormsPermissionDeniedException::class);

		$this->client->getForm(source: $this->source, formId: 42);

	}//end test403IsMappedToPermissionDeniedException()

	/**
	 * A 401 response is mapped to FormsPermissionDeniedException.
	 *
	 * @return void
	 */
	public function test401IsMappedToPermissionDeniedException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(401, ['message' => 'unauthenticated']));

		$this->expectException(FormsPermissionDeniedException::class);

		$this->client->listForms(source: $this->source);

	}//end test401IsMappedToPermissionDeniedException()

	/**
	 * A 404 response is mapped to FormsNotFoundException.
	 *
	 * @return void
	 */
	public function test404IsMappedToNotFoundException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(404, ['message' => 'not found']));

		$this->expectException(FormsNotFoundException::class);

		$this->client->getSubmission(source: $this->source, formId: 42, submissionId: 999);

	}//end test404IsMappedToNotFoundException()

	/**
	 * A 500 response is mapped to FormsUpstreamException.
	 *
	 * @return void
	 */
	public function test500IsMappedToUpstreamException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(500, ['message' => 'boom']));

		$this->expectException(FormsUpstreamException::class);

		$this->client->listForms(source: $this->source);

	}//end test500IsMappedToUpstreamException()

	/**
	 * A transport-level failure (DB persistence error surfaced by CallService)
	 * is mapped to FormsUpstreamException, never left to crash uncaught.
	 *
	 * @return void
	 */
	public function testTransportFailureIsMappedToUpstreamException(): void {
		$this->callService->method('call')->willThrowException(new \OCP\DB\Exception('connection refused'));

		$this->expectException(FormsUpstreamException::class);

		$this->client->listForms(source: $this->source);

	}//end testTransportFailureIsMappedToUpstreamException()
}//end class
