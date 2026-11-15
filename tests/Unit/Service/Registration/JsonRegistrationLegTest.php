<?php

/**
 * Unit tests for the json registration leg.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use OCA\Integriq\Service\Registration\JsonRegistrationLeg;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One POST of the mapped body, checked first against the target's schema.
 */
#[CoversClass(JsonRegistrationLeg::class)]
#[UsesClass(RegistrationRefusal::class)]
#[UsesClass(JsonSchemaChecker::class)]
#[UsesClass(ValidationOutcome::class)]
class JsonRegistrationLegTest extends TestCase {

	/**
	 * A target posting to /intake on source src-1, requiring a string subject.
	 *
	 * @var array<string,mixed>
	 */
	private const TARGET = [
		'kind' => 'json',
		'sources' => ['endpoint' => 'src-1'],
		'json' => ['path' => '/intake', 'schema' => ['type' => 'object', 'required' => ['subject'], 'properties' => ['subject' => ['type' => 'string']]]],
	];

	/**
	 * The leg over a transport that answers the given status and body.
	 *
	 * @param int        $status The answer status.
	 * @param mixed      $data   The decoded answer.
	 * @param array|null $calls  Receives each send.
	 *
	 * @return JsonRegistrationLeg
	 */
	private function leg(int $status, mixed $data, ?array &$calls = null): JsonRegistrationLeg {
		$calls     = [];
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(function (string $sourceId, string $method, string $address, array $options = []) use ($status, $data, &$calls): array {
			$calls[] = [$sourceId, $method, $address, $options];
			return ['status' => $status, 'data' => $data, 'raw' => json_encode($data)];
		});

		return new JsonRegistrationLeg($transport, new JsonSchemaChecker());
	}//end leg()

	/**
	 * A body that matches is posted once to the path, and the answer's id is the reference.
	 *
	 * @return void
	 */
	public function testAMatchingBodyIsPostedOnce(): void {
		$result = $this->leg(201, ['id' => 77, 'url' => 'https://intake.example/77'], $calls)
			->register(target: self::TARGET, mapped: ['subject' => 'milieu'], handover: ['reference' => 'OF-1'], steps: []);

		$this->assertSame([['src-1', 'POST', '/intake', ['json' => ['subject' => 'milieu']]]], $calls);
		$this->assertSame('77', $result['externalReference']);
		$this->assertSame('https://intake.example/77', $result['externalUrl']);
		$this->assertSame([['step' => 'posted', 'reference' => '77', 'url' => 'https://intake.example/77']], $result['steps']);
	}//end testAMatchingBodyIsPostedOnce()

	/**
	 * An answer without an id gives the submission reference as the external reference.
	 *
	 * @return void
	 */
	public function testWithoutAnIdTheSubmissionReferenceIsTheReference(): void {
		$result = $this->leg(204, null)->register(target: self::TARGET, mapped: ['subject' => 'milieu'], handover: ['reference' => 'OF-1'], steps: []);

		$this->assertSame(['OF-1', ''], [$result['externalReference'], $result['externalUrl']]);
	}//end testWithoutAnIdTheSubmissionReferenceIsTheReference()

	/**
	 * A body that does not match the schema is mapping-failed and nothing is posted.
	 *
	 * @return void
	 */
	public function testABodyThatDoesNotMatchIsRefusedWithoutAPost(): void {
		$leg = $this->leg(201, ['id' => 1], $calls);

		try {
			$leg->register(target: self::TARGET, mapped: ['subject' => 12], handover: ['reference' => 'OF-1'], steps: []);
			$this->fail('A mismatching body must be refused');
		} catch (RegistrationRefusal $refusal) {
			$this->assertSame(['mapping-failed', false], [$refusal->getReason(), $refusal->isRetryable()]);
			$this->assertStringContainsString('/subject', $refusal->getMessage());
		}

		$this->assertSame([], $calls);
	}//end testABodyThatDoesNotMatchIsRefusedWithoutAPost()

	/**
	 * A 503 is source-unavailable and retryable; a 400 is outside-refused and not.
	 *
	 * @return void
	 */
	public function testTheOutsideStatusDecidesRetry(): void {
		foreach ([[503, 'source-unavailable', true], [429, 'source-unavailable', true], [400, 'outside-refused', false]] as [$status, $code, $retryable]) {
			try {
				$this->leg($status, ['detail' => 'no'])->register(target: self::TARGET, mapped: ['subject' => 'x'], handover: ['reference' => 'OF-1'], steps: []);
				$this->fail('A ' . $status . ' must be refused');
			} catch (RegistrationRefusal $refusal) {
				$this->assertSame([$code, $retryable, $status], [$refusal->getReason(), $refusal->isRetryable(), $refusal->toAnswer()['status']]);
			}
		}
	}//end testTheOutsideStatusDecidesRetry()

	/**
	 * A POST already done is not done again.
	 *
	 * @return void
	 */
	public function testAPostAlreadyDoneIsNotRepeated(): void {
		$steps  = [['step' => 'posted', 'reference' => '77', 'url' => 'u']];
		$result = $this->leg(201, ['id' => 99], $calls)->register(target: self::TARGET, mapped: ['subject' => 'x'], handover: ['reference' => 'OF-1'], steps: $steps);

		$this->assertSame([], $calls);
		$this->assertSame(['77', 'u', $steps], [$result['externalReference'], $result['externalUrl'], $result['steps']]);
	}//end testAPostAlreadyDoneIsNotRepeated()

	/**
	 * A target without an endpoint source, or with a source that is gone, is refused and not retryable.
	 *
	 * @return void
	 */
	public function testAMissingSourceIsRefused(): void {
		try {
			$this->leg(201, [])->register(target: ['json' => ['path' => '/x']], mapped: [], handover: ['reference' => 'OF-1'], steps: []);
			$this->fail('A target without a source must be refused');
		} catch (RegistrationRefusal $refusal) {
			$this->assertSame(['source-unavailable', false], [$refusal->getReason(), $refusal->isRetryable()]);
		}

		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willThrowException(new CaseSystemRefusal(status: 409, message: 'source gone'));
		try {
			(new JsonRegistrationLeg($transport, new JsonSchemaChecker()))->register(target: ['sources' => ['endpoint' => 's']], mapped: [], handover: [], steps: []);
			$this->fail('A missing source must be refused');
		} catch (RegistrationRefusal $refusal) {
			$this->assertSame(['source-unavailable', false, 'source gone'], [$refusal->getReason(), $refusal->isRetryable(), $refusal->getMessage()]);
		}
	}//end testAMissingSourceIsRefused()
}//end class
