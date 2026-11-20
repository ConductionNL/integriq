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
	 * @var GraphqlTokenStream
	 */
	private GraphqlTokenStream $stream;

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

		$this->stream = new GraphqlTokenStream(tokens: (new GraphqlLexer())->tokenize(source: $document));

		[$operations, $fragments] = $this->definitions();

		return (new GraphqlOperationMeasure())->measure(operations: $operations, fragments: $fragments, operationName: $operationName);

	}//end read()

	/**
	 * Every definition of the document, split into operations and fragments by name.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: array<string, list<array<string, mixed>>>}
	 *
	 * @throws UnexpectedValueException On a malformed document or a fragment defined twice.
	 */
	private function definitions(): array {
		$operations = [];
		$fragments  = [];
		while ($this->stream->peek() !== null) {
			$definition = $this->definition();
			if ($definition['kind'] !== 'fragment') {
				$operations[] = $definition;
				continue;
			}

			if (isset($fragments[$definition['name']]) === true) {
				throw new UnexpectedValueException('The fragment "' . $definition['name'] . '" is defined twice.');
			}

			$fragments[$definition['name']] = $definition['selections'];
		}

		return [$operations, $fragments];

	}//end definitions()

	/**
	 * One definition: an operation, a shorthand query or a fragment.
	 *
	 * @return array{kind: string, type?: string, name: string|null, selections: list<array<string, mixed>>}
	 *
	 * @throws UnexpectedValueException On anything else, such as a type system definition.
	 */
	private function definition(): array {
		$token = $this->stream->peek();
		if ($token === ['punct', '{']) {
			return ['kind' => 'operation', 'type' => 'query', 'name' => null, 'selections' => $this->selectionSet(nesting: 0)];
		}

		if ($token === ['name', 'fragment']) {
			return $this->fragmentDefinition();
		}

		if ($token !== null && $token[0] === 'name' && in_array($token[1], ['query', 'mutation', 'subscription'], true) === true) {
			return $this->operationDefinition(type: $token[1]);
		}

		throw new UnexpectedValueException('The GraphQL document holds something other than operations and fragments.');

	}//end definition()

	/**
	 * An operation definition: `type name? variables? directives? { ... }`.
	 *
	 * @param string $type The operation type keyword at the current token.
	 *
	 * @return array{kind: string, type: string, name: string|null, selections: list<array<string, mixed>>}
	 *
	 * @throws UnexpectedValueException On a malformed operation.
	 */
	private function operationDefinition(string $type): array {
		$this->stream->skip();
		$name = null;
		if ($this->stream->peek() !== null && $this->stream->peek()[0] === 'name') {
			$name = $this->stream->next()[1];
		}

		$this->stream->skipArguments();
		$this->stream->directives();

		return ['kind' => 'operation', 'type' => $type, 'name' => $name, 'selections' => $this->selectionSet(nesting: 0)];

	}//end operationDefinition()

	/**
	 * A fragment definition: `fragment name on Type directives? { ... }`.
	 *
	 * @return array{kind: string, name: string, selections: list<array<string, mixed>>}
	 *
	 * @throws UnexpectedValueException On a malformed fragment.
	 */
	private function fragmentDefinition(): array {
		$this->stream->skip();
		$name = $this->stream->expectName();
		if ($name === 'on') {
			throw new UnexpectedValueException('A fragment cannot be named "on".');
		}

		$this->stream->expect(kind: 'name', value: 'on');
		$this->stream->expectName();
		$this->stream->directives();

		return ['kind' => 'fragment', 'name' => $name, 'selections' => $this->selectionSet(nesting: 0)];

	}//end fragmentDefinition()

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

		$this->stream->expect(kind: 'punct', value: '{');
		$selections = [];
		while ($this->stream->peek() !== ['punct', '}']) {
			if ($this->stream->peek() === null) {
				throw new UnexpectedValueException('The GraphQL document ends inside a selection.');
			}

			$selections[] = $this->selection(nesting: $nesting);
		}

		$this->stream->skip();
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
		if ($this->stream->peek() === ['punct', '...']) {
			$this->stream->skip();
			return $this->spreadSelection(nesting: $nesting);
		}

		$name = $this->stream->expectName();
		if ($this->stream->peek() === ['punct', ':']) {
			$this->stream->skip();
			$name = $this->stream->expectName();
		}

		$this->stream->skipArguments();
		$this->stream->directives();
		$selections = [];
		if ($this->stream->peek() === ['punct', '{']) {
			$selections = $this->selectionSet(nesting: ($nesting + 1));
		}

		return ['kind' => 'field', 'name' => $name, 'selections' => $selections];

	}//end selection()

	/**
	 * The selection after a spread: a fragment spread or an inline fragment.
	 *
	 * @param int $nesting How deep the reader already is.
	 *
	 * @return array<string, mixed> The selection.
	 *
	 * @throws UnexpectedValueException On a malformed selection.
	 */
	private function spreadSelection(int $nesting): array {
		$next = $this->stream->peek();
		if ($next !== null && $next[0] === 'name' && $next[1] !== 'on') {
			$this->stream->skip();
			$this->stream->directives();
			return ['kind' => 'spread', 'name' => $next[1]];
		}

		if ($next === ['name', 'on']) {
			$this->stream->skip();
			$this->stream->expectName();
		}

		$this->stream->directives();

		return ['kind' => 'inline', 'selections' => $this->selectionSet(nesting: ($nesting + 1))];

	}//end spreadSelection()

}//end class
