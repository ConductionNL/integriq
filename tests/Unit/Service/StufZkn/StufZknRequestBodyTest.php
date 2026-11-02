<?php

/**
 * StUF-ZKN request body test: the bounded read of a public StUF body (iq-f9).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufZknRequestBody;
use PHPUnit\Framework\TestCase;

class StufZknRequestBodyTest extends TestCase {

	/**
	 * The stream is read for at most one byte past the ceiling, however long it is.
	 *
	 * @return void
	 */
	public function testAnOversizedStreamIsReadOnlyOneByteBeyondTheCeiling(): void {
		$file = tempnam(sys_get_temp_dir(), 'stufbody');
		file_put_contents($file, str_repeat('a', (StufZknRequestBody::MAX_BYTES + 5000)));

		try {
			$body = (new StufZknRequestBody())->read($file);
		} finally {
			unlink($file);
		}

		$this->assertSame((StufZknRequestBody::MAX_BYTES + 1), strlen($body), 'one byte past the ceiling is enough to know it is too large');
	}//end testAnOversizedStreamIsReadOnlyOneByteBeyondTheCeiling()

	/**
	 * A body within the ceiling is read whole; a missing stream is an empty body.
	 *
	 * @return void
	 */
	public function testABodyWithinTheCeilingIsReadWhole(): void {
		$file = tempnam(sys_get_temp_dir(), 'stufbody');
		file_put_contents($file, '<Envelope/>');

		try {
			$this->assertSame('<Envelope/>', (new StufZknRequestBody())->read($file));
		} finally {
			unlink($file);
		}

		$this->assertSame('', (new StufZknRequestBody())->read(sys_get_temp_dir() . '/does-not-exist-' . uniqid()));
	}//end testABodyWithinTheCeilingIsReadWhole()
}//end class
