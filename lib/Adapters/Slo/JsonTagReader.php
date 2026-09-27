<?php

/**
 * Integriq JSONTag reader for SLO curriculum responses.
 *
 * SLO's `/tree/{id}` answers in JSONTag (`application/jsontag`): JSON with a
 * type annotation in front of a value, written like an HTML start tag.
 *
 *     <object class="FoSet" id="/uuid/612a...">{"title":"Kerndoelen burgerschap", ...}
 *     "Niveau": [<link>"/uuid/512e..."]
 *
 * This reader turns that into plain PHP arrays: an `object` annotation's
 * `class` and `id` become the `@type` and `@id` keys of the object that
 * follows, a `<link>"Y"` value becomes `['@link' => 'Y']`, and every other
 * annotation (`<uuid>`, `<date>`, ...) is dropped. String contents are copied
 * verbatim, so a `<` inside a title is never read as an annotation. Nothing is
 * evaluated. Format reference: https://github.com/muze-nl/jsontag (read
 * 2026-09-27); SLO's server writes it with `JSONTag.stringify()`
 * (slonl/curriculum-rest-api, src/api-server.js, route `/tree/:id`).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use JsonException;
use OCA\Integriq\Exception\SloCurriculumException;

/**
 * Reads JSONTag (and plain JSON, which is valid JSONTag) into linked arrays.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
 */
final class JsonTagReader {
	/**
	 * Maximum nesting depth handed to json_decode().
	 */
	private const MAX_DEPTH = 1024;

	/**
	 * Characters that end a run of plain structural JSON text.
	 */
	private const RUN_STOPS = "\"<{} \t\r\n";

	/**
	 * Decode a JSONTag (or plain JSON) body.
	 *
	 * @param string $text The response body.
	 *
	 * @return mixed The decoded value, with `@type`/`@id` on annotated
	 *               objects and `['@link' => id]` for link values.
	 *
	 * @throws SloCurriculumException When the body is not valid JSONTag.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
	 */
	public function decode(string $text): mixed {
		$json = $this->toJson(text: $text);

		try {
			return json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new SloCurriculumException(
				message: 'The SLO response is neither valid JSON nor valid JSONTag: ' . $exception->getMessage(),
				previous: $exception
			);
		}
	}//end decode()

	/**
	 * Rewrite a JSONTag body as plain JSON text.
	 *
	 * @param string $text The JSONTag body.
	 *
	 * @return string Plain JSON.
	 *
	 * @throws SloCurriculumException When an annotation or a string is not closed.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
	 */
	public function toJson(string $text): string {
		$out = '';
		$length = strlen($text);
		$position = 0;
		$closeLinkAfterString = false;
		$pendingHeader = '';
		$needComma = false;

		while ($position < $length) {
			$char = $text[$position];

			// Whitespace never changes state.
			if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
				$out .= $char;
				$position++;
				continue;
			}

			// The first member after an injected `@type`/`@id` needs a comma,
			// unless the object is empty.
			if ($needComma === true) {
				if ($char !== '}') {
					$out .= ',';
				}

				$needComma = false;
			}

			if ($char === '"') {
				$pendingHeader = '';
				$end = $this->findStringEnd(text: $text, start: $position);
				$out .= substr($text, $position, ($end - $position + 1));
				$position = ($end + 1);
				if ($closeLinkAfterString === true) {
					$out .= '}';
					$closeLinkAfterString = false;
				}

				continue;
			}

			if ($char === '<') {
				$position = $this->consumeTag(
					text: $text,
					position: $position,
					out: $out,
					pendingHeader: $pendingHeader,
					closeLinkAfterString: $closeLinkAfterString
				);
				continue;
			}

			if ($char === '{' && $pendingHeader !== '') {
				$out .= '{' . $pendingHeader;
				$pendingHeader = '';
				$needComma = true;
				$position++;
				continue;
			}

			// Any other structural text: copy the whole run at once.
			$run = max(1, strcspn($text, self::RUN_STOPS, $position));
			$pendingHeader = '';
			$out .= substr($text, $position, $run);
			$position += $run;
		}//end while

		return $out;
	}//end toJson()

	/**
	 * Index every annotated object in a decoded document by its `@id`.
	 *
	 * Both the full id (`/uuid/<uuid>`) and the bare uuid after the last `/`
	 * are keys, so a `@link` of either form resolves. The first object seen
	 * under an id wins.
	 *
	 * @param mixed $document A value returned by decode().
	 *
	 * @return array<string,array<string,mixed>> Objects keyed by id.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
	 */
	public function indexById(mixed $document): array {
		$index = [];
		$stack = [$document];

		while ($stack !== []) {
			$value = array_pop($stack);
			if (is_array($value) === false) {
				continue;
			}

			$id = ($value['@id'] ?? null);
			if (is_string($id) === true && $id !== '') {
				$index[$id] ??= $value;
				$tail = self::lastSegment(value: $id);
				if ($tail !== '') {
					$index[$tail] ??= $value;
				}
			}

			foreach (array_reverse($value) as $child) {
				if (is_array($child) === true) {
					$stack[] = $child;
				}
			}
		}//end while

		return $index;
	}//end indexById()

	/**
	 * Replace a `['@link' => id]` value by the object it names, when known.
	 *
	 * @param mixed $value A decoded value.
	 * @param array<string,array<string,mixed>> $index The document index from indexById().
	 *
	 * @return mixed The linked object, or the value unchanged.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
	 */
	public function resolve(mixed $value, array $index): mixed {
		if (is_array($value) === false || count($value) !== 1 || isset($value['@link']) === false) {
			return $value;
		}

		$link = (string)$value['@link'];
		if (isset($index[$link]) === true) {
			return $index[$link];
		}

		return ($index[self::lastSegment(value: $link)] ?? $value);
	}//end resolve()

	/**
	 * The part of an id after its last `/` (the bare SLO uuid).
	 *
	 * @param string $value An id such as `/uuid/<uuid>` or a full URI.
	 *
	 * @return string The last segment, or the value when it has no `/`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
	 */
	public static function lastSegment(string $value): string {
		$slash = strrpos($value, '/');
		if ($slash === false) {
			return $value;
		}

		return substr($value, ($slash + 1));
	}//end lastSegment()

	/**
	 * Consume one annotation starting at `<` and apply its effect.
	 *
	 * @param string $text The body.
	 * @param int $position Offset of the `<`.
	 * @param string $out The JSON built so far (appended to for a link).
	 * @param string $pendingHeader Set to the `@type`/`@id` members for an object annotation.
	 * @param bool $closeLinkAfterString Set when a link value opens.
	 *
	 * @return int The offset after the closing `>`.
	 *
	 * @throws SloCurriculumException When the annotation is not closed.
	 */
	private function consumeTag(string $text, int $position, string &$out, string &$pendingHeader, bool &$closeLinkAfterString): int {
		$end = strpos($text, '>', $position);
		if ($end === false) {
			throw new SloCurriculumException(
				message: sprintf('A JSONTag annotation in the SLO response is not closed (offset %d).', $position)
			);
		}

		$tag = $this->parseTag(body: substr($text, ($position + 1), ($end - $position - 1)));

		if ($tag['name'] === 'link') {
			$out .= '{"@link":';
			$closeLinkAfterString = true;
		}

		if ($tag['name'] === 'object') {
			$pendingHeader = $this->objectHeader(attributes: $tag['attributes']);
		}

		return ($end + 1);
	}//end consumeTag()

	/**
	 * Find the offset of the closing quote of the string that starts at $start.
	 *
	 * @param string $text The body.
	 * @param int $start Offset of the opening quote.
	 *
	 * @return int Offset of the closing quote.
	 *
	 * @throws SloCurriculumException When the string never closes.
	 */
	private function findStringEnd(string $text, int $start): int {
		$length = strlen($text);
		$position = ($start + 1);

		while ($position < $length) {
			$position += strcspn($text, "\"\\", $position);
			if ($position >= $length) {
				break;
			}

			if ($text[$position] === '\\') {
				$position += 2;
				continue;
			}

			return $position;
		}

		throw new SloCurriculumException(
			message: sprintf('A string in the SLO response is not closed (it opens at offset %d).', $start)
		);
	}//end findStringEnd()

	/**
	 * Split an annotation body into its type name and attributes.
	 *
	 * @param string $body The text between `<` and `>`.
	 *
	 * @return array{name:string,attributes:array<string,string>} The parsed tag.
	 */
	private function parseTag(string $body): array {
		$name = '';
		if (preg_match('/^\s*([A-Za-z][A-Za-z0-9]*)/', $body, $match) === 1) {
			$name = strtolower($match[1]);
		}

		$attributes = [];
		if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)="([^"]*)"/', $body, $matches, PREG_SET_ORDER) > 0) {
			foreach ($matches as $attribute) {
				$attributes[$attribute[1]] = $attribute[2];
			}
		}

		return ['name' => $name, 'attributes' => $attributes];
	}//end parseTag()

	/**
	 * The JSON members an `object` annotation adds to the object it precedes.
	 *
	 * @param array<string,string> $attributes The annotation's attributes.
	 *
	 * @return string JSON members without braces, or '' when there are none.
	 */
	private function objectHeader(array $attributes): string {
		$members = [];
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if (isset($attributes['class']) === true) {
			$members[] = '"@type":' . json_encode($attributes['class'], $flags);
		}

		if (isset($attributes['id']) === true) {
			$members[] = '"@id":' . json_encode($attributes['id'], $flags);
		}

		return implode(',', $members);
	}//end objectHeader()
}//end class
