<?php

/**
 * Unit tests: every action integriq enforces is in the action seed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-every-enforced-action-is-in-the-seed-req-arc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Reads every PHP file under lib/ for `requireAction(` and `can(` calls and
 * resolves the action each one names: a string literal, a class constant (a
 * list constant gives each of its strings), or a `$parameter` of a private
 * helper, followed to the calls of that helper in the same file. A name built
 * from a prefix must use one of KNOWN_PREFIXES and have a seeded variant.
 * Anything it cannot resolve fails the test, naming the place, so a new kind
 * of call cannot slip past it.
 *
 * Reading source rather than loading classes keeps the test free of the
 * Nextcloud classes the controllers extend.
 *
 * @coversNothing
 */
class ActionSeedCoverageTest extends TestCase {

	private const LIB = __DIR__.'/../../../lib';

	/**
	 * Prefixes an action name is built from at run time, with what fills them.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_PREFIXES = [
		'event.subscribe-nextcloud-' => 'EventsController: the Nextcloud event domain (files, calendar, tables, forms)',
		'propertySource.query.' => 'PropertySourceController: a provider that is not public (brp)',
	];

	/**
	 * The file that defines requireAction() and can() rather than calling them.
	 *
	 * @var string
	 */
	private const DEFINITION = 'Service/ActionAuthService.php';

	/**
	 * The seed file, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function seed(): array {
		return json_decode((string) file_get_contents(self::LIB.'/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end seed()

	public function testEveryEnforcedActionIsSeeded(): void {
		$seeded = array_keys(self::seed()['actions']);
		$scan   = self::scanLib();

		$missing = [];
		foreach ($scan['actions'] as $action => $where) {
			if (in_array($action, $seeded, true) === false) {
				$missing[] = $action.' ('.$where.')';
			}
		}

		$this->assertSame([], $missing, 'Enforced actions missing from lib/actions.seed.json');
		$this->assertSame([], $scan['unresolved'], 'Action names this test cannot resolve; seed them and teach the test the form');
		$this->assertGreaterThan(60, count($scan['actions']), 'the scan finds the enforced actions at all');
	}//end testEveryEnforcedActionIsSeeded()

	public function testEveryPrefixIsKnownAndHasASeededVariant(): void {
		$seeded = array_keys(self::seed()['actions']);

		foreach (self::scanLib()['prefixes'] as $prefix => $where) {
			$this->assertArrayHasKey($prefix, self::KNOWN_PREFIXES, sprintf('Unknown action prefix "%s" at %s', $prefix, $where));
			$variants = array_filter($seeded, static fn (string $key): bool => str_starts_with($key, $prefix));
			$this->assertNotSame([], $variants, sprintf('No seeded action starts with "%s"', $prefix));
		}
	}//end testEveryPrefixIsKnownAndHasASeededVariant()

	public function testEverySeededActionDefaultsToAdminWithALabelAndAnArea(): void {
		$seed = self::seed();

		foreach ($seed['actions'] as $action => $groups) {
			$this->assertContains('admin', $groups, $action.' keeps admin');
			$this->assertNotEmpty($seed['labels'][$action] ?? null, $action.' has a label');
			$this->assertNotEmpty($seed['areas'][$action] ?? null, $action.' has an area');
		}

		$this->assertSame([], array_values(array_diff(array_keys($seed['labels']), array_keys($seed['actions']))), 'no label for an unseeded action');
		$this->assertSame([], array_values(array_diff(array_keys($seed['areas']), array_keys($seed['actions']))), 'no area for an unseeded action');
	}//end testEverySeededActionDefaultsToAdminWithALabelAndAnArea()

	public function testANewUnseededLiteralIsFoundByName(): void {
		$source = <<<'PHP'
		<?php
		namespace OCA\Integriq\Controller;
		class ExampleController {
			private const ACTION_LIST = 'example.list';
			private const TOOLS = ['a' => 'example.tool-a', 'b' => 'example.tool-b'];
			public function create(): void {
				$this->actionAuth->requireAction(user: $user, action: 'example.new');
				$this->actionAuth->can($user, self::ACTION_LIST);
				$this->actionAuth->requireAction(user: $user, action: self::TOOLS[$tool]);
				$this->gate(action: 'example.via-helper');
			}
			private function gate(string $action): void {
				$this->actionAuth->requireAction(user: $user, action: $action);
			}
			private function dynamic(string $name): void {
				$this->actionAuth->requireAction(user: $user, action: $name);
			}
			private function decide(): void {
				$action = $this->choice();
				$this->actionAuth->requireAction(user: $user, action: $action);
			}
			private function choice(): ?string {
				return 'example.chosen';
				return null;
			}//end choice()
		}
		PHP;

		$scan = self::scanSource(file: 'Controller/ExampleController.php', source: $source);

		$this->assertSame(['example.chosen', 'example.list', 'example.new', 'example.tool-a', 'example.tool-b', 'example.via-helper'], array_keys($scan['actions']));
		$this->assertSame(['Controller/ExampleController.php:16 $name'], $scan['unresolved']);
	}//end testANewUnseededLiteralIsFoundByName()

	/**
	 * Scan every PHP file under lib/.
	 *
	 * @return array{actions: array<string, string>, prefixes: array<string, string>, unresolved: list<string>}
	 */
	private static function scanLib(): array {
		$result = ['actions' => [], 'prefixes' => [], 'unresolved' => []];
		$files  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$relative = substr($file->getPathname(), strlen(self::LIB) + 1);
			if ($file->getExtension() !== 'php' || $relative === self::DEFINITION) {
				continue;
			}

			$one                  = self::scanSource(file: $relative, source: (string) file_get_contents($file->getPathname()));
			$result['actions']   += $one['actions'];
			$result['prefixes']  += $one['prefixes'];
			$result['unresolved'] = array_merge($result['unresolved'], $one['unresolved']);
		}

		ksort($result['actions']);
		sort($result['unresolved']);

		return $result;
	}//end scanLib()

	/**
	 * Scan one source file.
	 *
	 * @param string $file   Its path under lib/, for messages.
	 * @param string $source Its source.
	 *
	 * @return array{actions: array<string, string>, prefixes: array<string, string>, unresolved: list<string>}
	 */
	private static function scanSource(string $file, string $source): array {
		$result = ['actions' => [], 'prefixes' => [], 'unresolved' => []];
		if (preg_match_all('/(?:->|::)(requireAction|can)\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE) === 0) {
			return $result;
		}

		foreach ($calls[0] as $call) {
			$expression = self::argument(arguments: self::arguments(source: $source, open: $call[1] + strlen($call[0])), name: 'action', position: 1);
			if ($expression === null) {
				continue;
			}

			$where = $file.':'.(substr_count(substr($source, 0, $call[1]), "\n") + 1);
			self::resolve(source: $source, offset: $call[1], expression: $expression, where: $where, result: $result, depth: 0);
		}

		ksort($result['actions']);

		return $result;
	}//end scanSource()

	/**
	 * Resolve one action expression into the result.
	 *
	 * @param string                                                                                         $source     The file's source.
	 * @param int                                                                                            $offset     Where the expression is used.
	 * @param string                                                                                         $expression The expression.
	 * @param string                                                                                         $where      file:line, for messages.
	 * @param array{actions: array<string, string>, prefixes: array<string, string>, unresolved: list<string>} $result The result so far.
	 * @param int                                                                                            $depth      Helper calls followed so far.
	 *
	 * @return void
	 */
	private static function resolve(string $source, int $offset, string $expression, string $where, array &$result, int $depth): void {
		$expression = trim($expression);
		while (preg_match('/^\((.*)\)$/s', $expression, $inner) === 1) {
			$expression = trim($inner[1]);
		}

		if (preg_match('/^([\'"])([^\'"]+)\1$/', $expression, $literal) === 1) {
			$result['actions'][$literal[2]] ??= $where;
			return;
		}

		if (preg_match('/^(self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)(\[[^\]]*\])?$/', $expression, $constant) === 1) {
			$values = self::constant(source: $source, class: $constant[1], name: $constant[2]);
			if ($values === []) {
				$result['unresolved'][] = $where.' '.$expression;
			}

			foreach ($values as $value) {
				$result['actions'][$value] ??= $where;
			}

			return;
		}

		if (preg_match('/^(.+?)\s*\.\s*\$\w+$/s', $expression, $concat) === 1) {
			$prefix = self::constantOrLiteral(source: $source, expression: trim($concat[1]));
			if ($prefix !== null) {
				$result['prefixes'][$prefix] ??= $where;
				return;
			}
		}

		if (preg_match('/^\$(\w+)$/', $expression, $variable) === 1 && $depth < 3) {
			$helper = self::enclosingFunction(source: $source, offset: $offset);
			if ($helper !== null && ($position = array_search($variable[1], $helper['parameters'], true)) !== false) {
				$followed = false;
				$pattern  = '/(?:->|::)'.preg_quote($helper['name'], '/').'\s*\(/';
				preg_match_all($pattern, $source, $uses, PREG_OFFSET_CAPTURE);
				foreach ($uses[0] as $use) {
					$argument = self::argument(arguments: self::arguments(source: $source, open: $use[1] + strlen($use[0])), name: $variable[1], position: (int) $position);
					if ($argument !== null) {
						$followed = true;
						self::resolve(source: $source, offset: $use[1], expression: $argument, where: $where, result: $result, depth: $depth + 1);
					}
				}

				if ($followed === true) {
					return;
				}
			}

			$returns = self::assignedReturns(source: $source, offset: $offset, variable: $variable[1]);
			if ($returns !== []) {
				foreach ($returns as $value) {
					$result['actions'][$value] ??= $where;
				}

				return;
			}
		}//end if

		$result['unresolved'][] = $where.' '.$expression;
	}//end resolve()

	/**
	 * The strings a variable can hold when the function using it assigned it
	 * from a method of the same file whose every `return` is a string literal
	 * or null: `$action = $this->decision(...)`.
	 *
	 * @param string $source   The source.
	 * @param int    $offset   Where the variable is used.
	 * @param string $variable The variable, without `$`.
	 *
	 * @return list<string>
	 */
	private static function assignedReturns(string $source, int $offset, string $variable): array {
		$before = substr($source, 0, $offset);
		$start  = strrpos($before, 'function ');
		if ($start === false) {
			return [];
		}

		$pattern = '/\$'.preg_quote($variable, '/').'\s*=\s*\$this->(\w+)\s*\(/';
		if (preg_match($pattern, substr($before, $start), $assignment) !== 1) {
			return [];
		}

		$body = '/function\s+'.preg_quote($assignment[1], '/').'\s*\(.*?\/\/end '.preg_quote($assignment[1], '/').'\(\)/s';
		if (preg_match($body, $source, $method) !== 1 || preg_match_all('/return\s+([^;]+);/', $method[0], $returns) === 0) {
			return [];
		}

		$values = [];
		foreach ($returns[1] as $returned) {
			$returned = trim($returned);
			if ($returned === 'null') {
				continue;
			}

			if (preg_match('/^([\'"])([^\'"]+)\1$/', $returned, $literal) !== 1) {
				return [];
			}

			$values[] = $literal[2];
		}

		return $values;
	}//end assignedReturns()

	/**
	 * The text between a call's parentheses.
	 *
	 * @param string $source The source.
	 * @param int    $open   Just after the opening parenthesis.
	 *
	 * @return string
	 */
	private static function arguments(string $source, int $open): string {
		$depth = 1;
		$at    = $open;
		$quote = null;
		for ($length = strlen($source); $at < $length && $depth > 0; $at++) {
			$char = $source[$at];
			if ($quote !== null) {
				if ($char === '\\') {
					$at++;
				} else if ($char === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($char === '\'' || $char === '"') {
				$quote = $char;
			} else if ($char === '(' || $char === '[') {
				$depth++;
			} else if ($char === ')' || $char === ']') {
				$depth--;
			}
		}

		return substr($source, $open, $at - $open - 1);
	}//end arguments()

	/**
	 * One argument of a call, by name or else by position.
	 *
	 * @param string $arguments The text between the parentheses.
	 * @param string $name      The parameter name.
	 * @param int    $position  Its position, from zero.
	 *
	 * @return string|null
	 */
	private static function argument(string $arguments, string $name, int $position): ?string {
		$parts = [];
		$depth = 0;
		$quote = null;
		$part  = '';
		for ($at = 0, $length = strlen($arguments); $at < $length; $at++) {
			$char = $arguments[$at];
			if ($quote === null && $char === ',' && $depth === 0) {
				$parts[] = trim($part);
				$part    = '';
				continue;
			}

			if ($quote !== null) {
				if ($char === $quote) {
					$quote = null;
				}
			} else if ($char === '\'' || $char === '"') {
				$quote = $char;
			} else if (in_array($char, ['(', '['], true) === true) {
				$depth++;
			} else if (in_array($char, [')', ']'], true) === true) {
				$depth--;
			}

			$part .= $char;
		}//end for

		if (trim($part) !== '') {
			$parts[] = trim($part);
		}

		foreach ($parts as $candidate) {
			if (preg_match('/^'.preg_quote($name, '/').'\s*:\s*(.+)$/s', $candidate, $named) === 1) {
				return trim($named[1]);
			}
		}

		$positional = $parts[$position] ?? null;
		if ($positional === null || preg_match('/^\w+\s*:(?!:)/', $positional) === 1) {
			return null;
		}

		return $positional;
	}//end argument()

	/**
	 * The function a source offset sits in, with its parameter names in order.
	 *
	 * @param string $source The source.
	 * @param int    $offset The offset.
	 *
	 * @return array{name: string, parameters: list<string>}|null
	 */
	private static function enclosingFunction(string $source, int $offset): ?array {
		if (preg_match_all('/function\s+(\w+)\s*\(/', substr($source, 0, $offset), $functions, PREG_OFFSET_CAPTURE) === 0) {
			return null;
		}

		$last       = count($functions[0]) - 1;
		$signature  = self::arguments(source: $source, open: $functions[0][$last][1] + strlen($functions[0][$last][0]));
		preg_match_all('/\$(\w+)/', $signature, $parameters);

		return ['name' => $functions[1][$last][0], 'parameters' => $parameters[1]];
	}//end enclosingFunction()

	/**
	 * A string literal or a string constant's value.
	 *
	 * @param string $source     The source the expression is in.
	 * @param string $expression The expression.
	 *
	 * @return string|null
	 */
	private static function constantOrLiteral(string $source, string $expression): ?string {
		if (preg_match('/^([\'"])([^\'"]+)\1$/', $expression, $literal) === 1) {
			return $literal[2];
		}

		if (preg_match('/^(self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)$/', $expression, $constant) === 1) {
			return self::constant(source: $source, class: $constant[1], name: $constant[2])[0] ?? null;
		}

		return null;
	}//end constantOrLiteral()

	/**
	 * The strings a class constant holds: its value, or each string of a list.
	 *
	 * @param string $source The source of the class that names it.
	 * @param string $class  `self`, `static` or a class imported into that source.
	 * @param string $name   The constant.
	 *
	 * @return list<string>
	 */
	private static function constant(string $source, string $class, string $name): array {
		if ($class !== 'self' && $class !== 'static') {
			if (preg_match('/^use\s+OCA\\\\Integriq\\\\([\w\\\\]+\\\\'.preg_quote($class, '/').');/m', $source, $import) !== 1) {
				return [];
			}

			$path   = self::LIB.'/'.str_replace('\\', '/', $import[1]).'.php';
			$source = (string) @file_get_contents($path);
		}

		if (preg_match('/const\s+'.preg_quote($name, '/').'\s*=\s*(.+?);/s', $source, $definition) !== 1) {
			return [];
		}

		preg_match_all('/=>\s*([\'"])([^\'"]+)\1|^\s*\[?\s*([\'"])([^\'"]+)\3|,\s*([\'"])([^\'"]+)\5/m', $definition[1], $strings, PREG_SET_ORDER);
		$values = [];
		foreach ($strings as $string) {
			$values[] = ($string[2] ?? '') !== '' ? $string[2] : (($string[4] ?? '') !== '' ? $string[4] : $string[6]);
		}

		if (str_starts_with(trim($definition[1]), '[') === false) {
			return array_slice($values, 0, 1);
		}

		if (str_contains($definition[1], '=>') === true) {
			preg_match_all('/=>\s*([\'"])([^\'"]+)\1/', $definition[1], $mapped);
			return $mapped[2];
		}

		return $values;
	}//end constant()
}//end class
