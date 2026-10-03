<?php

/**
 * Integriq OpenApiReferenceResolver.
 *
 * Inlines the local `$ref`s of one OpenAPI document.
 *
 * @category Service
 * @package  OCA\Integriq\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use InvalidArgumentException;
use stdClass;

/**
 * Follows `#/...` references inside the document it was made for.
 *
 * A reference to another document is reported, never fetched: a message
 * check makes no network call. A cycle deeper than {@see MAX_DEPTH} is
 * reported as one.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */
class OpenApiReferenceResolver {

	/**
	 * How deep references are followed before the document is called cyclic.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 32;

	/**
	 * Constructor.
	 *
	 * @param stdClass $openApi The parsed document (objects for maps).
	 */
	public function __construct(
		private readonly stdClass $openApi,
	) {

	}//end __construct()

	/**
	 * A copy of a node with every local reference inlined.
	 *
	 * @param mixed $node  The node.
	 * @param int   $depth How many references deep this is.
	 *
	 * @return mixed
	 *
	 * @throws InvalidArgumentException On a remote or missing reference, or a cycle.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function resolve(mixed $node, int $depth = 0): mixed {
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('The OpenAPI document refers to itself in a cycle deeper than ' . self::MAX_DEPTH);
		}

		if (is_array($node) === true) {
			return array_map(fn ($item) => $this->resolve(node: $item, depth: $depth), $node);
		}

		if ($node instanceof stdClass === false) {
			return $node;
		}

		if (isset($node->{'$ref'}) === true && is_string($node->{'$ref'}) === true) {
			return $this->resolve(node: $this->target(ref: $node->{'$ref'}), depth: ($depth + 1));
		}

		$copy = new stdClass();
		foreach (get_object_vars($node) as $key => $value) {
			$copy->{$key} = $this->resolve(node: $value, depth: $depth);
		}

		return $copy;
	}//end resolve()

	/**
	 * The node a local reference points at.
	 *
	 * @param string $ref The reference.
	 *
	 * @return mixed
	 *
	 * @throws InvalidArgumentException On a remote or missing reference.
	 */
	private function target(string $ref): mixed {
		if (str_starts_with($ref, '#/') === false) {
			throw new InvalidArgumentException('The OpenAPI document refers to ' . $ref . ', which is not fetched');
		}

		$node = $this->openApi;
		foreach (explode('/', substr($ref, 2)) as $segment) {
			$segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
			if ($node instanceof stdClass === false || property_exists($node, $segment) === false) {
				throw new InvalidArgumentException('The OpenAPI document refers to ' . $ref . ', which it does not contain');
			}

			$node = $node->{$segment};
		}

		return $node;
	}//end target()
}//end class
