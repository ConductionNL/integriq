<?php

/**
 * Unit tests for the objects-api registration leg.
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
use OCA\Integriq\Service\Registration\ObjectsApiRegistrationLeg;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One object of the configured type and version whose record.data is the mapped submission.
 */
#[CoversClass(ObjectsApiRegistrationLeg::class)]
#[UsesClass(RegistrationRefusal::class)]
class ObjectsApiRegistrationLegTest extends TestCase {

	/**
	 * A target of object type version 3.
	 *
	 * @var array<string,mixed>
	 */
	private const TARGET = [
		'kind' => 'objects-api',
		'sources' => ['objects' => 'obj-src'],
		'objectsApi' => ['objecttype' => 'https://types.example/api/v2/objecttypes/woo', 'objecttypeVersion' => 3],
	];

	/**
	 * The leg over a transport answering the given status and body.
	 *
	 * @param int        $status The answer status.
	 * @param mixed      $data   The decoded answer.
	 * @param array|null $calls  Receives each send.
	 *
	 * @return ObjectsApiRegistrationLeg
	 */
	private function leg(int $status, mixed $data, ?array &$calls = null): ObjectsApiRegistrationLeg {
		$calls     = [];
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(function (string $sourceId, string $method, string $address, array $options = []) use ($status, $data, &$calls): array {
			$calls[] = [$sourceId, $method, $address, $options];
			return ['status' => $status, 'data' => $data, 'raw' => ''];
		});

		return new ObjectsApiRegistrationLeg($transport);
	}//end leg()

	/**
	 * One object is posted with record.typeVersion 3, the mapped data and the submission date.
	 *
	 * @return void
	 */
	public function testOneObjectIsPostedWithTheTypeVersionAndMappedData(): void {
		$result = $this->leg(201, ['uuid' => 'o-1', 'url' => 'https://objects.example/api/v2/objects/o-1'], $calls)
			->register(target: self::TARGET, mapped: ['onderwerp' => 'milieu'], handover: ['reference' => 'OF-1', 'submittedAt' => '2026-10-08T10:12:00+02:00'], steps: []);

		$this->assertSame(
			[[
				'obj-src',
				'POST',
				'/objects',
				['json' => ['type' => 'https://types.example/api/v2/objecttypes/woo', 'record' => ['typeVersion' => 3, 'data' => ['onderwerp' => 'milieu'], 'startAt' => '2026-10-08']]],
			]],
			$calls
		);
		$this->assertSame(['o-1', 'https://objects.example/api/v2/objects/o-1'], [$result['externalReference'], $result['externalUrl']]);
	}//end testOneObjectIsPostedWithTheTypeVersionAndMappedData()

	/**
	 * An object already created is not created again.
	 *
	 * @return void
	 */
	public function testAnObjectAlreadyCreatedIsNotCreatedAgain(): void {
		$steps  = [['step' => 'object', 'reference' => 'o-1', 'url' => 'u']];
		$result = $this->leg(201, ['uuid' => 'o-2'], $calls)->register(target: self::TARGET, mapped: [], handover: [], steps: $steps);

		$this->assertSame([], $calls);
		$this->assertSame('o-1', $result['externalReference']);
	}//end testAnObjectAlreadyCreatedIsNotCreatedAgain()

	/**
	 * An incomplete target, and a target that sends files to the Documenten API, are refused without a request.
	 *
	 * @return void
	 */
	public function testATargetItCannotServeIsRefusedWithoutARequest(): void {
		$incomplete = ['objectsApi' => ['objecttypeVersion' => 'three'] + self::TARGET['objectsApi']] + self::TARGET;
		$withFiles  = ['objectsApi' => ['informatieobjecttype' => 'https://catalogi.example/iot/1'] + self::TARGET['objectsApi']] + self::TARGET;
		foreach ([$incomplete, $withFiles] as $target) {
			$leg = $this->leg(201, ['uuid' => 'o-1'], $calls);
			try {
				$leg->register(target: $target, mapped: [], handover: [], steps: []);
				$this->fail('The target must be refused');
			} catch (RegistrationRefusal $refusal) {
				$this->assertSame(['source-unavailable', false], [$refusal->getReason(), $refusal->isRetryable()]);
			}

			$this->assertSame([], $calls);
		}
	}//end testATargetItCannotServeIsRefusedWithoutARequest()

	/**
	 * A refusal from the Objects API is outside-refused with its status.
	 *
	 * @return void
	 */
	public function testARefusalFromTheObjectsApiIsOutsideRefused(): void {
		$this->expectException(RegistrationRefusal::class);
		$this->expectExceptionMessage('refused with 400');

		$this->leg(400, ['type' => ['invalid']])->register(target: self::TARGET, mapped: [], handover: [], steps: []);
	}//end testARefusalFromTheObjectsApiIsOutsideRefused()
}//end class
