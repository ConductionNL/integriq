<?php

/**
 * Unit tests for RenderOutcome.
 *
 * The distinction this change turns on: a vendor that answered no, and a
 * vendor that answered nothing.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use OCA\Integriq\Service\DocumentGeneration\RenderOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the normalised render outcome.
 */
class RenderOutcomeTest extends TestCase {

	/**
	 * A refused render is terminal: the vendor answered.
	 *
	 * @return void
	 */
	public function testARefusalIsTerminal(): void {
		$outcome = RenderOutcome::failed(detail: 'Template no longer exists');

		$this->assertSame('failed', $outcome->status);
		$this->assertTrue($outcome->isTerminal());

	}//end testARefusalIsTerminal()

	/**
	 * An unreachable vendor is not a refusal and not an ending.
	 *
	 * @return void
	 */
	public function testAnUnreachableVendorIsNeitherARefusalNorAnEnding(): void {
		$outcome = RenderOutcome::unreachable(detail: 'Connection timed out after 30s');

		$this->assertSame('unreachable', $outcome->status);
		$this->assertNotSame('failed', $outcome->status);
		$this->assertFalse(
			$outcome->isTerminal(),
			'nobody knows yet whether a document exists, so the render is not over'
		);

	}//end testAnUnreachableVendorIsNeitherARefusalNorAnEnding()

	/**
	 * A rendered outcome carries the document, and a queued one does not.
	 *
	 * @return void
	 */
	public function testOnlyARenderedOutcomeCarriesADocument(): void {
		$rendered = RenderOutcome::rendered(providerJobId: 'j-1', fileReference: 'doc-1');
		$queued = RenderOutcome::queued(providerJobId: 'j-2');

		$this->assertSame('doc-1', $rendered->fileReference);
		$this->assertTrue($rendered->isTerminal());
		$this->assertSame('', $queued->fileReference);
		$this->assertFalse($queued->isTerminal());

	}//end testOnlyARenderedOutcomeCarriesADocument()

	/**
	 * Every status this class recognises is one the job schema accepts.
	 *
	 * @return void
	 */
	public function testTheRecognisedStatusesAreTheFourTheJobRecords(): void {
		$this->assertSame(['queued', 'rendered', 'failed', 'unreachable'], RenderOutcome::STATUSES);

	}//end testTheRecognisedStatusesAreTheFourTheJobRecords()
}//end class
