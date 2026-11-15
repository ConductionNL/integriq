<?php

/**
 * The PDOK WMS source adapter hands map and capability requests to the WMS
 * client with its defaults, and reads its flag from app config.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Pdok;

use OCA\Integriq\Adapters\Pdok\PdokWmsClient;
use OCA\Integriq\Sources\Pdok\PdokWmsSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PdokWmsSourceAdapter.
 *
 * @covers \OCA\Integriq\Sources\Pdok\PdokWmsSourceAdapter
 */
class PdokWmsSourceAdapterTest extends TestCase {

	/**
	 * The WMS client double.
	 *
	 * @var PdokWmsClient&MockObject
	 */
	private PdokWmsClient&MockObject $client;

	/**
	 * Set up the client double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(PdokWmsClient::class);
		$this->client->method('flavour')->willReturn('mock');
	}//end setUp()

	/**
	 * The adapter with the given stored flag value.
	 *
	 * @param string $flag The stored pdok.feature_flag.
	 *
	 * @return PdokWmsSourceAdapter
	 */
	private function adapter(string $flag = '0'): PdokWmsSourceAdapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('integriq', 'pdok.feature_flag', '0')->willReturn($flag);

		return new PdokWmsSourceAdapter($config, $this->createMock(LoggerInterface::class), $this->client);
	}//end adapter()

	/**
	 * The flag is on for 1 and any casing of true, off for everything else.
	 *
	 * @return void
	 */
	public function testTheFlagReadsOneOrTrue(): void {
		foreach (['1' => true, 'True' => true, '0' => false, 'false' => false, '' => false] as $raw => $active) {
			$this->assertSame($active, $this->adapter((string)$raw)->isActive(), 'flag ' . var_export($raw, true));
		}
	}//end testTheFlagReadsOneOrTrue()

	/**
	 * getMap passes every argument through and returns the image bytes.
	 *
	 * @return void
	 */
	public function testGetMapPassesTheRequestThrough(): void {
		$bbox = [4.8, 52.3, 4.9, 52.4];
		$this->client->expects($this->once())->method('getMap')
			->with('luchtfoto', 'Actueel_orthoHR', $bbox, 'EPSG:4326', 256, 128, 'image/jpeg')
			->willReturn('JPEGBYTES');

		$image = $this->adapter()->getMap('luchtfoto', 'Actueel_orthoHR', $bbox, 'EPSG:4326', 256, 128, 'image/jpeg');

		$this->assertSame('JPEGBYTES', $image);
	}//end testGetMapPassesTheRequestThrough()

	/**
	 * Without options a map is a 512x512 PNG in RD New.
	 *
	 * @return void
	 */
	public function testGetMapDefaults(): void {
		$this->client->expects($this->once())->method('getMap')
			->with('bgt', 'achtergrond', [0, 0, 1, 1], 'EPSG:28992', 512, 512, 'image/png')
			->willReturn('PNG');

		$this->assertSame('PNG', $this->adapter()->getMap('bgt', 'achtergrond', [0, 0, 1, 1]));
	}//end testGetMapDefaults()

	/**
	 * getCapabilities returns the client's document for the dataset.
	 *
	 * @return void
	 */
	public function testGetCapabilities(): void {
		$this->client->expects($this->once())->method('getCapabilities')->with('')->willReturn('<WMS_Capabilities/>');

		$this->assertSame('<WMS_Capabilities/>', $this->adapter()->getCapabilities());
	}//end testGetCapabilities()

	/**
	 * The descriptor names this adapter, disabled by default, behind the PDOK flag.
	 *
	 * @return void
	 */
	public function testTheDescriptor(): void {
		$descriptor = PdokWmsSourceAdapter::sourceDescriptor();

		$this->assertSame('pdok-wms', $descriptor['id']);
		$this->assertSame('gis-raster', $descriptor['subCategory']);
		$this->assertSame('wms', $descriptor['type']);
		$this->assertSame(PdokWmsSourceAdapter::class, $descriptor['adapterClass']);
		$this->assertFalse($descriptor['isEnabled']);
		$this->assertSame('pdok.feature_flag', $descriptor['reference']);
	}//end testTheDescriptor()
}//end class
