<?php

/**
 * OpenConnector Forms Synchronization Adapter.
 *
 * Sits between `SynchronizationService`'s `nextcloud-form` source-fetch
 * branch (and `EventService::dispatchMappingAction()`'s outbound path) and
 * {@see FormsClientInterface}: runs the source-side submission pagination
 * loop, and feature-detects the Forms app via `IAppManager` only (design.md
 * Decision 6 — never a direct `OCA\Forms\*` reference). Mirrors
 * `TablesSyncAdapter`'s method names/shapes exactly.
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Forms;

use OCA\OpenConnector\Exception\FormsFeatureDisabledException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Pagination and feature-detection layer between the synchronization/event
 * engines and the raw Forms API client.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md
 */
class FormsSyncAdapter
{

    /**
     * Forms app id, as registered in `appinfo/info.xml` of nextcloud/forms.
     *
     * @var string
     */
    private const FORMS_APP_ID = 'forms';

    /**
     * Safety cap on the number of pages fetched per source run — mirrors
     * `TablesSyncAdapter::MAX_PAGES`, so a misbehaving/non-paginating
     * upstream can never loop forever.
     *
     * @var int
     */
    private const MAX_PAGES = 500;

    /**
     * Constructor.
     *
     * @param FormsClientInterface $client     The raw Forms API client.
     * @param IAppManager          $appManager Feature-detection (never a direct OCA\Forms\* reference).
     * @param LoggerInterface      $logger     Logger for pagination diagnostics.
     */
    public function __construct(
        private readonly FormsClientInterface $client,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
    public function isEnabled(?IUser $user=null): bool
    {
        if ($user !== null) {
            return $this->appManager->isEnabledForUser(self::FORMS_APP_ID, $user);
        }

        return $this->appManager->isEnabledForUser(self::FORMS_APP_ID);

    }//end isEnabled()

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
    public function assertEnabled(?IUser $user=null): void
    {
        if ($this->isEnabled(user: $user) === false) {
            throw new FormsFeatureDisabledException(
                message: 'The Forms app is not enabled — nextcloud-form synchronizations/mappings require it.'
            );
        }

    }//end assertEnabled()

    /**
     * Fetch every submission of a form, paginating until exhausted.
     *
     * Each returned submission is exposed with `id` at the top level so the
     * existing `getOriginId()` default `idPosition` ('id') resolves the
     * Forms submission id with no adapter-specific override (REQ-002).
     *
     * @param ObjectEntity $source   The `Source` whose credentials are used.
     * @param int          $formId   The Forms form id.
     * @param int          $pageSize Submissions requested per page (default 100).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
     */
    public function fetchAllSubmissions(ObjectEntity $source, int $formId, int $pageSize=100): array
    {
        $submissions    = [];
        $page           = 1;
        $firstIdOfFirst = null;

        while ($page <= self::MAX_PAGES) {
            $batch = $this->client->listSubmissions(source: $source, formId: $formId, page: $page, pageSize: $pageSize);
            if (count($batch) === 0) {
                break;
            }

            $firstId = ($batch[0]['id'] ?? null);
            if ($page === 1) {
                $firstIdOfFirst = $firstId;
            } else if ($firstId === $firstIdOfFirst) {
                // The upstream returned the same first submission again — it
                // does not honour pagination. Stop here rather than looping
                // forever.
                $this->logger->warning(
                    'FormsSyncAdapter: submissions endpoint did not appear to honour pagination; stopping',
                    ['formId' => $formId, 'page' => $page]
                );
                break;
            }

            foreach ($batch as $submission) {
                $submissions[] = $submission;
            }

            if (count($batch) < $pageSize) {
                break;
            }

            $page++;
        }//end while

        return $submissions;

    }//end fetchAllSubmissions()

    /**
     * Fetch a submission's full answers (with the form's questions, for the
     * outbound answer-resolution path) — `EventService::dispatchMappingAction()`'s
     * entry point into this adapter (design.md Decision 2).
     *
     * @param ObjectEntity $source       The `Source` whose credentials are used.
     * @param int          $formId       The Forms form id.
     * @param int          $submissionId The Forms submission id.
     *
     * @return array{id: int, formId: int, userId: string, timestamp: int,
     *               answers: array<int, array{id: int, questionId: int, questionName: ?string, text: mixed}>}
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     */
    public function fetchSubmission(ObjectEntity $source, int $formId, int $submissionId): array
    {
        return $this->client->getSubmission(source: $source, formId: $formId, submissionId: $submissionId);

    }//end fetchSubmission()

    /**
     * Fetch a form's questions (id/text/name/type), used by both the
     * outbound answer resolver and the editor's field-mapping helper.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $formId The Forms form id.
     *
     * @return array{id: int, title: string, questions: array<int, array{id: int, text: string,
     *               name: string, type: string}>}
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    public function fetchForm(ObjectEntity $source, int $formId): array
    {
        return $this->client->getForm(source: $source, formId: $formId);

    }//end fetchForm()

    /**
     * List the forms accessible to a Source's identity, for the editor's form picker.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     *
     * @return array<int, array{id: int, title: string}>
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    public function listFormsForEditor(ObjectEntity $source): array
    {
        return $this->client->listForms(source: $source);

    }//end listFormsForEditor()

    /**
     * List a form's questions with type metadata, for the editor's
     * field-mapping helper.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $formId The Forms form id.
     *
     * @return array<int, array{id: int, text: string, name: string, type: string}>
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    public function listQuestionsForEditor(ObjectEntity $source, int $formId): array
    {
        $form = $this->client->getForm(source: $source, formId: $formId);

        return $form['questions'];

    }//end listQuestionsForEditor()
}//end class
