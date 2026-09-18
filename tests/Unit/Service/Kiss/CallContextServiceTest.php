<?php

/**
 * What the panel is told about the caller, and what it is never told.
 *
 * The rule this file is really about: when no party is matched, there are NO
 * open cases either. A case list beside a null caller puts somebody's cases on
 * the screen under "unknown caller", which is the wrong-person failure wearing
 * a different hat.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Service\Kiss\CallContextService;
use OCA\Integriq\Service\Kiss\CallerDirectory;
use OCA\Integriq\Service\Kiss\CallerLookup;
use OCA\Integriq\Service\Kiss\CallerSearchInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the caller context.
 */
class CallContextServiceTest extends TestCase {

	/**
	 * A party carrying one phone number.
	 *
	 * @param string $name The name.
	 * @param string $number The stored number.
	 *
	 * @return array<string, mixed> The party.
	 */
	private function party(string $name, string $number): array {
		return [
			'uuid'             => 'p-'.$name,
			'naam'             => $name,
			'digitaleAdressen' => [['soortDigitaalAdres' => 'telefoonnummer', 'adres' => $number]],
		];

	}//end party()

	/**
	 * A context service over a search that answers with the given data.
	 *
	 * @param array|null $parties The candidates, or null for no search binding.
	 * @param array $cases The open cases.
	 * @param string $throwOn Which call throws: 'parties', 'cases' or ''.
	 *
	 * @return CallContextService The service.
	 */
	private function service(?array $parties, array $cases = [], string $throwOn = ''): CallContextService {
		$search = null;

		if ($parties !== null) {
			$search = $this->createMock(originalClassName: CallerSearchInterface::class);

			if ($throwOn === 'parties') {
				$search->method('findPartiesByPhoneNumber')
					->willThrowException(new KissProviderException('unreachable'));
			} else {
				$search->method('findPartiesByPhoneNumber')->willReturn($parties);
			}

			if ($throwOn === 'cases') {
				$search->method('findOpenCasesForParty')
					->willThrowException(new KissProviderException('unreachable'));
			} else {
				$search->method('findOpenCasesForParty')->willReturn($cases);
			}
		}

		return new CallContextService(
			lookup: new CallerLookup(),
			directory: new CallerDirectory(search: $search),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end service()

	/**
	 * A known caller carries their party and their open cases.
	 *
	 * @return void
	 */
	public function testAKnownCallerCarriesTheirPartyAndCases(): void {
		$context = $this->service(
			parties: [$this->party(name: 'Jansen', number: '+31612345678')],
			cases: ['ZAAK-2026-0001']
		)->resolve(e164: '+31612345678');

		$this->assertSame('Jansen', $context['caller']['naam']);
		$this->assertSame(['ZAAK-2026-0001'], $context['openCases']);

	}//end testAKnownCallerCarriesTheirPartyAndCases()

	/**
	 * An unmatched caller carries no cases at all.
	 *
	 * @return void
	 */
	public function testAnUnmatchedCallerCarriesNoCasesAtAll(): void {
		// The whole point. A case list beside a null caller is somebody's
		// cases on the panel under "unknown caller".
		$context = $this->service(
			parties: [$this->party(name: 'De Vries', number: '+49612345678')],
			cases: ['ZAAK-2026-0002']
		)->resolve(e164: '+31612345678');

		$this->assertNull($context['caller']);
		$this->assertSame([], $context['openCases']);

	}//end testAnUnmatchedCallerCarriesNoCasesAtAll()

	/**
	 * Two parties on one number carry no cases either.
	 *
	 * @return void
	 */
	public function testAnAmbiguousMatchCarriesNoCases(): void {
		$context = $this->service(
			parties: [
				$this->party(name: 'Jansen', number: '+31201234567'),
				$this->party(name: 'Jansen-De Vries', number: '+31201234567'),
			],
			cases: ['ZAAK-2026-0003']
		)->resolve(e164: '+31201234567');

		$this->assertNull($context['caller']);
		$this->assertSame([], $context['openCases']);

	}//end testAnAmbiguousMatchCarriesNoCases()

	/**
	 * A withheld number is never looked up.
	 *
	 * @return void
	 */
	public function testAWithheldNumberIsNeverLookedUp(): void {
		$context = $this->service(parties: [$this->party(name: 'Jansen', number: '+31612345678')])
			->resolve(e164: '');

		$this->assertNull($context['caller']);
		$this->assertSame([], $context['openCases']);

	}//end testAWithheldNumberIsNeverLookedUp()

	/**
	 * An instance with no search binding shows every caller as unknown.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoSearchBindingShowsEveryCallerAsUnknown(): void {
		// True, and visible. That is what makes it safe to ship the panel
		// before a binding can search by number.
		$context = $this->service(parties: null)->resolve(e164: '+31612345678');

		$this->assertNull($context['caller']);
		$this->assertFalse((new CallerDirectory(search: null))->canSearch());

	}//end testAnInstanceWithNoSearchBindingShowsEveryCallerAsUnknown()

	/**
	 * A directory that cannot answer is an unknown caller, not a failed call.
	 *
	 * @return void
	 */
	public function testADirectoryThatCannotAnswerIsAnUnknownCaller(): void {
		// The phone is still ringing. Throwing here would turn a KISS outage
		// into a switchboard outage.
		$context = $this->service(parties: [], throwOn: 'parties')->resolve(e164: '+31612345678');

		$this->assertNull($context['caller']);
		$this->assertSame([], $context['openCases']);

	}//end testADirectoryThatCannotAnswerIsAnUnknownCaller()

	/**
	 * A good identification survives a failed case lookup.
	 *
	 * @return void
	 */
	public function testAGoodIdentificationSurvivesAFailedCaseLookup(): void {
		// The caller is known and the cases are not. Discarding the name
		// because a second lookup failed would throw away the useful half.
		$context = $this->service(
			parties: [$this->party(name: 'Jansen', number: '+31612345678')],
			throwOn: 'cases'
		)->resolve(e164: '+31612345678');

		$this->assertSame('Jansen', $context['caller']['naam']);
		$this->assertSame([], $context['openCases']);

	}//end testAGoodIdentificationSurvivesAFailedCaseLookup()

}//end class
