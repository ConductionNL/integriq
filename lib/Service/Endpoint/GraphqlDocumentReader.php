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

		$this->tokens = (new GraphqlLexer())->tokenize(source: $document);
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

		return (new GraphqlOperationMeasure())->measure(operations: $operations, fragments: $fragments, operationName: $operationName);

	}//end read()

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

}//end class
