<?php

/**
 * A synchronization's writeBack declaration filled from the outcome of an attempt.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\OutcomeWriteBack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The template filling on its own: which declared fields come out, what a
 * placeholder reads, and how a failed answer becomes a message.
 */
#[CoversClass(OutcomeWriteBack::class)]
class OutcomeWriteBackTest extends TestCase {

	/**
	 * The outcome of an accepted push.
	 *
	 * @var array<string, mixed>
	 */
	private const CONTEXT = [
		'status' => 201,
		'targetId' => 'doc-7',
		'response' => ['url' => 'https://zaken.example.nl/documenten/7', 'versie' => 3, 'links' => ['a', 'b'], 'actief' => true],
	];

	public function testAWholePlaceholderKeepsItsTypeAndTextIsFilledAsAString(): void {
		$writeBack = [
			OutcomeWriteBack::SUCCESS => [
				'documentUrl' => '{{ response.url }}',
				'version' => '{{response.versie}}',
				'links' => '{{ response.links }}',
				'summary' => 'Stored as {{ targetId }} (HTTP {{ status }}), version {{ response.versie }}',
				'processingStatus' => 'written_back',
				'attempts' => 1,
				'flags' => ['kept' => true],
			],
		];

		$this->assertSame(
			[
				'documentUrl' => 'https://zaken.example.nl/documenten/7',
				'version' => 3,
				'links' => ['a', 'b'],
				'summary' => 'Stored as doc-7 (HTTP 201), version 3',
				'processingStatus' => 'written_back',
				'attempts' => 1,
				'flags' => ['kept' => true],
			],
			(new OutcomeWriteBack())->fields(writeBack: $writeBack, outcome: OutcomeWriteBack::SUCCESS, context: self::CONTEXT)
		);
	}//end testAWholePlaceholderKeepsItsTypeAndTextIsFilledAsAString()

	public function testAPlaceholderInTextThatReadsNothingOrAListBecomesEmpty(): void {
		$writeBack = [
			OutcomeWriteBack::FAILURE => [
				'note' => 'Failed: {{ error.message }}; links {{ response.links }}; active {{ response.actief }}.',
				'missing' => '{{ error.code }}',
			],
		];

		$this->assertSame(
			['note' => 'Failed: ; links ; active 1.', 'missing' => null],
			(new OutcomeWriteBack())->fields(writeBack: $writeBack, outcome: OutcomeWriteBack::FAILURE, context: self::CONTEXT)
		);
	}//end testAPlaceholderInTextThatReadsNothingOrAListBecomesEmpty()

	public function testFieldsWithoutAUsableNameAndUndeclaredOutcomesAreLeftOut(): void {
		$writeBack = [
			OutcomeWriteBack::SUCCESS => [0 => 'numbered', '' => 'empty name', 'kept' => 'yes'],
			OutcomeWriteBack::FAILURE => 'not a list',
		];
		$writer = new OutcomeWriteBack();

		$this->assertSame(['kept' => 'yes'], $writer->fields(writeBack: $writeBack, outcome: OutcomeWriteBack::SUCCESS, context: []));
		$this->assertSame([], $writer->fields(writeBack: $writeBack, outcome: OutcomeWriteBack::FAILURE, context: []));
		$this->assertSame([], $writer->fields(writeBack: null, outcome: OutcomeWriteBack::SUCCESS, context: []));
		$this->assertSame([], $writer->fields(writeBack: [], outcome: OutcomeWriteBack::SUCCESS, context: []));
	}//end testFieldsWithoutAUsableNameAndUndeclaredOutcomesAreLeftOut()

	public function testAFailedAnswerGivesItsDetailMessageOrTitleElseTheStatus(): void {
		$writer = new OutcomeWriteBack();

		$this->assertSame('Ongeldig informatieobjecttype', $writer->messageFromAnswer(body: '{"detail":" Ongeldig informatieobjecttype ","title":"Bad request"}', status: 400));
		$this->assertSame('Bad request', $writer->messageFromAnswer(body: '{"detail":"  ","message":"","title":"Bad request"}', status: 400));
		$this->assertSame('Quota exceeded', $writer->messageFromAnswer(body: '{"message":"Quota exceeded","detail":12}', status: 429));
		$this->assertSame('The target answered HTTP 502.', $writer->messageFromAnswer(body: '<html>Bad gateway</html>', status: 502));
		$this->assertSame('The target answered HTTP 0.', $writer->messageFromAnswer(body: null, status: null));
	}//end testAFailedAnswerGivesItsDetailMessageOrTitleElseTheStatus()

	public function testALongMessageIsCutAtAThousandCharacters(): void {
		$writer = new OutcomeWriteBack();
		$long = str_repeat('é', 1200);

		$this->assertSame(str_repeat('é', 1000), $writer->truncate(message: $long));
		$this->assertSame(1000, mb_strlen($writer->messageFromAnswer(body: (string)json_encode(['detail' => $long]), status: 400)));
		$this->assertSame('kort', $writer->truncate(message: 'kort'));
	}//end testALongMessageIsCutAtAThousandCharacters()
}//end class
