<?php

/**
 * Unit tests for how a StUF provider query takes a refusal from the apps it reaches.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use PHPUnit\Framework\TestCase;

/**
 * Only the owning app answers, only once, and an unknown kind is a plain refusal.
 *
 * @covers \OCA\Integriq\Event\StufProviderQueryEvent
 * @uses   \OCA\Integriq\Event\StufCaseMutationEvent
 */
final class StufProviderQueryRefusalTest extends TestCase {

	/**
	 * A case mutation query on source-1.
	 *
	 * @return StufCaseMutationEvent
	 */
	private static function query(): StufCaseMutationEvent {
		return new StufCaseMutationEvent('source-1', 'ref-9', 'W', ['identificatie' => 'X-1'], ['action' => 'update']);
	}//end query()

	/**
	 * Another app's refusal on a source owned by dossiq is ignored; the owner's counts.
	 *
	 * @return void
	 */
	public function testOnlyTheOwningAppMayRefuse(): void {
		$query = self::query();
		$query->restrictToApp(appId: ' dossiq ');
		$query->restrictToApp(appId: 'other');

		$query->refuse(appId: 'other', reason: 'not mine', kind: StufProviderQueryEvent::REFUSAL_FORBIDDEN);
		self::assertSame([false, null, null, StufProviderQueryEvent::REFUSAL_REFUSED, false], [$query->isAnswered(), $query->getAnsweredBy(), $query->getRefusal(), $query->getRefusalKind(), $query->isPropagationStopped()]);

		$query->refuse(appId: 'dossiq', reason: 'Zaak is gesloten', kind: StufProviderQueryEvent::REFUSAL_CONFLICT);
		self::assertSame([true, 'dossiq', 'Zaak is gesloten', StufProviderQueryEvent::REFUSAL_CONFLICT, true], [$query->isAnswered(), $query->getAnsweredBy(), $query->getRefusal(), $query->getRefusalKind(), $query->isPropagationStopped()]);
	}//end testOnlyTheOwningAppMayRefuse()

	/**
	 * A second refusal does not replace the first.
	 *
	 * @return void
	 */
	public function testTheFirstRefusalStands(): void {
		$query = self::query();
		$query->refuse(appId: 'dossiq', reason: 'Onbekende zaak', kind: StufProviderQueryEvent::REFUSAL_NOT_FOUND);
		$query->refuse(appId: 'pipelinq', reason: 'Geen toegang', kind: StufProviderQueryEvent::REFUSAL_FORBIDDEN);

		self::assertSame(['dossiq', 'Onbekende zaak', StufProviderQueryEvent::REFUSAL_NOT_FOUND], [$query->getAnsweredBy(), $query->getRefusal(), $query->getRefusalKind()]);
	}//end testTheFirstRefusalStands()

	/**
	 * An unknown kind is recorded as a plain refusal; an empty app id does not restrict.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsAPlainRefusal(): void {
		$query = self::query();
		$query->restrictToApp(appId: '  ');
		$query->refuse(appId: 'pipelinq', reason: 'Nee', kind: 'teapot');

		self::assertSame(['pipelinq', StufProviderQueryEvent::REFUSAL_REFUSED], [$query->getAnsweredBy(), $query->getRefusalKind()]);
	}//end testAnUnknownKindIsAPlainRefusal()
}//end class
