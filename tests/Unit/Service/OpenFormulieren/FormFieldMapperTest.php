<?php

/**
 * Unit tests for FormFieldMapper.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\OpenFormulieren
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

namespace OCA\OpenConnector\Tests\Unit\Service\OpenFormulieren;

use OCA\OpenConnector\Exception\MappingResolutionException;
use OCA\OpenConnector\Service\OpenFormulieren\FormFieldMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-form mapping resolver: the mapping matrix, the
 * mandatory-field config validation, and the literal-leak guard.
 *
 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */
class FormFieldMapperTest extends TestCase
{

    private FormFieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FormFieldMapper();

    }//end setUp()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-full-mapping-resolves-every-declared-field
     */
    public function testFullMappingResolvesEveryDeclaredField(): void
    {
        $fieldMapping = [
            'title'    => ['type' => 'from', 'value' => 'aanvraagType'],
            'summary'  => ['type' => 'template', 'value' => 'Aanvraag: {{aanvraagType}} — {{toelichting}}'],
            'channel'  => ['type' => 'const', 'value' => 'web'],
            'priority' => ['type' => 'const', 'value' => 'normaal'],
        ];
        $values = ['aanvraagType' => 'kapvergunning', 'toelichting' => 'Boom in tuin'];

        $this->mapper->validateConfig(fieldMapping: $fieldMapping);
        $result = $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: $values);

        $this->assertSame('kapvergunning', $result['mappedTitle']);
        $this->assertSame('Aanvraag: kapvergunning — Boom in tuin', $result['mappedSummary']);
        $this->assertSame('web', $result['mappedChannel']);
        $this->assertSame('normaal', $result['mappedPriority']);

    }//end testFullMappingResolvesEveryDeclaredField()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-partial-mapping-omits-an-undeclared-optional-field
     */
    public function testPartialMappingOmitsUndeclaredOptionalField(): void
    {
        $fieldMapping = [
            'title'   => ['type' => 'const', 'value' => 'Vergunning aanvraag'],
            'summary' => ['type' => 'const', 'value' => 'Zie bijlage'],
            'channel' => ['type' => 'const', 'value' => 'web'],
        ];

        $this->mapper->validateConfig(fieldMapping: $fieldMapping);
        $result = $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: []);

        $this->assertArrayHasKey('mappedTitle', $result);
        $this->assertArrayNotHasKey('mappedPriority', $result);

    }//end testPartialMappingOmitsUndeclaredOptionalField()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-mapping-config-missing-a-mandatory-field-is-rejected
     */
    public function testConfigMissingMandatoryFieldIsRejected(): void
    {
        $fieldMapping = [
            'title'   => ['type' => 'const', 'value' => 'Vergunning aanvraag'],
            'summary' => ['type' => 'const', 'value' => 'Zie bijlage'],
            // 'channel' deliberately absent.
        ];

        $this->expectException(MappingResolutionException::class);
        $this->expectExceptionMessageMatches('/channel/');

        $this->mapper->validateConfig(fieldMapping: $fieldMapping);

    }//end testConfigMissingMandatoryFieldIsRejected()

    /**
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-mapping-config-missing-a-mandatory-field-is-rejected
     */
    public function testConfigDeclaringUnknownTargetFieldIsRejected(): void
    {
        $fieldMapping = [
            'title'     => ['type' => 'const', 'value' => 'x'],
            'summary'   => ['type' => 'const', 'value' => 'y'],
            'channel'   => ['type' => 'const', 'value' => 'web'],
            'requester' => ['type' => 'const', 'value' => 'z'],
        ];

        $this->expectException(MappingResolutionException::class);
        $this->expectExceptionMessageMatches('/requester/');

        $this->mapper->validateConfig(fieldMapping: $fieldMapping);

    }//end testConfigDeclaringUnknownTargetFieldIsRejected()

    /**
     * The literal-leak guard: an unresolvable `from` entry MUST throw and
     * MUST NOT return the referenced key name as the value.
     *
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-unresolvable-declared-field-errors-never-leaks-the-literal
     */
    public function testUnresolvableFromEntryThrowsRatherThanLeakingTheLiteral(): void
    {
        $fieldMapping = [
            'title'   => ['type' => 'const', 'value' => 'x'],
            'summary' => ['type' => 'from', 'value' => 'toelichting'],
            'channel' => ['type' => 'const', 'value' => 'web'],
        ];

        try {
            $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: ['other' => 'value']);
            $this->fail('Expected MappingResolutionException was not thrown — a resolved value would mean the literal key name leaked through.');
        } catch (MappingResolutionException $exception) {
            $this->assertStringContainsString('toelichting', $exception->getMessage());
        }

    }//end testUnresolvableFromEntryThrowsRatherThanLeakingTheLiteral()

    /**
     * The literal-leak guard: an unresolvable `template` placeholder MUST
     * throw and MUST NOT return the unexpanded template string.
     *
     * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-unresolvable-template-placeholder-errors-never-leaks-the-template
     */
    public function testUnresolvableTemplatePlaceholderThrowsRatherThanLeakingTheTemplate(): void
    {
        $fieldMapping = [
            'title'   => ['type' => 'template', 'value' => 'Aanvraag: {{aanvraagType}}'],
            'summary' => ['type' => 'const', 'value' => 'x'],
            'channel' => ['type' => 'const', 'value' => 'web'],
        ];

        $this->expectException(MappingResolutionException::class);

        $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: []);

    }//end testUnresolvableTemplatePlaceholderThrowsRatherThanLeakingTheTemplate()

    /**
     * Directly asserts the returned value is never the unexpanded template —
     * catches the exception and inspects it did not silently succeed with a
     * literal-leaked value.
     */
    public function testTemplateNeverReturnsUnexpandedLiteralOnFailure(): void
    {
        $fieldMapping = ['summary' => ['type' => 'template', 'value' => '{{missingKey}}']];

        $threw = false;
        try {
            $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: []);
        } catch (MappingResolutionException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'An unresolvable template placeholder MUST throw, never resolve silently.');

    }//end testTemplateNeverReturnsUnexpandedLiteralOnFailure()

    public function testConstAlwaysResolvesWithNoKeyLookup(): void
    {
        $fieldMapping = ['channel' => ['type' => 'const', 'value' => 'web']];

        $result = $this->mapper->map(fieldMapping: $fieldMapping, submittedValues: []);

        $this->assertSame('web', $result['mappedChannel']);

    }//end testConstAlwaysResolvesWithNoKeyLookup()

    public function testMalformedExpressionTypeIsRejected(): void
    {
        $fieldMapping = [
            'title'   => ['type' => 'unknown-kind', 'value' => 'x'],
            'summary' => ['type' => 'const', 'value' => 'y'],
            'channel' => ['type' => 'const', 'value' => 'web'],
        ];

        $this->expectException(MappingResolutionException::class);

        $this->mapper->validateConfig(fieldMapping: $fieldMapping);

    }//end testMalformedExpressionTypeIsRejected()
}//end class
