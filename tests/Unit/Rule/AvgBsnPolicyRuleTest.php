<?php

/**
 * Unit tests for AvgBsnPolicyRule.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Rule;

use Exception;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AVG BSN policy rule: 11-proef validation, SHA-256 hashing
 * inbound, and the outbound raw-BSN guard.
 *
 * @spec openspec/changes/vng-klantinteracties-adapter/specs/vng-klantinteracties-adapter/spec.md#req-003
 */
class AvgBsnPolicyRuleTest extends TestCase {

	/**
	 * Spec example valid test BSN (11-proef-valid) also used in
	 * REQ-003's scenario text.
	 *
	 * @var string
	 */
	private const VALID_BSN = '999993653';

	/**
	 * Build a rule ObjectEntity carrying the given avgBsnPolicy configuration.
	 *
	 * @param array $configuration The `configuration.avgBsnPolicy` payload.
	 *
	 * @return ObjectEntity
	 */
	private function makeRule(array $configuration = []): ObjectEntity {
		$rule = new ObjectEntity();
		$rule->setObject(['name' => 'vng-avg-bsn-policy', 'configuration' => ['avgBsnPolicy' => $configuration]]);
		return $rule;
	}//end makeRule()

	/**
	 * A valid inbound BSN is hashed (SHA-256) and never persisted raw.
	 *
	 * @return void
	 */
	public function testValidInboundBsnIsHashed(): void {
		$subject = new AvgBsnPolicyRule();
		$data = [
			'body' => [
				'partijIdentificator' => [
					'codeSoortObjectId' => 'bsn',
					'objectId' => self::VALID_BSN,
				],
			],
		];

		$result = $subject->apply(rule: $this->makeRule(), data: $data, timing: 'before');

		$expectedHash = hash('sha256', self::VALID_BSN);
		$this->assertSame($expectedHash, $result['body']['partijIdentificator']['objectId']);
		$this->assertNotSame(self::VALID_BSN, $result['body']['partijIdentificator']['objectId']);
	}//end testValidInboundBsnIsHashed()

	/**
	 * A BSN failing the 11-proef checksum is rejected before any storage.
	 *
	 * @return void
	 */
	public function testInvalidBsnIsRejected(): void {
		$subject = new AvgBsnPolicyRule();
		$data = [
			'body' => [
				'partijIdentificator' => [
					'codeSoortObjectId' => 'bsn',
					'objectId' => '123456789',
				],
			],
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/11-proef/');
		$subject->apply(rule: $this->makeRule(), data: $data, timing: 'before');
	}//end testInvalidBsnIsRejected()

	/**
	 * Non-BSN identity types are left untouched (rule is a no-op).
	 *
	 * @return void
	 */
	public function testNonBsnIdentityIsUntouched(): void {
		$subject = new AvgBsnPolicyRule();
		$data = [
			'body' => [
				'partijIdentificator' => [
					'codeSoortObjectId' => 'rsin',
					'objectId' => '123456782',
				],
			],
		];

		$result = $subject->apply(rule: $this->makeRule(), data: $data, timing: 'before');

		$this->assertSame('123456782', $result['body']['partijIdentificator']['objectId']);
	}//end testNonBsnIdentityIsUntouched()

	/**
	 * A raw, checksum-valid BSN that survived to the outbound path is stripped, never rendered.
	 *
	 * @return void
	 */
	public function testOutboundRawBsnIsStripped(): void {
		$subject = new AvgBsnPolicyRule();
		$data = [
			'body' => [
				'partijIdentificator' => [
					'codeSoortObjectId' => 'bsn',
					'objectId' => self::VALID_BSN,
				],
			],
		];

		$result = $subject->apply(rule: $this->makeRule(), data: $data, timing: 'after');

		$this->assertArrayNotHasKey('objectId', $result['body']['partijIdentificator']);
	}//end testOutboundRawBsnIsStripped()

	/**
	 * A stored hash-backed identity (not a valid raw BSN) passes through outbound unchanged.
	 *
	 * @return void
	 */
	public function testOutboundHashBackedIdentityPassesThrough(): void {
		$subject = new AvgBsnPolicyRule();
		$hash = hash('sha256', self::VALID_BSN);
		$data = [
			'body' => [
				'partijIdentificator' => [
					'codeSoortObjectId' => 'bsn',
					'objectId' => $hash,
				],
			],
		];

		$result = $subject->apply(rule: $this->makeRule(), data: $data, timing: 'after');

		$this->assertSame($hash, $result['body']['partijIdentificator']['objectId']);
	}//end testOutboundHashBackedIdentityPassesThrough()

	/**
	 * The 11-proef checksum accepts the spec's example valid BSN and rejects an all-zero BSN.
	 *
	 * @return void
	 */
	public function testIsValidBsnChecksum(): void {
		$subject = new AvgBsnPolicyRule();

		$this->assertTrue($subject->isValidBsn(self::VALID_BSN));
		$this->assertFalse($subject->isValidBsn('000000000'));
		$this->assertFalse($subject->isValidBsn('123456789'));
		$this->assertFalse($subject->isValidBsn('not-a-bsn'));
	}//end testIsValidBsnChecksum()

}//end class
