<?php

/**
 * Every authorization block integriq ships names only actions every supported
 * OpenRegister accepts.
 *
 * OpenRegister 2.1.33 accepts create, read, update and delete (plus actions a
 * schema declares itself). A fragment naming `destroy` made a clean store
 * install reject ten integriq schemas whole: the PARTIAL IMPORT seen on
 * 2026-10-01. Newer OpenRegister knows more verbs, but a fragment has to load
 * on the oldest release integriq is installed beside.
 *
 * Reads the real files OpenRegister imports.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class AuthorizationActionVocabularyTest extends TestCase {

	/**
	 * Keys an authorization block may carry on every supported OpenRegister.
	 *
	 * @var array<int, string>
	 */
	private const ACCEPTED = ['create', 'read', 'update', 'delete', 'inheritFromPublic'];

	/**
	 * No shipped authorization block names an action outside the accepted set.
	 *
	 * @return void
	 */
	public function testEveryShippedAuthorizationActionIsAccepted(): void {
		$files = array_merge(
			glob(__DIR__ . '/../../../lib/Settings/*.json') ?: [],
			glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: []
		);
		$this->assertNotSame([], $files, 'The settings files must be found, or this test proves nothing.');

		$blocks = 0;
		$refused = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collect(node: $data, file: basename($file), path: '', blocks: $blocks, refused: $refused);
		}

		$this->assertGreaterThan(10, $blocks, 'Expected the lockdown fragments\' authorization blocks to be read.');
		$this->assertSame([], $refused, 'Authorization actions an older OpenRegister refuses: ' . implode(', ', $refused));

	}//end testEveryShippedAuthorizationActionIsAccepted()

	/**
	 * Walk a decoded file and record every authorization action outside the accepted set.
	 *
	 * @param array             $node    The node.
	 * @param string            $file    The file name, for the message.
	 * @param string            $path    The path so far.
	 * @param int               $blocks  Authorization blocks seen.
	 * @param array<int,string> $refused Refused actions, as file:path:action.
	 *
	 * @return void
	 */
	private function collect(array $node, string $file, string $path, int &$blocks, array &$refused): void {
		foreach ($node as $key => $value) {
			if ($key === 'authorization' && is_array($value) === true) {
				$blocks++;
				foreach (array_keys($value) as $action) {
					if (in_array($action, self::ACCEPTED, true) === false) {
						$refused[] = $file . ':' . $path . ':' . $action;
					}
				}
			}

			if (is_array($value) === true) {
				$this->collect(node: $value, file: $file, path: $path . '/' . $key, blocks: $blocks, refused: $refused);
			}
		}

	}//end collect()
}//end class
