<?php

/**
 * Integriq PDOK WMS Client (mock).
 *
 * Deterministic, no-network implementation of {@see PdokWmsClient}. Ships
 * dormant; DI in {@see \OCA\Integriq\AppInfo\Application::register()}
 * returns this class until `pdok.feature_flag` is set to `1`.
 *
 * Returns a hand-rolled but standards-shaped GetCapabilities document and a
 * deterministic 1x1 transparent PNG for every GetMap call so downstream code
 * can exercise its branches without hitting the real service.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.openconnector.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Pdok;

/**
 * Mock PDOK WMS client — dormant default.
 */
final class PdokWmsClientMock extends PdokWmsClient {

	/**
	 * 1x1 transparent PNG (43 bytes) used as the canned GetMap response.
	 *
	 * @var string
	 */
	private const TRANSPARENT_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

	/**
	 * Render a canned GetCapabilities XML document.
	 *
	 * @param string $dataset PDOK WMS dataset key.
	 *
	 * @return string The raw XML capabilities document.
	 */
	public function getCapabilities(string $dataset): string {
		$safe = htmlspecialchars($dataset, ENT_XML1 | ENT_COMPAT, 'UTF-8');

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">'
			. '<Service><Name>WMS</Name><Title>PDOK mock ' . $safe . '</Title></Service>'
			. '<Capability><Layer><Name>' . $safe . '</Name><Title>' . $safe . '</Title>'
			. '<CRS>EPSG:28992</CRS><CRS>EPSG:4326</CRS></Layer></Capability>'
			. '</WMS_Capabilities>';

	}//end getCapabilities()

	/**
	 * Return the canned 1x1 transparent PNG.
	 *
	 * @param string $dataset PDOK WMS dataset key.
	 * @param string $layer Layer name.
	 * @param array $bbox Bounding box (unused).
	 * @param string $crs CRS identifier (unused).
	 * @param int $width Pixel width (unused).
	 * @param int $height Pixel height (unused).
	 * @param string $format Image MIME type (unused).
	 *
	 * @return string The 1x1 transparent PNG.
	 */
	public function getMap(
		string $dataset,
		string $layer,
		array $bbox,
		string $crs = 'EPSG:28992',
		int $width = 512,
		int $height = 512,
		string $format = 'image/png',
	): string {
		// Suppress unused-parameter warnings.
		unset($dataset, $layer, $bbox, $crs, $width, $height, $format);

		$decoded = base64_decode(self::TRANSPARENT_PNG_BASE64, true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end getMap()

	/**
	 * Flavour identifier.
	 *
	 * @return string
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()
}//end class
