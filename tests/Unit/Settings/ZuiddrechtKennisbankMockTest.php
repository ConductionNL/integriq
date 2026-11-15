<?php

/**
 * The Zuiddrecht set's knowledge base: the shipped xwiki source in mock mode.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The demo answers knowledge-base searches without an xWiki.
 */
class ZuiddrechtKennisbankMockTest extends TestCase {

	/**
	 * The change finds the shipped xwiki source, switches it on in mock mode and
	 * leaves its headers; the canned search answers with the board's two articles among others.
	 *
	 * @return void
	 */
	public function testTheXwikiSourceAnswersWithTheBoardsArticles(): void {
		$folder  = __DIR__.'/../../../lib/Settings';
		$part    = json_decode((string)file_get_contents($folder.'/example-sets/zuiddrecht.d/10-kennisbank-mock.json'), true);
		$changes = array_column($part['changes'], null, 'key');
		$change  = $changes['kennisbank-xwiki-mock'];

		$shipped = json_decode((string)file_get_contents($folder.'/register.d/xwiki-source.json'), true)['components']['objects'][0];
		$this->assertSame(expected: ['name' => $shipped['name'], 'location' => $shipped['location']], actual: $change['where']);

		$this->assertTrue(condition: $change['set']['isEnabled']);
		$this->assertTrue(condition: $change['set']['configuration.mock']);
		$this->assertArrayNotHasKey(key: 'configuration', array: $change['set']);

		$titles = array_column($change['set']['configuration.mockResponse']['results'], 'title');
		$this->assertContains(needle: 'Evenementenvergunning: wat regel je', haystack: $titles);
		$this->assertContains(needle: 'Verhuizen binnen Zuiddrecht', haystack: $titles);
		foreach ($change['set']['configuration.mockResponse']['results'] as $row) {
			$this->assertSame(expected: $row['space'].'.'.$row['page'], actual: $row['reference']);
		}
	}//end testTheXwikiSourceAnswersWithTheBoardsArticles()
}//end class
