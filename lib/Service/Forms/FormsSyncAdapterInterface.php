<?php

/**
 * Contract of the FormsSyncAdapter.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Forms;

use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see FormsSyncAdapter} that other components call.
 */
interface FormsSyncAdapterInterface {
	/**
	 * Whether the Forms app is enabled for the given user (or instance-wide
	 * when no user is given — used by cron/background dispatch and the
	 * outbound `action.kind: 'mapping'` delivery path, which runs without an
	 * interactive user).
	 *
	 * @param IUser|null $user The acting user, or null for a non-interactive context.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001
	 */
	public function isEnabled(?IUser $user = null): bool;

	/**
	 * Guard every `nextcloud-form` entry point on feature detection.
	 *
	 * @param IUser|null $user The acting user, or null for a non-interactive context.
	 *
	 * @return void
	 *
	 * @throws FormsFeatureDisabledException When the Forms app is absent/disabled.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001
	 */
	public function assertEnabled(?IUser $user = null): void;

	/**
	 * Fetch every submission of a form, paginating until exhausted.
	 *
	 * Each returned submission is exposed with `id` at the top level so the
	 * existing `getOriginId()` default `idPosition` ('id') resolves the
	 * Forms submission id with no adapter-specific override (REQ-002).
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 * @param int $pageSize Submissions requested per page (default 100).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 */
	public function fetchAllSubmissions(ObjectEntity $source, int $formId, int $pageSize = 100): array;

	/**
	 * Fetch a submission's full answers (with the form's questions, for the
	 * outbound answer-resolution path) — `EventService::dispatchMappingAction()`'s
	 * entry point into this adapter (design.md Decision 2).
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 * @param int $submissionId The Forms submission id.
	 *
	 * @return array{id: int, formId: int, userId: string, timestamp: int,
	 *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
	 */
	public function fetchSubmission(ObjectEntity $source, int $formId, int $submissionId): array;

	/**
	 * Fetch a form's questions (id/text/name/type), used by both the
	 * outbound answer resolver and the editor's field-mapping helper.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 *
	 * @return array{id: int, title: string, questions: array<int, array{id: int, text: string,
	 *               name: string, type: string}>}
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
	 */
	public function fetchForm(ObjectEntity $source, int $formId): array;

	/**
	 * List the forms accessible to a Source's identity, for the editor's form picker.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 *
	 * @return array<int, array{id: int, title: string}>
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
	 */
	public function listFormsForEditor(ObjectEntity $source): array;

	/**
	 * List a form's questions with type metadata, for the editor's
	 * field-mapping helper.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $formId The Forms form id.
	 *
	 * @return array<int, array{id: int, text: string, name: string, type: string}>
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
	 */
	public function listQuestionsForEditor(ObjectEntity $source, int $formId): array;
}
