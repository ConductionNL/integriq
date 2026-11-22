<?php

/**
 * Integriq GraphqlLexer.
 *
 * Splits a GraphQL document into the tokens of its lexical grammar (October
 * 2021 edition), dropping whitespace, commas and comments. String values are
 * kept as an empty `string` token: governing a request never needs them.
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
 * The lexer of the executable GraphQL grammar.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlLexer {

	/**
	 * Punctuators of the grammar, one character each except the spread.
	 */
	private const PUNCTUATORS = '!$&():=@[]{|}';

	/**
	 * Whitespace, commas and comments: read past, never a token.
	 */
	private const IGNORED = '/\G(?:[ \t\n\r,]+|#[^\n\r]*)/';

	/**
	 * A name.
	 */
	private const NAME = '/\G[_A-Za-z][_0-9A-Za-z]*/';

	/**
	 * An int or float value.
	 */
	private const NUMBER = '/\G-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/';

	/**
	 * Split a document into tokens, dropping whitespace, commas and comments.
	 *
	 * @param string $source The document.
	 *
	 * @return list<array{0: string, 1: string}> The tokens as [kind, value].
	 *
	 * @throws UnexpectedValueException On a character or string the grammar does not allow.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function tokenize(string $source): array {
		$tokens = [];
		$length = strlen($source);
		$offset = 0;
		if (str_starts_with($source, "\xEF\xBB\xBF") === true) {
			$offset = 3;
		}

		while ($offset < $length) {
			[$token, $offset] = $this->token(source: $source, offset: $offset);
			if ($token !== null) {
				$tokens[] = $token;
			}
		}

		return $tokens;

	}//end tokenize()

	/**
	 * Read one lexical unit at an offset.
	 *
	 * @param string $source The document.
	 * @param int    $offset Where the unit starts.
	 *
	 * @return array{0: array{0: string, 1: string}|null, 1: int} The token (null for ignored text) and the offset after it.
	 *
	 * @throws UnexpectedValueException On a character or string the grammar does not allow.
	 */
	private function token(string $source, int $offset): array {
		if (preg_match(self::IGNORED, $source, $match, 0, $offset) === 1) {
			return [null, ($offset + strlen($match[0]))];
		}

		$char = $source[$offset];
		if ($char === '"') {
			return [['string', ''], $this->skipString(source: $source, start: $offset)];
		}

		if ($char === '.') {
			return [['punct', '...'], $this->spread(source: $source, offset: $offset)];
		}

		if (str_contains(self::PUNCTUATORS, $char) === true) {
			return [['punct', $char], ($offset + 1)];
		}

		return $this->word(source: $source, offset: $offset);

	}//end token()

	/**
	 * Read a spread (`...`) at an offset.
	 *
	 * @param string $source The document.
	 * @param int    $offset Where the spread starts.
	 *
	 * @return int The offset after it.
	 *
	 * @throws UnexpectedValueException On a lone or double dot.
	 */
	private function spread(string $source, int $offset): int {
		if (substr($source, $offset, 3) !== '...') {
			throw new UnexpectedValueException('The GraphQL document holds a lone ".".');
		}

		return ($offset + 3);

	}//end spread()

	/**
	 * Read a name or a number at an offset.
	 *
	 * @param string $source The document.
	 * @param int    $offset Where it starts.
	 *
	 * @return array{0: array{0: string, 1: string}, 1: int} The token and the offset after it.
	 *
	 * @throws UnexpectedValueException When neither starts here.
	 */
	private function word(string $source, int $offset): array {
		if (preg_match(self::NAME, $source, $match, 0, $offset) === 1) {
			return [['name', $match[0]], ($offset + strlen($match[0]))];
		}

		if (preg_match(self::NUMBER, $source, $match, 0, $offset) === 1 && $match[0] !== '-') {
			return [['number', $match[0]], ($offset + strlen($match[0]))];
		}

		throw new UnexpectedValueException('The GraphQL document holds a character the grammar does not allow.');

	}//end word()

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
		if (substr($source, $start, 3) === '"""') {
			return $this->blockStringEnd(source: $source, offset: ($start + 3));
		}

		return $this->stringEnd(source: $source, offset: ($start + 1));

	}//end skipString()

	/**
	 * The position after the closing quotes of a block string.
	 *
	 * @param string $source The document.
	 * @param int    $offset The position after the opening quotes.
	 *
	 * @return int
	 *
	 * @throws UnexpectedValueException When the block string does not close.
	 */
	private function blockStringEnd(string $source, int $offset): int {
		$length = strlen($source);
		while ($offset < $length) {
			if (substr($source, $offset, 4) === '\\"""') {
				$offset += 4;
				continue;
			}

			if (substr($source, $offset, 3) === '"""') {
				return ($offset + 3);
			}

			$offset++;
		}

		throw new UnexpectedValueException('The GraphQL document holds a block string that does not close.');

	}//end blockStringEnd()

	/**
	 * The position after the closing quote of a single-line string.
	 *
	 * @param string $source The document.
	 * @param int    $offset The position after the opening quote.
	 *
	 * @return int
	 *
	 * @throws UnexpectedValueException When the string does not close on its line.
	 */
	private function stringEnd(string $source, int $offset): int {
		$length = strlen($source);
		while ($offset < $length) {
			$offset += strcspn($source, "\\\"\n\r", $offset);
			$char    = ($source[$offset] ?? '');
			if ($char !== '\\') {
				break;
			}

			$offset += 2;
		}

		if (($source[$offset] ?? '') !== '"') {
			throw new UnexpectedValueException('The GraphQL document holds a string that does not close.');
		}

		return ($offset + 1);

	}//end stringEnd()
}//end class
