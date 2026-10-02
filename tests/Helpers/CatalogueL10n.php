<?php

/**
 * CatalogueL10n: an IL10N double that answers from one of the app's own backend catalogues.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Translates the way Nextcloud's L10N does (catalogue lookup, then vsprintf), reading
 * `l10n/<lang>.json`, and records every source string it was asked for.
 */
class CatalogueL10n {

	/**
	 * Source strings asked for, across every double this helper made.
	 *
	 * @var list<string>
	 */
	public static array $asked = [];

	/**
	 * An IL10N double for one language.
	 *
	 * @param TestCase $test     The calling test instance (provides getMockBuilder()).
	 * @param string   $language The catalogue, `en` answers the English source.
	 *
	 * @return IL10N&MockObject
	 */
	public static function make(TestCase $test, string $language='en'): MockObject {
		$catalogue = [];
		if ($language !== 'en') {
			$path      = dirname(__DIR__, 2) . '/l10n/' . $language . '.json';
			$catalogue = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['translations'];
		}

		$l10n = $test->getMockBuilder(IL10N::class)->getMock();
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters=[]) use ($catalogue): string {
				self::$asked[] = $text;
				$translated    = (string)($catalogue[$text] ?? $text);
				return vsprintf($translated, (array)$parameters);
			}
		);

		return $l10n;
	}//end make()
}//end class
