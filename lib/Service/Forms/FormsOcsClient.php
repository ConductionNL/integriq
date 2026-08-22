<?php

/**
 * Integriq Forms v3-REST Client.
 *
 * Concrete {@see FormsClientInterface} implementation. Targets the
 * `index.php/apps/forms/api/v3/*` surface (unwrapped JSON, no OCS response
 * envelope) — the same `index.php` convention `TablesOcsClient::BASE_PATH`
 * uses for `Tables`, chosen for consistency (design.md Decision 1,
 * discovery.md Finding 5). This base path is TENTATIVE: verified against the
 * public `nextcloud/forms` upstream source only, not a live instance with
 * the `forms` app installed. If a live instance later shows the OCS-
 * enveloped `ocs/v2.php/...` form is actually required, only this class's
 * {@see BASE_PATH} constant and envelope-unwrapping logic need to change —
 * {@see FormsClientInterface}'s contract is transport-agnostic by design.
 *
 * Transport is exclusively {@see \OCA\Integriq\Service\CallService::call()}
 * against the `Source` object configured on the synchronization/subscription
 * — no new HTTP client, no new secret storage. CallLog persistence,
 * rate-limit tracking, and brokered-credential dispatch are inherited for
 * free, mirroring `TablesOcsClient`'s own stated rationale.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Forms;

use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\FormsNotFoundException;
use OCA\Integriq\Exception\FormsPermissionDeniedException;
use OCA\Integriq\Exception\FormsUpstreamException;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Speaks the Forms `index.php/apps/forms/api/v3/*` REST dialect over `CallService`.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */
class FormsOcsClient implements FormsClientInterface {

	/**
	 * Base path of the Forms v3 REST surface (TENTATIVE — discovery.md
	 * Finding 5; verified against upstream source, not a live instance).
	 *
	 * @var string
	 */
	private const BASE_PATH = '/index.php/apps/forms/api/v3';

	/**
	 * Constructor.
	 *
	 * @param CallService $callService The shared HTTP dispatcher (CallLog, rate-limit,
	 *                                 brokered-credential resolution — all inherited).
	 * @param LoggerInterface $logger Logger for upstream-failure diagnostics.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read a single form, including its questions.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 *
	 * @return array{id: int, title: string, questions: array<int, array{id: int, text: string,
	 *               name: string, type: string}>}
	 *
	 * @throws FormsNotFoundException When the form does not exist upstream.
	 * @throws FormsPermissionDeniedException When the identity cannot read the form.
	 * @throws FormsUpstreamException On network failure or a non-2xx/non-4xx response.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
	 */
	public function getForm(ObjectEntity $source, int $formId): array {
		$body = $this->dispatch(source: $source, endpoint: self::BASE_PATH . "/forms/{$formId}", method: 'GET');

		return $this->normaliseForm(form: $this->asArray(value: $body));
	}//end getForm()

	/**
	 * Read a single submission, including its answers.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id the submission belongs to.
	 * @param int $submissionId The Forms submission id.
	 *
	 * @return array{id: int, formId: int, userId: string, timestamp: int,
	 *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}
	 *
	 * @throws FormsNotFoundException When the submission does not exist upstream.
	 * @throws FormsPermissionDeniedException When the identity cannot read the submission.
	 * @throws FormsUpstreamException On network failure or a non-2xx/non-4xx response.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
	 */
	public function getSubmission(ObjectEntity $source, int $formId, int $submissionId): array {
		$body = $this->dispatch(
			source: $source,
			endpoint: self::BASE_PATH . "/forms/{$formId}/submissions/{$submissionId}",
			method: 'GET'
		);

		return $this->normaliseSubmission(submission: $this->asArray(value: $body), fallbackFormId: $formId);
	}//end getSubmission()

	/**
	 * Read one page of a form's submissions.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 * @param int $page 1-based page number.
	 * @param int $pageSize Maximum submissions to return for this page.
	 *
	 * @return array<int, array{id: int, formId: int, userId: string, timestamp: int,
	 *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}>
	 *
	 * @throws FormsNotFoundException When the form does not exist upstream.
	 * @throws FormsPermissionDeniedException When the identity cannot read the form's submissions.
	 * @throws FormsUpstreamException On network failure or a non-2xx/non-4xx response.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 */
	public function listSubmissions(ObjectEntity $source, int $formId, int $page, int $pageSize): array {
		$offset = ($page - 1) * $pageSize;
		$body = $this->dispatch(
			source: $source,
			endpoint: self::BASE_PATH . "/forms/{$formId}/submissions",
			method: 'GET',
			config: [
				'query' => [
					'limit' => $pageSize,
					'offset' => $offset,
				],
			]
		);

		$submissionsPayload = $this->asArray(value: $body);
		// The Forms `FormsSubmissions` response shape wraps the list under a
		// `submissions` key alongside form metadata; tolerate a bare list too.
		$rawSubmissions = $submissionsPayload['submissions'] ?? $submissionsPayload;

		$submissions = [];
		foreach ($this->asArray(value: $rawSubmissions) as $submission) {
			if (is_array($submission) === false) {
				continue;
			}

			$submissions[] = $this->normaliseSubmission(submission: $submission, fallbackFormId: $formId);
		}

		return $submissions;
	}//end listSubmissions()

	/**
	 * List the forms accessible to the Source's configured identity.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 *
	 * @return array<int, array{id: int, title: string}>
	 *
	 * @throws FormsUpstreamException On network failure or a non-2xx/non-4xx response.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
	 */
	public function listForms(ObjectEntity $source): array {
		$body = $this->dispatch(source: $source, endpoint: self::BASE_PATH . '/forms', method: 'GET');

		$forms = [];
		foreach ($this->asArray(value: $body) as $form) {
			if (is_array($form) === false) {
				continue;
			}

			$forms[] = [
				'id' => (int)($form['id'] ?? 0),
				'title' => (string)($form['title'] ?? ''),
			];
		}

		return $forms;
	}//end listForms()

	/**
	 * Dispatch a call through `CallService::call()` and decode/classify the response.
	 *
	 * @param ObjectEntity $source The Source to call.
	 * @param string $endpoint The Forms endpoint (already includes {@see BASE_PATH}).
	 * @param string $method HTTP method.
	 * @param array $config Extra Guzzle-shaped config (headers/query/json).
	 *
	 * @return array|null The JSON-decoded response body, or null for an empty/204 body.
	 *
	 * @throws FormsNotFoundException On an upstream 404.
	 * @throws FormsPermissionDeniedException On an upstream 401/403.
	 * @throws FormsUpstreamException On transport failure or another non-2xx status.
	 */
	private function dispatch(ObjectEntity $source, string $endpoint, string $method, array $config = []): ?array {
		try {
			$callLog = $this->callService->call(source: $source, endpoint: $endpoint, method: $method, config: $config);
		} catch (GuzzleException|LoaderError|SyntaxError|\OCP\DB\Exception $exception) {
			$this->logger->warning(
				'FormsOcsClient: transport failure calling Forms API',
				['endpoint' => $endpoint, 'method' => $method, 'error' => $exception->getMessage()]
			);
			throw new FormsUpstreamException(
				message: "Failed to reach the Forms API ({$method} {$endpoint}): " . $exception->getMessage()
			);
		}

		$callLogBody = $callLog->getObject();
		$statusCode = (int)($callLogBody['response']['statusCode'] ?? 0);
		$rawBody = (string)($callLogBody['response']['body'] ?? '');

		if ($statusCode === 401 || $statusCode === 403) {
			throw new FormsPermissionDeniedException(
				message: "Forms API denied {$method} {$endpoint} (HTTP {$statusCode})",
				statusCode: $statusCode
			);
		}

		if ($statusCode === 404) {
			throw new FormsNotFoundException(message: "Forms API resource not found: {$method} {$endpoint}");
		}

		if ($statusCode < 200 || $statusCode >= 300) {
			throw new FormsUpstreamException(
				message: "Forms API returned HTTP {$statusCode} for {$method} {$endpoint} (see CallLog {$callLog->getUuid()})"
			);
		}

		if ($rawBody === '') {
			return null;
		}

		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end dispatch()

	/**
	 * Coerce a value to an array, treating any non-array as empty.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return array
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asArray()

	/**
	 * Normalise a raw Forms `Form` payload (incl. questions) into the contract shape.
	 *
	 * @param array $form Raw Forms `FormsForm` object.
	 *
	 * @return array{id: int, title: string, questions: array<int, array{id: int, text: string,
	 *               name: string, type: string}>}
	 */
	private function normaliseForm(array $form): array {
		$questions = [];
		foreach ($this->asArray(value: ($form['questions'] ?? null)) as $question) {
			if (is_array($question) === false) {
				continue;
			}

			$questions[] = [
				'id' => (int)($question['id'] ?? 0),
				// `text` is the question's label. Forms' own `FormsQuestion`
				// shape has no confirmed separate `name` field (discovery.md);
				// `name` is normalised here for the editor's field-mapping
				// helper (nextcloud-forms-connector REQ-005) with a best-effort
				// fallback to `text` when the upstream payload has no `name`.
				'text' => (string)($question['text'] ?? ''),
				'name' => (string)($question['name'] ?? $question['text'] ?? ''),
				'type' => (string)($question['type'] ?? ''),
			];
		}

		return [
			'id' => (int)($form['id'] ?? 0),
			'title' => (string)($form['title'] ?? ''),
			'questions' => $questions,
		];

	}//end normaliseForm()

	/**
	 * Normalise a raw Forms `Submission` payload (incl. answers) into the
	 * shape this client's callers expect.
	 *
	 * @param array $submission Raw Forms `FormsSubmission` object.
	 * @param int $fallbackFormId Used when the upstream payload omits `formId`
	 *                            (the caller always already knows it).
	 *
	 * @return array{id: int, formId: int, userId: string, timestamp: int,
	 *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}
	 */
	private function normaliseSubmission(array $submission, int $fallbackFormId): array {
		$answers = [];
		foreach ($this->asArray(value: ($submission['answers'] ?? null)) as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$questionName = null;
			if (isset($answer['questionName']) === true) {
				$questionName = (string)$answer['questionName'];
			}

			$answers[] = [
				'id' => (int)($answer['id'] ?? 0),
				'questionId' => (int)($answer['questionId'] ?? 0),
				'questionName' => $questionName,
				'text' => ($answer['text'] ?? null),
			];
		}

		return [
			'id' => (int)($submission['id'] ?? 0),
			'formId' => (int)($submission['formId'] ?? $fallbackFormId),
			'userId' => (string)($submission['userId'] ?? ''),
			'timestamp' => (int)($submission['timestamp'] ?? 0),
			'answers' => $answers,
		];

	}//end normaliseSubmission()
}//end class
