<?php

/**
 * Unit tests: the live SWV hand-off client against a recorded acknowledgement.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Swv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Swv;

use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientHttp;
use OCA\Integriq\Tests\Unit\Adapters\Support\SupplierCallHarness;
use PHPUnit\Framework\TestCase;

/**
 * A dossier posted to the receiver, and its acknowledgement.
 */
class SwvHandoffClientHttpTest extends TestCase {
	use SupplierCallHarness;

	/**
	 * The dossier is posted as JSON, kept out of the call log, and the acknowledgement returned.
	 *
	 * @return void
	 */
	public function testTheReceiverAcknowledgesTheDossier(): void {
		$recorded = $this->recorded(path: 'swv/swv-acknowledgement-recorded.json');
		$client = new SwvHandoffClientHttp(calls: $this->supplierCall(slugs: ['swv-kindkans'], status: 201, body: (string)json_encode($recorded)));
		$dossier = ['requestType' => 'arrangement', 'tlvRequested' => true, 'pupil' => ['name' => 'not logged']];

		$ack = $client->handOff(receiverId: 'swv-kindkans', dossier: $dossier);

		$this->assertSame(
			expected: ['referenceId' => 'KK-2026-000731', 'acceptedStatus' => 'received', 'receivedAt' => '2026-11-13T10:15:00+01:00', 'flavour' => 'https'],
			actual: $ack
		);
		$this->assertSame(expected: ['swv-kindkans', '/handoff', 'POST'], actual: array_slice($this->calls[0], 0, 3));
		$this->assertSame(expected: $dossier, actual: $this->calls[0][3]['json']);
		$this->assertFalse($this->calls[0][3]['logBody']);
		$this->assertTrue($this->calls[0][4]);
	}//end testTheReceiverAcknowledgesTheDossier()

	/**
	 * An unreachable receiver throws, so the caller keeps the dossier queued.
	 *
	 * @return void
	 */
	public function testAnUnreachableReceiverThrows(): void {
		$client = new SwvHandoffClientHttp(calls: $this->supplierCall(slugs: ['swv-ldos'], status: 503, body: ''));

		$this->expectException(SupplierCallException::class);
		$this->expectExceptionMessage('swv-ldos answered /handoff with status 503.');

		$client->handOff(receiverId: 'swv-ldos', dossier: ['requestType' => 'tlv']);
	}//end testAnUnreachableReceiverThrows()

	/**
	 * An acknowledgement without a reference is not an acknowledgement.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementWithoutAReferenceThrows(): void {
		$client = new SwvHandoffClientHttp(calls: $this->supplierCall(slugs: ['swv-ldos'], status: 200, body: '{"acceptedStatus":"received"}'));

		$this->expectExceptionMessage('swv-ldos acknowledged the hand-off without a reference.');

		$client->handOff(receiverId: 'swv-ldos', dossier: []);
	}//end testAnAcknowledgementWithoutAReferenceThrows()
}//end class
