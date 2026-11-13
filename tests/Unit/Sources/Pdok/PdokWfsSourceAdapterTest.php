<?php

/**
 * The PDOK WFS source adapter splits the type name into dataset and feature
 * type, hands the query to the WFS client, and reads its flag from app config.
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

use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\Sources\Pdok\PdokWfsSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PdokWfsSourceAdapter.
 *
 * @covers \OCA\Integriq\Sources\Pdok\PdokWfsSourceAdapter
 */
class PdokWfsSourceAdapterTest extends TestCase {

	/**
	 * The WFS client double.
	 *
	 * @var PdokWfsClient&MockObject
	 */
	private PdokWfsClient&MockObject $client;

	/**
	 * Set up the client double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(PdokWfsClient::class);
		$this->client->method('flavour')->willReturn('mock');
	}//end setUp()

	/**
	 * The adapter with the given stored flag value.
	 *
	 * @param string $flag The stored pdok.feature_flag.
	 *
	 * @return PdokWfsSourceAdapter
	 */
	private function adapter(string $flag = '0'): PdokWfsSourceAdapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('integriq', 'pdok.feature_flag', '0')->willReturn($flag);

		return new PdokWfsSourceAdapter($config, $this->createMock(LoggerInterface::class), $this->client);
	}//end adapter()

	/**
	 * The flag is on for 1 and any casing of true, off for everything else.
	 *
	 * @return void
	 */
	public function testTheFlagReadsOneOrTrue(): void {
		foreach (['1' => true, 'true' => true, 'TRUE' => true, '0' => false, '' => false, 'yes' => false, ' 1' => false] as $raw => $active) {
			$this->assertSame($active, $this->adapter((string)$raw)->isActive(), 'flag ' . var_export($raw, true));
		}
	}//end testTheFlagReadsOneOrTrue()

	/**
	 * A dataset:featureType name is split, and the query goes to the client.
	 *
	 * @return void
	 */
	public function testGetFeaturesSplitsTheTypeName(): void {
		$bbox = [155000, 463000, 156000, 464000];
		$this->client->expects($this->once())->method('getFeature')
			->with('bag', 'pand:extra', $bbox, 5, 'EPSG:28992', ['status' => 'in gebruik'])
			->willReturn([['id' => 'pand.1']]);

		$features = $this->adapter()->getFeatures('bag:pand:extra', $bbox, 5, ['status' => 'in gebruik']);

		$this->assertSame([['id' => 'pand.1']], $features);
	}//end testGetFeaturesSplitsTheTypeName()

	/**
	 * A name without a colon is the dataset with an empty feature type, and
	 * the defaults are no bbox and a count of 100.
	 *
	 * @return void
	 */
	public function testANameWithoutAColonIsTheDataset(): void {
		$this->client->expects($this->once())->method('getFeature')
			->with('bag', '', null, 100, 'EPSG:28992', [])
			->willReturn([]);

		$this->assertSame([], $this->adapter()->getFeatures('bag'));
	}//end testANameWithoutAColonIsTheDataset()

	/**
	 * describeService returns the client's capabilities for the dataset.
	 *
	 * @return void
	 */
	public function testDescribeServiceReturnsTheCapabilities(): void {
		$this->client->expects($this->once())->method('getCapabilities')->with('bag')->willReturn('<WFS_Capabilities/>');

		$this->assertSame('<WFS_Capabilities/>', $this->adapter()->describeService('bag'));
	}//end testDescribeServiceReturnsTheCapabilities()

	/**
	 * The descriptor names this adapter, disabled by default, behind the PDOK flag.
	 *
	 * @return void
	 */
	public function testTheDescriptor(): void {
		$descriptor = PdokWfsSourceAdapter::sourceDescriptor();

		$this->assertSame('pdok-wfs', $descriptor['id']);
		$this->assertSame('geo', $descriptor['category']);
		$this->assertSame('gis-vector', $descriptor['subCategory']);
		$this->assertSame('wfs', $descriptor['type']);
		$this->assertSame(PdokWfsSourceAdapter::class, $descriptor['adapterClass']);
		$this->assertFalse($descriptor['isEnabled']);
		$this->assertSame('pdok.feature_flag', $descriptor['reference']);
	}//end testTheDescriptor()
}//end class
