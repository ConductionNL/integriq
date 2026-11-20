<?php

/**
 * Integriq GraphqlTokenStream.
 *
 * The cursor over the tokens of a GraphQL document the reader parses: look
 * at the next token, take it, require it, and skip the groups (arguments,
 * variables, directives) that governing a request never needs.
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
 * A cursor over the tokens of one GraphQL document.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlTokenStream {

	/**
	 * The position of the next token.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * Constructor for GraphqlTokenStream.
	 *
	 * @param list<array{0: string, 1: string}> $tokens The tokens as [kind, value].
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $tokens,
	) {

	}//end __construct()

	/**
	 * Take the next token without looking at it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function skip(): void {
		$this->pos++;

	}//end skip()

	/**
	 * Skip directives: `@name(arguments)?` any number of times.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function directives(): void {
		while ($this->peek() === ['punct', '@']) {
			$this->pos++;
			$this->expectName();
			$this->skipArguments();
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
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function skipBalanced(string $open, string $close): void {
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
	 * Skip an argument or variable list when one follows.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the list does not close.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function skipArguments(): void {
		if ($this->peek() === ['punct', '(']) {
			$this->skipBalanced(open: '(', close: ')');
		}

	}//end skipArguments()

	/**
	 * The next token without taking it.
	 *
	 * @return array{0: string, 1: string}|null The token, or null at the end.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function peek(): ?array {
		return ($this->tokens[$this->pos] ?? null);

	}//end peek()

	/**
	 * Take the next token.
	 *
	 * @return array{0: string, 1: string}|null The token, or null at the end.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function next(): ?array {
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
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function expect(string $kind, string $value): void {
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
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function expectName(): string {
		$token = $this->next();
		if ($token === null || $token[0] !== 'name') {
			throw new UnexpectedValueException('The GraphQL document is malformed: expected a name.');
		}

		return $token[1];

	}//end expectName()
}//end class
