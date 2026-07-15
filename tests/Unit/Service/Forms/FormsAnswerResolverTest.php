<?php

/**
 * Unit tests for FormsAnswerResolver.
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

use OCA\OpenConnector\Exception\FormsConfigException;
use OCA\OpenConnector\Service\Forms\FormsAnswerResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for answer-by-question resolution, type-aware coercion, and the
 * ambiguity guard (nextcloud-forms-connector REQ-003).
 *
 * @spec openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */
class FormsAnswerResolverTest extends TestCase
{

    /**
     * @var FormsAnswerResolver
     */
    private FormsAnswerResolver $resolver;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FormsAnswerResolver();

    }//end setUp()

    /**
     * TC-7: resolution by numeric question id.
     *
     * @return void
     */
    public function testResolveByNumericQuestionId(): void
    {
        $questions = [['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short']];
        $answers   = [['id' => 1, 'questionId' => 7, 'questionName' => null, 'text' => 'Acme BV']];

        $result = $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: 7);

        $this->assertSame('Acme BV', $result);

    }//end testResolveByNumericQuestionId()

    /**
     * TC-7 variant: a numeric-string reference resolves identically to an int.
     *
     * @return void
     */
    public function testResolveByNumericStringQuestionId(): void
    {
        $questions = [['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short']];
        $answers   = [['id' => 1, 'questionId' => 7, 'questionName' => null, 'text' => 'Acme BV']];

        $result = $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: '7');

        $this->assertSame('Acme BV', $result);

    }//end testResolveByNumericStringQuestionId()

    /**
     * TC-8: resolution by unambiguous question text.
     *
     * @return void
     */
    public function testResolveByUnambiguousQuestionText(): void
    {
        $questions = [['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short']];
        $answers   = [['id' => 1, 'questionId' => 7, 'questionName' => null, 'text' => 'Acme BV']];

        $result = $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: 'Company name');

        $this->assertSame('Acme BV', $result);

    }//end testResolveByUnambiguousQuestionText()

    /**
     * TC-9: ambiguous question text is a hard config error, never a guess.
     *
     * @return void
     */
    public function testAmbiguousQuestionTextThrowsNamingBothIds(): void
    {
        $questions = [
            ['id' => 12, 'text' => 'Comments', 'name' => '', 'type' => 'long'],
            ['id' => 19, 'text' => 'Comments', 'name' => '', 'type' => 'long'],
        ];
        $answers = [
            ['id' => 1, 'questionId' => 12, 'questionName' => null, 'text' => 'foo'],
            ['id' => 2, 'questionId' => 19, 'questionName' => null, 'text' => 'bar'],
        ];

        $this->expectException(FormsConfigException::class);
        $this->expectExceptionMessageMatches('/Comments.*12.*19|Comments.*19.*12/s');

        $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: 'Comments');

    }//end testAmbiguousQuestionTextThrowsNamingBothIds()

    /**
     * TC-10: a multiple-choice question resolves to an array.
     *
     * @return void
     */
    public function testMultipleTypeQuestionResolvesToArray(): void
    {
        $questions = [['id' => 4, 'text' => 'Interested in', 'name' => '', 'type' => 'multiple']];
        $answers   = [
            ['id' => 1, 'questionId' => 4, 'questionName' => null, 'text' => 'Red'],
            ['id' => 2, 'questionId' => 4, 'questionName' => null, 'text' => 'Blue'],
        ];

        $result = $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: 4);

        $this->assertSame(['Red', 'Blue'], $result);

    }//end testMultipleTypeQuestionResolvesToArray()

    /**
     * A `multiple_unique`-type question also resolves to an array (Finding 2).
     *
     * @return void
     */
    public function testMultipleUniqueTypeQuestionResolvesToArray(): void
    {
        $questions = [['id' => 5, 'text' => 'Pick one region', 'name' => '', 'type' => 'multiple_unique']];
        $answers   = [['id' => 1, 'questionId' => 5, 'questionName' => null, 'text' => 'North']];

        $result = $this->resolver->resolve(questions: $questions, answers: $answers, questionRef: 5);

        $this->assertSame(['North'], $result);

    }//end testMultipleUniqueTypeQuestionResolvesToArray()

    /**
     * A `multiple`-type question with zero matching rows resolves to an empty array.
     *
     * @return void
     */
    public function testMultipleTypeQuestionWithNoAnswersResolvesToEmptyArray(): void
    {
        $questions = [['id' => 4, 'text' => 'Interested in', 'name' => '', 'type' => 'multiple']];

        $result = $this->resolver->resolve(questions: $questions, answers: [], questionRef: 4);

        $this->assertSame([], $result);

    }//end testMultipleTypeQuestionWithNoAnswersResolvesToEmptyArray()

    /**
     * TC unnumbered: an unanswered optional question resolves to null.
     *
     * @return void
     */
    public function testUnansweredQuestionResolvesToNull(): void
    {
        $questions = [['id' => 9, 'text' => 'Optional feedback', 'name' => '', 'type' => 'long']];

        $result = $this->resolver->resolve(questions: $questions, answers: [], questionRef: 9);

        $this->assertNull($result);

    }//end testUnansweredQuestionResolvesToNull()

    /**
     * A text reference matching zero questions resolves to null (not an error).
     *
     * @return void
     */
    public function testUnknownQuestionTextResolvesToNull(): void
    {
        $questions = [['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short']];

        $result = $this->resolver->resolve(questions: $questions, answers: [], questionRef: 'Does not exist');

        $this->assertNull($result);

    }//end testUnknownQuestionTextResolvesToNull()

    /**
     * A numeric id reference not present in the form's `questions` list still
     * resolves directly against `answers[].questionId` (id-matching never
     * needs the question list — design.md Decision 3 step 1), defaulting to
     * scalar coercion when the question's type is unknown.
     *
     * @return void
     */
    public function testNumericReferenceResolvesEvenWithoutMatchingQuestionEntry(): void
    {
        $answers = [['id' => 1, 'questionId' => 42, 'questionName' => null, 'text' => 'hello']];

        $result = $this->resolver->resolve(questions: [], answers: $answers, questionRef: 42);

        $this->assertSame('hello', $result);

    }//end testNumericReferenceResolvesEvenWithoutMatchingQuestionEntry()
}//end class
