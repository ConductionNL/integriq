<?php
/**
 * Unit tests for the hitl-approval-rule-action register fragment and its
 * ADR-023 action-matrix seed entries.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/hitl-approval-rule-action/design.md#database-changes
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Settings;

use OCA\OpenConnector\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the `approval_request` register.d fragment (Task 1), its
 * declarative ops-visibility notification (Task 6), its seed data
 * (Task 15), and the `approval.approve`/`approval.reject` ADR-023
 * action-matrix seed entries (Task 2).
 *
 * @spec openspec/changes/hitl-approval-rule-action/design.md#database-changes
 */
class HitlApprovalRegisterFragmentTest extends TestCase
{

    /**
     * Path to the fragment under test.
     *
     * @var string
     */
    private const FRAGMENT_PATH = __DIR__.'/../../../lib/Settings/register.d/hitl-approval-rule-action.json';

    /**
     * Path to the ADR-023 action-matrix seed under test.
     *
     * @var string
     */
    private const ACTIONS_SEED_PATH = __DIR__.'/../../../lib/actions.seed.json';

    /**
     * Invoke the private static InitializeRegister::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()

    /**
     * Decode the fragment file once per test.
     *
     * @return array<mixed>
     */
    private function fragment(): array
    {
        $this->assertFileExists(self::FRAGMENT_PATH);

        $raw = file_get_contents(self::FRAGMENT_PATH);
        $this->assertNotFalse($raw);

        $fragment = json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'fragment MUST be valid JSON: '.json_last_error_msg());

        return $fragment;

    }//end fragment()

    /**
     * Task 1: the fragment is well-formed JSON and declares exactly the
     * `approval_request` schema, backed by a non-empty properties block
     * covering the fields design.md's Database Changes table requires.
     *
     * @return void
     */
    public function testFragmentIsWellFormedAndDeclaresApprovalRequestSchema(): void
    {
        $fragment = $this->fragment();

        $schemas = ($fragment['components']['schemas'] ?? []);
        $this->assertSame(['approval_request'], array_keys($schemas));

        $schema = $schemas['approval_request'];
        $this->assertArrayHasKey('title', $schema);
        $this->assertArrayHasKey('description', $schema);

        $properties = ($schema['properties'] ?? []);
        foreach (
            [
                'uuid',
                'status',
                'endpointId',
                'ruleId',
                'timing',
                'resumeOrder',
                'snapshot',
                'synchronizationId',
                'requesterUserId',
                'approverGroup',
                'onReject',
                'onTimeout',
                'expiresAt',
                'approverUserId',
                'comment',
                'approvedAt',
                'rejectedAt',
                'resumeResult',
                'consumedAt',
            ] as $field
        ) {
            $this->assertArrayHasKey($field, $properties, "design.md's Database Changes table requires field '$field'");
        }

        $this->assertSame(
            ['pending', 'approved', 'rejected', 'expired', 'dead_letter', 'error'],
            $properties['status']['enum'] ?? null
        );

    }//end testFragmentIsWellFormedAndDeclaresApprovalRequestSchema()

    /**
     * Task 1: the fragment attaches `approval_request` onto the
     * `openconnector` register's schema list AND `components.schemas` when
     * deep-merged onto a representative base descriptor — the two things
     * OpenRegister's ImportHandler requires (per EudiRegisterFragmentTest's
     * established precedent) — and does not disturb a disjoint pre-existing
     * schema (union by key, ADR-037), i.e. does not redeclare an existing slug.
     *
     * @return void
     */
    public function testMergingFragmentAttachesApprovalRequestWithoutRedeclaringExistingSlugs(): void
    {
        $fragment = $this->fragment();

        $base = [
            'components' => [
                'registers' => [
                    'openconnector' => [
                        'slug'    => 'openconnector',
                        'schemas' => ['source', 'consumer', 'endpoint', 'rule', 'synchronization'],
                    ],
                ],
                'schemas'   => [
                    'source' => ['type' => 'object'],
                ],
            ],
        ];

        $merged = $this->merge($base, $fragment);

        $registerSchemas = $merged['components']['registers']['openconnector']['schemas'];
        $this->assertSame(
            ['source', 'consumer', 'endpoint', 'rule', 'synchronization', 'approval_request'],
            $registerSchemas,
            'the base register schema list must be preserved with approval_request appended, not redeclared in place of an existing slug'
        );

        $this->assertArrayHasKey('approval_request', $merged['components']['schemas']);
        // A disjoint fragment must never disturb a pre-existing schema.
        $this->assertSame(['type' => 'object'], $merged['components']['schemas']['source']);

    }//end testMergingFragmentAttachesApprovalRequestWithoutRedeclaringExistingSlugs()

    /**
     * The fragment does not touch `openconnector_register.json` itself.
     *
     * @return void
     */
    public function testFragmentDoesNotModifyTheDescriptorFile(): void
    {
        $descriptorPath = __DIR__.'/../../../lib/Settings/openconnector_register.json';
        $descriptor     = json_decode((string) file_get_contents($descriptorPath), true);

        $this->assertArrayNotHasKey(
            'approval_request',
            $descriptor['components']['schemas'],
            'the coverage-gated descriptor file must not declare approval_request directly'
        );

    }//end testFragmentDoesNotModifyTheDescriptorFile()

    /**
     * Task 6: the schema declares a compliant, declarative
     * `x-openregister-notifications` `created` rule targeting the static
     * `openconnector-ops` group, matching the dialect shape used by every
     * other occurrence in this app (design.md Decision 4).
     *
     * @return void
     */
    public function testDeclarativeOpsVisibilityNotificationTargetsOpsGroup(): void
    {
        $schema = $this->fragment()['components']['schemas']['approval_request'];

        $rule = ($schema['x-openregister-notifications']['created'] ?? null);
        $this->assertIsArray($rule, 'a declarative created-trigger notification rule must be declared');

        $this->assertTrue($rule['enabled'] ?? false);
        $this->assertSame('created', $rule['trigger']['type'] ?? null);
        $this->assertContains('nc-notification', $rule['channels'] ?? []);

        $recipients = ($rule['recipients'][0] ?? []);
        $this->assertSame('groups', $recipients['kind'] ?? null);
        $this->assertSame(['openconnector-ops'], $recipients['groups'] ?? null);

        $this->assertArrayHasKey('nl', $rule['subject'] ?? []);
        $this->assertArrayHasKey('en', $rule['subject'] ?? []);

    }//end testDeclarativeOpsVisibilityNotificationTargetsOpsGroup()

    /**
     * Task 15: three `approval_request` seed objects exist (pending /
     * approved / rejected), matching design.md's Seed Data table.
     *
     * @return void
     */
    public function testSeedDataDeclaresThreeRepresentativeStates(): void
    {
        $schema = $this->fragment()['components']['schemas']['approval_request'];
        $seed   = ($schema['x-openregister-seed'] ?? []);

        $this->assertCount(3, $seed);

        $bySlug = [];
        foreach ($seed as $row) {
            $slug           = ($row['@self']['slug'] ?? '');
            $bySlug[$slug] = $row;
        }

        $this->assertArrayHasKey('approval-woo-publish-pending', $bySlug);
        $this->assertSame('pending', $bySlug['approval-woo-publish-pending']['status']);
        $this->assertSame('woo-approvers', $bySlug['approval-woo-publish-pending']['approverGroup']);

        $this->assertArrayHasKey('approval-berichtenbox-approved', $bySlug);
        $this->assertSame('approved', $bySlug['approval-berichtenbox-approved']['status']);
        $this->assertSame('berichtenbox-approvers', $bySlug['approval-berichtenbox-approved']['approverGroup']);

        $this->assertArrayHasKey('approval-woo-publish-rejected', $bySlug);
        $this->assertSame('rejected', $bySlug['approval-woo-publish-rejected']['status']);
        $this->assertNotEmpty($bySlug['approval-woo-publish-rejected']['comment'] ?? '');

    }//end testSeedDataDeclaresThreeRepresentativeStates()

    /**
     * Task 2: `approval.approve` / `approval.reject` exist in the ADR-023
     * action-matrix seed, both defaulting to `["admin"]`.
     *
     * @return void
     */
    public function testActionMatrixSeedDeclaresApprovalActionsDefaultingToAdmin(): void
    {
        $this->assertFileExists(self::ACTIONS_SEED_PATH);

        $raw  = file_get_contents(self::ACTIONS_SEED_PATH);
        $seed = json_decode((string) $raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $matrix = ($seed['actions'] ?? $seed);

        $this->assertArrayHasKey('approval.approve', $matrix);
        $this->assertSame(['admin'], $matrix['approval.approve']);

        $this->assertArrayHasKey('approval.reject', $matrix);
        $this->assertSame(['admin'], $matrix['approval.reject']);

    }//end testActionMatrixSeedDeclaresApprovalActionsDefaultingToAdmin()
}//end class
