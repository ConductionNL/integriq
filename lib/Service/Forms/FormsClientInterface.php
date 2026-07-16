<?php

/**
 * OpenConnector Forms Client Interface.
 *
 * Narrow domain seam through which every Nextcloud Forms form/question/
 * submission read occurs — the single seam both the outbound
 * (submission -> external call) and inbound (`nextcloud-form` sync source)
 * directions go through (design.md Decision 1). Deliberately transport- and
 * API-version-agnostic in its method signatures — the concrete
 * {@see FormsOcsClient} targets the `index.php/apps/forms/api/v3/*` REST
 * surface (TENTATIVE base path, discovery.md Finding 5), but a future client
 * could be swapped in via DI without touching `FormsSyncAdapter`,
 * `SynchronizationService`, or `EventService`.
 *
 * MUST NEVER reference any `OCA\Forms\*` class — design.md Decision 1's
 * "in-process call" alternative was rejected specifically because
 * `FormsClientInterface` implementations are eagerly bound in the DI
 * container at every request, so a compile-time `use OCA\Forms\*` would
 * break autoloading on every instance without Forms installed. Feature
 * detection happens exclusively at the {@see FormsSyncAdapter} usage layer
 * via `IAppManager`.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Forms;

use OCA\OpenConnector\Exception\FormsNotFoundException;
use OCA\OpenConnector\Exception\FormsPermissionDeniedException;
use OCA\OpenConnector\Exception\FormsUpstreamException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A Forms API binding: list/read forms, questions, and submissions.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */
interface FormsClientInterface
{
    /**
     * Read a single form, including its questions.
     *
     * @param ObjectEntity $source The `Source` (register `openconnector`, schema
     *                             `source`) whose `location`/`authentication`
     *                             reach the target Nextcloud instance.
     * @param int          $formId The Forms form id.
     *
     * @return array{id: int, title: string, questions: array<int, array{id: int, text: string,
     *               name: string, type: string}>}
     *
     * @throws FormsNotFoundException          When the form does not exist upstream.
     * @throws FormsPermissionDeniedException  When the identity cannot read the form.
     * @throws FormsUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    public function getForm(ObjectEntity $source, int $formId): array;

    /**
     * Read a single submission, including its answers.
     *
     * @param ObjectEntity $source       The `Source` whose credentials are used.
     * @param int          $formId       The Forms form id the submission belongs to.
     * @param int          $submissionId The Forms submission id.
     *
     * @return array{id: int, formId: int, userId: string, timestamp: int,
     *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}
     *
     * @throws FormsNotFoundException          When the submission does not exist upstream.
     * @throws FormsPermissionDeniedException  When the identity cannot read the submission.
     * @throws FormsUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     */
    public function getSubmission(ObjectEntity $source, int $formId, int $submissionId): array;

    /**
     * Read one page of a form's submissions.
     *
     * Pagination note: the Forms `submissions` endpoint's server-side
     * pagination contract is not schema-guaranteed either (discovery.md
     * Finding 5) — forwarded on a best-effort basis, same caveat
     * `TablesOcsClient::listRows()` carries.
     *
     * @param ObjectEntity $source   The `Source` whose credentials are used.
     * @param int          $formId   The Forms form id.
     * @param int          $page     1-based page number.
     * @param int          $pageSize Maximum submissions to return for this page.
     *
     * @return array<int, array{id: int, formId: int, userId: string, timestamp: int,
     *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}>
     *
     * @throws FormsNotFoundException          When the form does not exist upstream.
     * @throws FormsPermissionDeniedException  When the identity cannot read the form's submissions.
     * @throws FormsUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
     */
    public function listSubmissions(ObjectEntity $source, int $formId, int $page, int $pageSize): array;

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
    public function listForms(ObjectEntity $source): array;
}//end interface
