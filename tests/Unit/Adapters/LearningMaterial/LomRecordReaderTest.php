<?php

/**
 * Unit tests for the LOM record reader.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\LicenseCode;
use OCA\Integriq\Adapters\LearningMaterial\LomRecordReader;
use PHPUnit\Framework\TestCase;

/**
 * Rights, makers and players read the way the spec says.
 */
class LomRecordReaderTest extends TestCase {
	/**
	 * @param string $rights The rights element's content.
	 *
	 * @return string The LOM record.
	 */
	private function lom(string $rights): string {
		return '<czp:lom xmlns:czp="http://www.imsglobal.org/xsd/imsmd_v1p2"><czp:general><czp:title><czp:langstring>T</czp:langstring></czp:title></czp:general>'
			. '<czp:lifecycle><czp:contribute><czp:role><czp:value><czp:langstring>publisher</czp:langstring></czp:value></czp:role>'
			. "<czp:centity><czp:vcard>BEGIN:VCARD\nFN:KlasCement\nEND:VCARD</czp:vcard></czp:centity></czp:contribute></czp:lifecycle>"
			. '<czp:technical><czp:format>video/mp4</czp:format><czp:location>https://www.youtube.com/watch?v=dQw4w9WgXcQ</czp:location></czp:technical>'
			. '<czp:rights>' . $rights . '</czp:rights></czp:lom>';
	}//end lom()

	/**
	 * @return void
	 */
	public function testRightsYesWithAnotherUrlIsUnknownWithTheUrlKept(): void {
		$rights = '<czp:copyrightandotherrestrictions><czp:value><czp:langstring>yes</czp:langstring></czp:value></czp:copyrightandotherrestrictions>'
			. '<czp:description><czp:langstring>Zie https://example.org/voorwaarden</czp:langstring></czp:description>';

		$record = (new LomRecordReader())->read(recordIdentifier: 'KlasCement:oai:1', xml: $this->lom(rights: $rights));

		$this->assertNotNull($record);
		$this->assertSame(LicenseCode::UNKNOWN, $record->license);
		$this->assertSame('https://example.org/voorwaarden', $record->licenseUrl);
		$this->assertSame('oai:1', $record->sourceId);
		$this->assertSame(['KlasCement'], $record->authors);
	}//end testRightsYesWithAnotherUrlIsUnknownWithTheUrlKept()

	/**
	 * @return void
	 */
	public function testRightsNoIsRightsReserved(): void {
		$this->assertSame(LicenseCode::RIGHTS_RESERVED, (new LomRecordReader())->license(value: 'no', url: 'https://creativecommons.org/licenses/by/4.0/'));
	}//end testRightsNoIsRightsReserved()

	/**
	 * @return void
	 */
	public function testAYoutubeVideoGetsItsEmbedUrl(): void {
		$record = (new LomRecordReader())->read(recordIdentifier: 'x:y', xml: $this->lom(rights: ''));

		$this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $record?->embedUrl);
		$this->assertSame('https://player.vimeo.com/video/76979871', (new LomRecordReader())->embedUrl(url: 'https://vimeo.com/76979871'));
		$this->assertNull((new LomRecordReader())->embedUrl(url: 'https://schooltv.nl/video-item/x'));
	}//end testAYoutubeVideoGetsItsEmbedUrl()

	/**
	 * @return void
	 */
	public function testXmlThatIsNoLomRecordReadsAsNull(): void {
		$reader = new LomRecordReader();

		$this->assertNull($reader->read(recordIdentifier: 'x:y', xml: '<lom><broken'));
		$this->assertNull($reader->read(recordIdentifier: 'x:y', xml: '<other xmlns="http://www.imsglobal.org/xsd/imsmd_v1p2"/>'));
		$this->assertNull($reader->read(recordIdentifier: 'x:y', xml: ''));
	}//end testXmlThatIsNoLomRecordReadsAsNull()
}//end class
