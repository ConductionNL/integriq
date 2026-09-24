<?php

/**
 * Tests for the CloudEvent loop guard.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Tests\Unit\Service\Event;

use OCA\Integriq\Service\Event\EventLoopGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Event\EventLoopGuard
 */
class EventLoopGuardTest extends TestCase {
	/**
	 * A plain object mutation is forwarded.
	 *
	 * @return void
	 */
	public function testAnOrdinaryObjectIsForwarded(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(payload: ['type' => 'player'], schemaId: '19', selfSchemaIds: ['25', '26']);

		$this->assertTrue($decision['forward']);
		$this->assertSame(EventLoopGuard::FORWARD, $decision['reason']);
	}//end testAnOrdinaryObjectIsForwarded()

	/**
	 * An object on one of the machinery's own schemas is refused.
	 *
	 * @return void
	 */
	public function testAnEventMachinerySchemaIsRefused(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: ['25', '26']);

		$this->assertFalse($decision['forward']);
		$this->assertSame(EventLoopGuard::REFUSED_SELF_SCHEMA, $decision['reason']);
	}//end testAnEventMachinerySchemaIsRefused()

	/**
	 * The marker stops a CloudEvent this app wrote even when the schema id
	 * says nothing, which is the case after a register rename or when the
	 * schema was copied into a second register.
	 *
	 * @return void
	 */
	public function testTheMarkerStopsAnEventWhoseSchemaIdIsNotRecognised(): void {
		$guard = new EventLoopGuard();
		$payload = $guard->stamp(payload: ['type' => 'com.nextcloud.openregister.object.created']);

		$decision = $guard->decide(payload: $payload, schemaId: '918', selfSchemaIds: ['25', '26']);

		$this->assertFalse($decision['forward']);
		$this->assertSame(EventLoopGuard::REFUSED_MARKER, $decision['reason']);
	}//end testTheMarkerStopsAnEventWhoseSchemaIdIsNotRecognised()

	/**
	 * The control beside the refusal above: an object that simply is not one
	 * of ours, on an unrecognised schema id, is forwarded. Without this, the
	 * marker test would pass just as well against a guard that refuses
	 * everything it does not recognise.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedSchemaIdWithoutTheMarkerIsForwarded(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(payload: ['type' => 'player'], schemaId: '918', selfSchemaIds: ['25', '26']);

		$this->assertTrue($decision['forward']);
		$this->assertSame(EventLoopGuard::FORWARD, $decision['reason']);
	}//end testAnUnrecognisedSchemaIdWithoutTheMarkerIsForwarded()

	/**
	 * The case the ceiling exists for: the id set resolved to nothing, so the
	 * schema guard cannot recognise anything at all. The chain still stops,
	 * and it stops at the ceiling rather than running on.
	 *
	 * @return void
	 */
	public function testWithNoResolvedIdsTheChainStillStopsAtTheCeiling(): void {
		$guard = new EventLoopGuard();

		for ($i = 0; $i < EventLoopGuard::MAX_CHAIN; $i++) {
			$decision = $guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: []);
			$this->assertTrue($decision['forward'], 'event ' . $i . ' should still be forwarded');
		}

		$decision = $guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: []);

		$this->assertFalse($decision['forward']);
		$this->assertSame(EventLoopGuard::REFUSED_CEILING, $decision['reason']);
		$this->assertSame(EventLoopGuard::MAX_CHAIN, $decision['chain']);
	}//end testWithNoResolvedIdsTheChainStillStopsAtTheCeiling()

	/**
	 * An unresolved id set is reported as such, so a log can tell a guard
	 * that went inert from a guard that had nothing to stop.
	 *
	 * @return void
	 */
	public function testAnUnresolvedIdSetIsReportedRatherThanLookingLikeACleanPass(): void {
		$guard = new EventLoopGuard();

		$blind = $guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: []);
		$seeing = (new EventLoopGuard())->decide(payload: ['type' => 'x'], schemaId: '19', selfSchemaIds: ['25']);

		$this->assertFalse($blind['identified']);
		$this->assertTrue($seeing['identified']);
		$this->assertTrue($blind['forward']);
		$this->assertTrue($seeing['forward']);
	}//end testAnUnresolvedIdSetIsReportedRatherThanLookingLikeACleanPass()

	/**
	 * A refusal never looks like a quiet instance: every refusal is named.
	 *
	 * @return void
	 */
	public function testEveryRefusalCarriesItsOwnName(): void {
		$names = [
			EventLoopGuard::REFUSED_MARKER,
			EventLoopGuard::REFUSED_SELF_SCHEMA,
			EventLoopGuard::REFUSED_CEILING,
		];

		$this->assertSame($names, array_unique($names));
		$this->assertNotContains(EventLoopGuard::FORWARD, $names);
	}//end testEveryRefusalCarriesItsOwnName()

	/**
	 * A refused mutation does not spend a slot in the chain, so a storm of
	 * suppressed events cannot exhaust the budget a real one needs.
	 *
	 * @return void
	 */
	public function testARefusedMutationDoesNotSpendTheChainBudget(): void {
		$guard = new EventLoopGuard();

		for ($i = 0; $i < 100; $i++) {
			$guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: ['25']);
		}

		$this->assertSame(0, $guard->chain());

		$decision = $guard->decide(payload: ['type' => 'player'], schemaId: '19', selfSchemaIds: ['25']);

		$this->assertTrue($decision['forward']);
	}//end testARefusedMutationDoesNotSpendTheChainBudget()

	/**
	 * The marker is read case-insensitively on its value.
	 *
	 * @return void
	 */
	public function testTheMarkerValueIsReadCaseInsensitively(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(
			payload: [EventLoopGuard::MARKER_KEY => ' INTEGRIQ '],
			schemaId: '19',
			selfSchemaIds: ['25']
		);

		$this->assertFalse($decision['forward']);
		$this->assertSame(EventLoopGuard::REFUSED_MARKER, $decision['reason']);
	}//end testTheMarkerValueIsReadCaseInsensitively()

	/**
	 * A marker key holding something other than a string is not a marker.
	 *
	 * @return void
	 */
	public function testANonStringMarkerIsNotTreatedAsOurOwn(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(
			payload: [EventLoopGuard::MARKER_KEY => ['integriq']],
			schemaId: '19',
			selfSchemaIds: ['25']
		);

		$this->assertTrue($decision['forward']);
	}//end testANonStringMarkerIsNotTreatedAsOurOwn()

	/**
	 * Stamping is what makes the marker guard work end to end: what stamp()
	 * writes, carriesMarker() must recognise.
	 *
	 * @return void
	 */
	public function testWhatIsStampedIsWhatIsRecognised(): void {
		$guard = new EventLoopGuard();

		$this->assertTrue($guard->carriesMarker(payload: $guard->stamp(payload: [])));
		$this->assertFalse($guard->carriesMarker(payload: []));
	}//end testWhatIsStampedIsWhatIsRecognised()

	/**
	 * An empty schema id is not a match against an empty-ish id set entry.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaIdNeverMatches(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(payload: ['type' => 'x'], schemaId: '', selfSchemaIds: ['', '25']);

		$this->assertTrue($decision['forward']);
	}//end testAnEmptySchemaIdNeverMatches()

	/**
	 * Ids are compared as strings, so the integer 25 and the string '25' do
	 * not silently differ.
	 *
	 * @return void
	 */
	public function testIdsAreComparedStrictlyAsStrings(): void {
		$guard = new EventLoopGuard();

		$decision = $guard->decide(payload: ['type' => 'x'], schemaId: '25', selfSchemaIds: ['25']);

		$this->assertFalse($decision['forward']);
		$this->assertSame(EventLoopGuard::REFUSED_SELF_SCHEMA, $decision['reason']);
	}//end testIdsAreComparedStrictlyAsStrings()
}//end class
