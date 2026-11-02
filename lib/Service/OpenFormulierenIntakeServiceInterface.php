<?php

/**
 * Contract of the OpenFormulierenIntakeService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see OpenFormulierenIntakeService} that other components call.
 */
interface OpenFormulierenIntakeServiceInterface {
	/**
	 * Ingest one signed, verified submission: persist (`received`), resolve +
	 * apply the form mapping (`mapped`|`failed`, isolated to this submission),
	 * and best-effort fetch/store attachments.
	 *
	 * Runs as the Open Formulieren connection's account (the controller wraps
	 * it in `runAs()`), so every write, the attachment files included, has that
	 * owner. A repeated delivery of the same `submission.uuid` creates no
	 * second record: a finished one is answered as it is, a `received` one is
	 * finished.
	 *
	 * @param string $formSlug The Open Formulieren form slug.
	 * @param string|null $formUuid The Open Formulieren form uuid, if present.
	 * @param array<string, mixed> $submissionMeta `{uuid, submittedAt}` from the payload's `submission` block.
	 * @param array<string, mixed> $values Raw submitted field values.
	 * @param array<int, array> $attachmentRefs `[{key, url, filename, contentType}, ...]`.
	 * @param array<string, mixed>|null $authContext `{plugin, bsn, kvk}` when present — never
	 *                                               logged.
	 * @param DsoIdentity|null $identity The connection and account the intake runs as; recorded as `receivedVia`.
	 *
	 * @return ObjectEntity The persisted `openformulieren_submission` record (any status).
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
	 */
	public function ingest(
		string $formSlug,
		?string $formUuid,
		array $submissionMeta,
		array $values,
		array $attachmentRefs = [],
		?array $authContext = null,
		?DsoIdentity $identity = null,
	): ObjectEntity;

	/**
	 * Read one submission's current state.
	 *
	 * @param string $submissionUuid The `openformulieren_submission` uuid.
	 *
	 * @return array<string, mixed> The submission's object data, plus `id`.
	 *
	 * @throws OpenFormulierenException When no submission exists for the uuid.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
	 */
	public function getSubmission(string $submissionUuid): array;

	/**
	 * Execute the declared `submission-to-case` handoff for a `mapped`
	 * submission, as the calling (real, authenticated) user — never a
	 * system-account shortcut (design.md §1.1). On success, best-effort
	 * copies stored attachments onto the created Case.
	 *
	 * @param string $submissionUuid The `openformulieren_submission` uuid.
	 *
	 * @return array<string, mixed> The engine's `execute()` result (`{status, target, correlationId}`
	 *                              or `{status: parked, queueEntry}`).
	 *
	 * @throws OpenFormulierenException When the submission is unknown or not yet `mapped`.
	 *
	 * Also propagates OpenRegister's own `Handoff\HandoffException` (not-declared /
	 * provider-unavailable) and `NotAuthorizedException` (RBAC refusal) unchanged —
	 * omitted from the @throws tag because PHPStan cannot resolve cross-app
	 * OCA\OpenRegister\Exception\* types as Throwable subtypes (same limitation
	 * documented in phpstan.neon's `unknown class OCA\\OpenRegister\\` ignores).
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-004
	 */
	public function handoff(string $submissionUuid): array;
}
