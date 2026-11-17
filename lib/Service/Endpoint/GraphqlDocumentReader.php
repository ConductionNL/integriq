<?php

/**
 * Integriq GraphqlDocumentReader.
 *
 * Reads a GraphQL request document far enough to govern it: which operation
 * it runs (type and name), how deep its selection nests once fragments are
 * expanded, and which fields it asks for at the root. It never executes
 * anything and never resolves a type: integriq proxies GraphQL, the source
 * executes it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use UnexpectedValueException;

/**
 * A lexer and a parser for the executable part of the GraphQL grammar
 * (October 2021 edition): operations, fragments, selections, arguments,
 * variables and directives.
 *
 * It is a real tokenizer, not a pattern over the text, because GraphQL lets
 * an alias, a fragment, a comment or a string hide what a pattern would read
 * (design D1). A document it cannot read is refused, never passed on.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlDocumentReader {

	/**
	 * The longest document read, in bytes; a larger one is refused unread.
	 */
	public const MAX_DOCUMENT_BYTES = 262144;

	/**
	 * The deepest nesting followed while reading; past it the document is refused.
	 */
	private const MAX_NESTING = 200;

	/**
	 * Punctuators of the grammar, one character each except the spread.
	 */
	private const PUNCTUATORS = '!$&()...:=@[]{|}';

	/**
	 * The tokens of the document being read.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $tokens = [];

	/**
	 * The position of the next token.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * Read the operation a request runs.
	 *
	 * @param string      $document      The GraphQL document.
	 * @param string|null $operationName The `operationName` of the request, when given.
	 *
	 * @return GraphqlOperation The operation, its depth and its root fields.
	 *
	 * @throws UnexpectedValueException When the document cannot be read or names no single operation.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function read(string $document, ?string $operationName=null): GraphqlOperation {
		if (strlen($document) > self::MAX_DOCUMENT_BYTES) {
			throw new UnexpectedValueException('The GraphQL document is too large.');
		}

		$this->tokens = $this->tokenize(source: $document);
		$this->pos    = 0;

		$operations = [];
		$fragments  = [];
		while ($this->peek() !== null) {
			$definition = $this->definition();
			if ($definition['kind'] === 'fragment') {
				if (isset($fragments[$definition['name']]) === true) {
					throw new UnexpectedValueException('The fragment "' . $definition['name'] . '" is defined twice.');
				}

				$fragments[$definition['name']] = $definition['selections'];
				continue;
			}

			$operations[] = $definition;
		}

		$operation = $this->chosen(operations: $operations, operationName: $operationName);

		return new GraphqlOperation(
			type: $operation['type'],
			name: $operation['name'],
			depth: $this->depth(selections: $operation['selections'], fragments: $fragments, visiting: []),
			rootFields: $this->rootFields(selections: $operation['selections'], fragments: $fragments, visiting: [])
		);

	}//end read()

	/**
	 * The operation the request asks for (GraphQL spec, GetOperation).
	 *
	 * @param list<array<string, mixed>> $operations    The operations of the document.
	 * @param string|null                $operationName The requested name.
	 *
	 * @return array<string, mixed> The operation.
	 *
	 * @throws UnexpectedValueException When none or several match.
	 */
	private function chosen(array $operations, ?string $operationName): array {
		if ($operations === []) {
			throw new UnexpectedValueException('The GraphQL document holds no operation.');
		}

		if ($operationName !== null && $operationName !== '') {
			foreach ($operations as $operation) {
				if ($operation['name'] === $operationName) {
					return $operation;
				}
			}

			throw new UnexpectedValueException('The GraphQL document holds no operation named "' . $operationName . '".');
		}

		if (count($operations) > 1) {
			throw new UnexpectedValueException('The GraphQL document holds several operations; name one in operationName.');
		}

		return $operations[0];

	}//end chosen()

	/**
	 * One definition: an operation, a shorthand query or a fragment.
	 *
	 * @return array{kind: string, type?: string, name: string|null, selections: list<array<string, mixed>>}
	 *
	 * @throws UnexpectedValueException On anything else, such as a type system definition.
	 */
	private function definition(): array {
		$token = $this->peek();
		if ($token === ['punct', '{']) {
			return ['kind' => 'operation', 'type' => 'query', 'name' => null, 'selections' => $this->selectionSet(nesting: 0)];
		}

		if ($token !== null && $token[0] === 'name' && in_array($token[1], ['query', 'mutation', 'subscription'], true) === true) {
			$this->pos++;
			$name = null;
			if ($this->peek() !== null && $this->peek()[0] === 'name') {
				$name = $this->next()[1];
			}

			if ($this->peek() === ['punct', '(']) {
				$this->skipBalanced(open: '(', close: ')');
			}

			$this->directives();
			return ['kind' => 'operation', 'type' => $token[1], 'name' => $name, 'selections' => $this->selectionSet(nesting: 0)];
		}

		if ($token === ['name', 'fragment']) {
			$this->pos++;
			$name = $this->expectName();
			if ($name === 'on') {
				throw new UnexpectedValueException('A fragment cannot be named "on".');
			}

			$this->expect(kind: 'name', value: 'on');
			$this->expectName();
			$this->directives();
			return ['kind' => 'fragment', 'name' => $name, 'selections' => $this->selectionSet(nesting: 0)];
		}

		throw new UnexpectedValueException('The GraphQL document holds something other than operations and fragments.');

	}//end definition()

	/**
	 * A selection set: `{ selection+ }`.
	 *
	 * @param int $nesting How deep the reader already is.
	 *
	 * @return list<array<string, mixed>> The selections: fields, spreads and inline fragments.
	 *
	 * @throws UnexpectedValueException On a malformed set.
	 */
	private function selectionSet(int $nesting): array {
		if ($nesting > self::MAX_NESTING) {
			throw new UnexpectedValueException('The GraphQL document nests too deep to read.');
		}

		$this->expect(kind: 'punct', value: '{');
		$selections = [];
		while ($this->peek() !== ['punct', '}']) {
			if ($this->peek() === null) {
				throw new UnexpectedValueException('The GraphQL document ends inside a selection.');
			}

			$selections[] = $this->selection(nesting: $nesting);
		}

		$this->pos++;
		if ($selections === []) {
			throw new UnexpectedValueException('A selection set cannot be empty.');
		}

		return $selections;

	}//end selectionSet()

	/**
	 * One selection.
	 *
	 * @param int $nesting How deep the reader already is.
	 *
	 * @return array<string, mixed> The selection.
	 *
	 * @throws UnexpectedValueException On a malformed selection.
	 */
	private function selection(int $nesting): array {
		if ($this->peek() === ['punct', '...']) {
			$this->pos++;
			$next = $this->peek();
			if ($next !== null && $next[0] === 'name' && $next[1] !== 'on') {
				$this->pos++;
				$this->directives();
				return ['kind' => 'spread', 'name' => $next[1]];
			}

			if ($next === ['name', 'on']) {
				$this->pos++;
				$this->expectName();
			}

			$this->directives();
			return ['kind' => 'inline', 'selections' => $this->selectionSet(nesting: ($nesting + 1))];
		}

		$name = $this->expectName();
		if ($this->peek() === ['punct', ':']) {
			$this->pos++;
			$name = $this->expectName();
		}

		if ($this->peek() === ['punct', '(']) {
			$this->skipBalanced(open: '(', close: ')');
		}

		$this->directives();
		$selections = [];
		if ($this->peek() === ['punct', '{']) {
			$selections = $this->selectionSet(nesting: ($nesting + 1));
		}

		return ['kind' => 'field', 'name' => $name, 'selections' => $selections];

	}//end selection()

	/**
	 * Skip directives: `@name(arguments)?` any number of times.
	 *
	 * @return void
	 */
	private function directives(): void {
		while ($this->peek() === ['punct', '@']) {
			$this->pos++;
			$this->expectName();
			if ($this->peek() === ['punct', '(']) {
				$this->skipBalanced(open: '(', close: ')');
			}
		}

	}//end directives()

	/**
	 * Skip a balanced group of tokens, such as arguments or variable definitions.
	 *
	 * Strings are single tokens, so a bracket inside a string never counts.
	 *
	 * @param string $open  The opening punctuator.
	 * @param string $close The closing punctuator.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the group does not close.
	 */
	private function skipBalanced(string $open, string $close): void {
		$level = 0;
		do {
			$token = $this->next();
			if ($token === null) {
				throw new UnexpectedValueException('The GraphQL document ends inside "' . $open . '".');
			}

			if ($token === ['punct', $open]) {
				$level++;
			} elseif ($token === ['punct', $close]) {
				$level--;
			}
		} while ($level > 0);

	}//end skipBalanced()

	/**
	 * How deep a selection nests: a field counts one, a fragment counts nothing itself.
	 *
	 * @param list<array<string, mixed>>               $selections The selections.
	 * @param array<string, list<array<string, mixed>>> $fragments  The fragments by name.
	 * @param array<string, true>                      $visiting   The fragments being expanded.
	 *
	 * @return int The depth.
	 *
	 * @throws UnexpectedValueException On an unknown or cyclic fragment.
	 */
	private function depth(array $selections, array $fragments, array $visiting): int {
		$deepest = 0;
		foreach ($selections as $selection) {
			$depth = match ($selection['kind']) {
				'field' => (1 + $this->depth(selections: $selection['selections'], fragments: $fragments, visiting: $visiting)),
				'inline' => $this->depth(selections: $selection['selections'], fragments: $fragments, visiting: $visiting),
				default => $this->depth(
					selections: $this->fragment(name: $selection['name'], fragments: $fragments, visiting: $visiting),
					fragments: $fragments,
					visiting: ($visiting + [$selection['name'] => true])
				),
			};
			$deepest = max($deepest, $depth);
		}

		return $deepest;

	}//end depth()

	/**
	 * The names of the fields an operation asks for at its root.
	 *
	 * @param list<array<string, mixed>>               $selections The root selections.
	 * @param array<string, list<array<string, mixed>>> $fragments  The fragments by name.
	 * @param array<string, true>                      $visiting   The fragments being expanded.
	 *
	 * @return list<string> The field names, each once.
	 */
	private function rootFields(array $selections, array $fragments, array $visiting): array {
		$names = [];
		foreach ($selections as $selection) {
			if ($selection['kind'] === 'field') {
				$names[] = $selection['name'];
				continue;
			}

			$inner = $selection['selections'] ?? null;
			$next  = $visiting;
			if ($selection['kind'] === 'spread') {
				$inner = $this->fragment(name: $selection['name'], fragments: $fragments, visiting: $visiting);
				$next  = ($visiting + [$selection['name'] => true]);
			}

			$names = array_merge($names, $this->rootFields(selections: $inner, fragments: $fragments, visiting: $next));
		}

		return array_values(array_unique($names));

	}//end rootFields()

	/**
	 * The selections of a fragment named in a spread.
	 *
	 * @param string                                    $name      The fragment name.
	 * @param array<string, list<array<string, mixed>>> $fragments The fragments by name.
	 * @param array<string, true>                       $visiting  The fragments being expanded.
	 *
	 * @return list<array<string, mixed>> Its selections.
	 *
	 * @throws UnexpectedValueException On an unknown or cyclic fragment.
	 */
	private function fragment(string $name, array $fragments, array $visiting): array {
		if (isset($fragments[$name]) === false) {
			throw new UnexpectedValueException('The fragment "' . $name . '" is not defined.');
		}

		if (isset($visiting[$name]) === true) {
			throw new UnexpectedValueException('The fragment "' . $name . '" spreads itself.');
		}

		return $fragments[$name];

	}//end fragment()

	/**
	 * The next token without taking it.
	 *
	 * @return array{0: string, 1: string}|null The token, or null at the end.
	 */
	private function peek(): ?array {
		return ($this->tokens[$this->pos] ?? null);

	}//end peek()

	/**
	 * Take the next token.
	 *
	 * @return array{0: string, 1: string}|null The token, or null at the end.
	 */
	private function next(): ?array {
		$token = $this->peek();
		if ($token !== null) {
			$this->pos++;
		}

		return $token;

	}//end next()

	/**
	 * Take a token of this kind and value, or refuse the document.
	 *
	 * @param string $kind  The token kind.
	 * @param string $value The token value.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the next token is another.
	 */
	private function expect(string $kind, string $value): void {
		if ($this->next() !== [$kind, $value]) {
			throw new UnexpectedValueException('The GraphQL document is malformed: expected "' . $value . '".');
		}

	}//end expect()

	/**
	 * Take a name token, or refuse the document.
	 *
	 * @return string The name.
	 *
	 * @throws UnexpectedValueException When the next token is not a name.
	 */
	private function expectName(): string {
		$token = $this->next();
		if ($token === null || $token[0] !== 'name') {
			throw new UnexpectedValueException('The GraphQL document is malformed: expected a name.');
		}

		return $token[1];

	}//end expectName()

	/**
	 * Split a document into tokens, dropping whitespace, commas and comments.
	 *
	 * @param string $source The document.
	 *
	 * @return list<array{0: string, 1: string}> The tokens as [kind, value].
	 *
	 * @throws UnexpectedValueException On a character or string the grammar does not allow.
	 */
	private function tokenize(string $source): array {
		$tokens = [];
		$length = strlen($source);
		$i      = 0;
		if (str_starts_with($source, "\xEF\xBB\xBF") === true) {
			$i = 3;
		}

		while ($i < $length) {
			$char = $source[$i];
			if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === ',') {
				$i++;
				continue;
			}

			if ($char === '#') {
				while ($i < $length && $source[$i] !== "\n" && $source[$i] !== "\r") {
					$i++;
				}

				continue;
			}

			if ($char === '.') {
				if (substr($source, $i, 3) !== '...') {
					throw new UnexpectedValueException('The GraphQL document holds a lone ".".');
				}

				$tokens[] = ['punct', '...'];
				$i       += 3;
				continue;
			}

			if (str_contains(self::PUNCTUATORS, $char) === true) {
				$tokens[] = ['punct', $char];
				$i++;
				continue;
			}

			if ($char === '"') {
				$i        = $this->skipString(source: $source, start: $i);
				$tokens[] = ['string', ''];
				continue;
			}

			if (preg_match('/\G[_A-Za-z][_0-9A-Za-z]*/', $source, $match, 0, $i) === 1) {
				$tokens[] = ['name', $match[0]];
				$i       += strlen($match[0]);
				continue;
			}

			if (preg_match('/\G-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/', $source, $match, 0, $i) === 1 && $match[0] !== '-') {
				$tokens[] = ['number', $match[0]];
				$i       += strlen($match[0]);
				continue;
			}

			throw new UnexpectedValueException('The GraphQL document holds a character the grammar does not allow.');
		}//end while

		return $tokens;

	}//end tokenize()

	/**
	 * Skip a string or block string starting at a quote.
	 *
	 * @param string $source The document.
	 * @param int    $start  The position of the opening quote.
	 *
	 * @return int The position after the closing quote.
	 *
	 * @throws UnexpectedValueException When the string does not close.
	 */
	private function skipString(string $source, int $start): int {
		$length = strlen($source);
		if (substr($source, $start, 3) === '"""') {
			$i = ($start + 3);
			while ($i < $length) {
				if (substr($source, $i, 4) === '\\"""') {
					$i += 4;
					continue;
				}

				if (substr($source, $i, 3) === '"""') {
					return ($i + 3);
				}

				$i++;
			}

			throw new UnexpectedValueException('The GraphQL document holds a block string that does not close.');
		}

		$i = ($start + 1);
		while ($i < $length) {
			$char = $source[$i];
			if ($char === '\\') {
				$i += 2;
				continue;
			}

			if ($char === '"') {
				return ($i + 1);
			}

			if ($char === "\n" || $char === "\r") {
				break;
			}

			$i++;
		}

		throw new UnexpectedValueException('The GraphQL document holds a string that does not close.');

	}//end skipString()
}//end class
