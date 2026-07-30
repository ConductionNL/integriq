<?php

/**
 * Unit tests for OpenFormulierenIntakeService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/open-formulieren-intake/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\OpenFormulierenException;
use OCA\OpenConnector\Service\OpenFormulieren\FormFieldMapper;
use OCA\OpenConnector\Service\OpenFormulierenIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for submission ingest (mapping + attachments) and the authenticated
 * handoff trigger, including per-submission failure isolation.
 *
 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenIntakeServiceTest extends TestCase
{

    /**
     * In-memory `openformulieren_submission`/`openformulieren_form_mapping`
     * store keyed by uuid, simulating OR persistence across a test's
     * sequential saveObject()/find() calls.
     *
     * @var array<string, ObjectEntity>
     */
    private array $submissionStore = [];

    /** @var array<int, ObjectEntity> */
    private array $mappingFixtures = [];

    private int $uuidCounter = 0;

    private HandoffService $handoffService;

    private FileService $fileService;

    private function buildEntity(array $data, string $uuid): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($data);

        return $entity;

    }//end buildEntity()

    /**
     * @return ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildObjectService()
    {
        $mock = $this->getMockBuilder(ORObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mock->method('saveObject')->willReturnCallback(
            function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null) {
                if ($schema !== OpenFormulierenIntakeService::SCHEMA_SUBMISSION) {
                    return $this->buildEntity((array) $object, ($uuid ?? 'other-'.(++$this->uuidCounter)));
                }

                $resolvedUuid = ($uuid ?? 'submission-'.(++$this->uuidCounter));
                $entity       = $this->buildEntity((array) $object, $resolvedUuid);
                $this->submissionStore[$resolvedUuid] = $entity;

                return $entity;
            }
        );

        $mock->method('find')->willReturnCallback(
            function ($id, ?string $register=null, ?string $schema=null) {
                if ($schema === OpenFormulierenIntakeService::SCHEMA_SUBMISSION) {
                    return ($this->submissionStore[(string) $id] ?? null);
                }

                // Handoff-target lookups (post-success attachment copy): any
                // non-submission find() returns a synthetic target entity.
                return $this->buildEntity(['title' => 'Case'], (string) $id);
            }
        );

        $mock->method('findAll')->willReturnCallback(
            function (array $config=[]) {
                $schema = ($config['filters']['schema'] ?? null);
                if ($schema === OpenFormulierenIntakeService::SCHEMA_MAPPING) {
                    return ['results' => $this->mappingFixtures, 'total' => count($this->mappingFixtures)];
                }

                return ['results' => [], 'total' => 0];
            }
        );

        return $mock;

    }//end buildObjectService()

    private function buildService(?Client $httpClient=null): OpenFormulierenIntakeService
    {
        $objectService = $this->buildObjectService();

        $this->handoffService = $this->getMockBuilder(HandoffService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $client = ($httpClient ?? new Client(['handler' => HandlerStack::create(new MockHandler([]))]));

        return new OpenFormulierenIntakeService(
            objectService: $objectService,
            handoffService: $this->handoffService,
            fileService: $this->fileService,
            fieldMapper: new FormFieldMapper(),
            httpClient: $client,
            logger: $this->createMock(LoggerInterface::class)
        );

    }//end buildService()

    private function addMappingFixture(string $formSlug, array $fieldMapping, bool $enabled=true): void
    {
        $this->mappingFixtures[] = $this->buildEntity(
            ['formSlug' => $formSlug, 'fieldMapping' => $fieldMapping, 'isEnabled' => $enabled],
            'mapping-'.$formSlug
        );

    }//end addMappingFixture()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-successful-ingest-reaches-mapped
     */
    public function testIngestReachesMappedForValidSubmission(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'from', 'value' => 'aanvraagType'],
                'summary' => ['type' => 'const', 'value' => 'Zie bijlage'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );
        $service = $this->buildService();

        $submission = $service->ingest(
            formSlug: 'vergunning-aanvraag',
            formUuid: null,
            submissionMeta: ['uuid' => 'of-sub-1'],
            values: ['aanvraagType' => 'kapvergunning']
        );

        $this->assertSame('mapped', $submission->getObject()['status']);
        $this->assertSame('kapvergunning', $submission->getObject()['mappedTitle']);

    }//end testIngestReachesMappedForValidSubmission()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-unknown-form-slug-fails-closed
     */
    public function testIngestFailsClosedForUnknownFormSlug(): void
    {
        $service = $this->buildService();

        $submission = $service->ingest(
            formSlug: 'no-such-form',
            formUuid: null,
            submissionMeta: [],
            values: []
        );

        $this->assertSame('failed', $submission->getObject()['status']);
        $this->assertStringContainsString('no-such-form', (string) $submission->getObject()['errorDetail']);

    }//end testIngestFailsClosedForUnknownFormSlug()

    /**
     * Per-submission isolation: a mandatory-field resolution failure on one
     * submission MUST NOT affect a second, valid submission processed after it.
     *
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-mandatory-field-resolution-failure-isolates-to-one-submission
     */
    public function testMandatoryFieldResolutionFailureIsolatesToOneSubmission(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'from', 'value' => 'aanvraagType'],
                'summary' => ['type' => 'const', 'value' => 'Zie bijlage'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );
        $service = $this->buildService();

        $first = $service->ingest(
            formSlug: 'vergunning-aanvraag',
            formUuid: null,
            submissionMeta: ['uuid' => 'of-sub-1'],
            values: []
        // no aanvraagType key => mandatory field unresolvable.
        );

        $second = $service->ingest(
            formSlug: 'vergunning-aanvraag',
            formUuid: null,
            submissionMeta: ['uuid' => 'of-sub-2'],
            values: ['aanvraagType' => 'sloopvergunning']
        );

        $this->assertSame('failed', $first->getObject()['status']);
        $this->assertSame('mapped', $second->getObject()['status']);
        $this->assertNotSame($first->getUuid(), $second->getUuid());

    }//end testMandatoryFieldResolutionFailureIsolatesToOneSubmission()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-attachment-fetch-failure-does-not-fail-the-submission
     */
    public function testAttachmentFetchFailureDoesNotFailSubmission(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'const', 'value' => 'x'],
                'summary' => ['type' => 'const', 'value' => 'y'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );

        $mock       = new MockHandler([new Response(500, [], 'boom')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $service    = $this->buildService(httpClient: $httpClient);

        $submission = $service->ingest(
            formSlug: 'vergunning-aanvraag',
            formUuid: null,
            submissionMeta: [],
            values: [],
            attachmentRefs: [['key' => 'bijlage', 'url' => 'https://example.test/f.pdf', 'filename' => 'f.pdf']]
        );

        $this->assertSame('mapped', $submission->getObject()['status']);
        $attachments = $submission->getObject()['attachments'];
        $this->assertCount(1, $attachments);
        $this->assertSame('failed', $attachments[0]['status']);

    }//end testAttachmentFetchFailureDoesNotFailSubmission()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-best-effort-attachment-handling-req-005
     */
    public function testSuccessfullyFetchedAttachmentIsStoredAndRecorded(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'const', 'value' => 'x'],
                'summary' => ['type' => 'const', 'value' => 'y'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );

        $mock       = new MockHandler([new Response(200, [], 'file-bytes')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $service    = $this->buildService(httpClient: $httpClient);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);
        $this->fileService->method('addFile')->willReturn($file);

        $submission = $service->ingest(
            formSlug: 'vergunning-aanvraag',
            formUuid: null,
            submissionMeta: [],
            values: [],
            attachmentRefs: [['key' => 'bijlage', 'url' => 'https://example.test/f.pdf', 'filename' => 'f.pdf']]
        );

        $attachments = $submission->getObject()['attachments'];
        $this->assertSame('fetched', $attachments[0]['status']);
        $this->assertSame(42, $attachments[0]['fileId']);

    }//end testSuccessfullyFetchedAttachmentIsStoredAndRecorded()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-authenticated-handoff-succeeds
     */
    public function testHandoffSucceedsAndUpdatesSubmission(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'const', 'value' => 'x'],
                'summary' => ['type' => 'const', 'value' => 'y'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );
        $service    = $this->buildService();
        $submission = $service->ingest(formSlug: 'vergunning-aanvraag', formUuid: null, submissionMeta: [], values: []);
        $this->assertSame('mapped', $submission->getObject()['status']);

        // HandoffService itself is mocked here (this test verifies
        // OpenFormulierenIntakeService's own orchestration, not OpenRegister's
        // engine) — in production, the real engine's own onSuccess.set is what
        // flips status=handed_off; that behaviour is covered by OpenRegister's
        // own HandoffServiceTest, not duplicated here.
        $this->handoffService->method('execute')->willReturn(
            [
                'status'        => 'executed',
                'target'        => ['register' => 'procest', 'schema' => 'case', 'uuid' => 'case-uuid-1'],
                'correlationId' => 'corr-1',
            ]
        );

        $result = $service->handoff(submissionUuid: $submission->getUuid());

        $this->assertSame('executed', $result['status']);
        $this->assertSame('case-uuid-1', $result['target']['uuid']);

        $stored = $this->submissionStore[$submission->getUuid()]->getObject();
        $this->assertSame('corr-1', $stored['correlationId']);
        $this->assertSame('case-uuid-1', $stored['targetCase']['uuid']);

    }//end testHandoffSucceedsAndUpdatesSubmission()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-004
     */
    public function testHandoffRejectsWhenSubmissionNotYetMapped(): void
    {
        $service = $this->buildService();

        // Directly seed a "received"-status submission (skip ingest()).
        $this->submissionStore['sub-received'] = $this->buildEntity(['status' => 'received'], 'sub-received');

        $this->expectException(OpenFormulierenException::class);

        $service->handoff(submissionUuid: 'sub-received');

    }//end testHandoffRejectsWhenSubmissionNotYetMapped()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-handoff-failure-isolates-to-the-triggering-submission
     */
    public function testHandoffFailureMarksSubmissionFailedAndRethrows(): void
    {
        $this->addMappingFixture(
            'vergunning-aanvraag',
            [
                'title'   => ['type' => 'const', 'value' => 'x'],
                'summary' => ['type' => 'const', 'value' => 'y'],
                'channel' => ['type' => 'const', 'value' => 'web'],
            ]
        );
        $service    = $this->buildService();
        $submission = $service->ingest(formSlug: 'vergunning-aanvraag', formUuid: null, submissionMeta: [], values: []);

        $this->handoffService->method('execute')->willThrowException(
            new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'no provider')
        );

        $this->expectException(HandoffException::class);

        try {
            $service->handoff(submissionUuid: $submission->getUuid());
        } finally {
            $this->assertSame('failed', $this->submissionStore[$submission->getUuid()]->getObject()['status']);
        }

    }//end testHandoffFailureMarksSubmissionFailedAndRethrows()
}//end class
