<?php

/**
 * Integriq OpenApiChecker.
 *
 * Checks a request or answer body against an operation of an OpenAPI document.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use InvalidArgumentException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds an operation and checks its body schema with the JSON Schema checker (design D4).
 *
 * The operation is found by `operationId`, or by the request method and path
 * (path templates such as `/personen/{bsn}` match any one segment). The body
 * schema is the request body's, or the answer's for its status (then `2XX`,
 * then `default`), JSON media type first. Local `$ref`s are inlined; a
 * remote one is reported, not fetched. OpenAPI 3.0 `nullable` is rewritten
 * to a JSON Schema type union before the check.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ValidationOutcome's named constructors build a value object;
 *   Yaml::parse is the library's only entry point.
 */
class OpenApiChecker {

	/**
	 * The HTTP methods an OpenAPI path item can hold.
	 *
	 * @var list<string>
	 */
	private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

	/**
	 * Constructor.
	 *
	 * @param JsonSchemaChecker $jsonSchema Checks the body against the operation's schema.
	 */
	public function __construct(
		private readonly JsonSchemaChecker $jsonSchema,
	) {

	}//end __construct()

	/**
	 * Check a body against an OpenAPI operation.
	 *
	 * @param string $document The OpenAPI document, JSON or YAML.
	 * @param mixed  $payload  The body, as decoded JSON.
	 * @param array  $context  `operationId`, or `method` and `path`; `direction` (`request` or `response`); `status` for an answer.
	 *
	 * @return ValidationOutcome
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function check(string $document, mixed $payload, array $context): ValidationOutcome {
		$openApi = self::parse(document: $document);
		if ($openApi instanceof stdClass === false) {
			return ValidationOutcome::failure(message: 'The OpenAPI document does not parse');
		}

		$operation = self::findOperation(openApi: $openApi, context: $context);
		if ($operation === null) {
			return ValidationOutcome::failure(message: 'The OpenAPI document has no operation ' . self::describeOperation(context: $context));
		}

		$references = new OpenApiReferenceResolver(openApi: $openApi);
		try {
			$schema = self::schemaOf(references: $references, holder: self::bodyHolder(operation: $operation, context: $context));
		} catch (InvalidArgumentException $e) {
			return ValidationOutcome::failure(message: $e->getMessage());
		}

		if ($schema === null) {
			return ValidationOutcome::valid();
		}

		return $this->jsonSchema->check(schema: $schema, payload: $payload);
	}//end check()

	/**
	 * The JSON Schema of a request body or response, references resolved; null when it declares none.
	 *
	 * @param OpenApiReferenceResolver $references The document's reference resolver.
	 * @param mixed                    $holder     The request body or response, possibly a reference.
	 *
	 * @return mixed
	 *
	 * @throws InvalidArgumentException When a reference cannot be resolved.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	private static function schemaOf(OpenApiReferenceResolver $references, mixed $holder): mixed {
		$holder = $references->resolve(node: $holder);
		$content = null;
		if ($holder instanceof stdClass === true) {
			$content = ($holder->content ?? null);
		}

		$schema = self::jsonSchemaOf(content: $content);
		if ($schema === null) {
			return null;
		}

		return self::toJsonSchema(schema: $references->resolve(node: $schema));
	}//end schemaOf()

	/**
	 * Why an OpenAPI document cannot be stored, or null when it parses.
	 *
	 * @param string $document The document, JSON or YAML.
	 *
	 * @return string|null The parser's message.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
	 */
	public function documentProblem(string $document): ?string {
		$openApi = json_decode($document, false);
		if (json_last_error() !== JSON_ERROR_NONE) {
			try {
				$openApi = Yaml::parse($document, Yaml::PARSE_OBJECT_FOR_MAP);
			} catch (\Throwable $e) {
				return 'The OpenAPI document does not parse: ' . $e->getMessage();
			}
		}

		if ($openApi instanceof stdClass === false) {
			return 'The OpenAPI document does not parse: it is not an object';
		}

		if (($openApi->paths ?? null) instanceof stdClass === false) {
			return 'The OpenAPI document has no paths object, so it describes no operation';
		}

		return null;
	}//end documentProblem()

	/**
	 * Parse JSON or YAML into objects.
	 *
	 * @param string $document The document.
	 *
	 * @return mixed The parsed document, or null.
	 */
	private static function parse(string $document): mixed {
		$decoded = json_decode($document, false);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		try {
			return Yaml::parse($document, Yaml::PARSE_OBJECT_FOR_MAP);
		} catch (\Throwable $e) {
			return null;
		}
	}//end parse()

	/**
	 * The operation the context names.
	 *
	 * @param stdClass $openApi The document.
	 * @param array    $context The context.
	 *
	 * @return stdClass|null
	 */
	private static function findOperation(stdClass $openApi, array $context): ?stdClass {
		$pathItems = array_filter(get_object_vars(($openApi->paths ?? new stdClass())), static fn ($item): bool => $item instanceof stdClass);
		foreach ($pathItems as $template => $pathItem) {
			foreach (self::operationsOf(pathItem: $pathItem) as $method => $operation) {
				if (self::isNamed(operation: $operation, method: $method, template: (string)$template, context: $context) === true) {
					return $operation;
				}
			}
		}

		return null;
	}//end findOperation()

	/**
	 * A path item's operations by method, in the order of self::METHODS.
	 *
	 * @param stdClass $pathItem The path item.
	 *
	 * @return array<string, stdClass>
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	private static function operationsOf(stdClass $pathItem): array {
		$operations = [];
		foreach (self::METHODS as $method) {
			if (($pathItem->{$method} ?? null) instanceof stdClass === true) {
				$operations[$method] = $pathItem->{$method};
			}
		}

		return $operations;
	}//end operationsOf()

	/**
	 * Whether an operation is the one the context names.
	 *
	 * @param stdClass $operation The operation.
	 * @param string   $method    Its method, lower case.
	 * @param string   $template  Its path template.
	 * @param array    $context   The context.
	 *
	 * @return bool
	 */
	private static function isNamed(stdClass $operation, string $method, string $template, array $context): bool {
		$operationId = (string)($context['operationId'] ?? '');
		if ($operationId !== '') {
			return (string)($operation->operationId ?? '') === $operationId;
		}

		return $method === strtolower((string)($context['method'] ?? ''))
			&& self::pathMatches(template: $template, path: (string)($context['path'] ?? '')) === true;
	}//end isNamed()

	/**
	 * Whether a request path matches a path template.
	 *
	 * @param string $template The template, such as `/personen/{bsn}`.
	 * @param string $path     The request path.
	 *
	 * @return bool
	 */
	private static function pathMatches(string $template, string $path): bool {
		$pattern = preg_replace('/\\\\\{[^\/]+?\\\\\}/', '[^/]+', preg_quote($template, '#'));

		return preg_match('#^' . $pattern . '/?$#', $path) === 1;
	}//end pathMatches()

	/**
	 * The request body, or the answer for the context's status.
	 *
	 * @param stdClass $operation The operation.
	 * @param array    $context   The context.
	 *
	 * @return mixed The request body or response object (maybe a `$ref`), or null.
	 */
	private static function bodyHolder(stdClass $operation, array $context): mixed {
		if ((string)($context['direction'] ?? 'request') !== 'response') {
			return ($operation->requestBody ?? null);
		}

		$responses = ($operation->responses ?? new stdClass());
		$status = (string)($context['status'] ?? '200');

		return ($responses->{$status} ?? $responses->{($status[0] ?? '2') . 'XX'} ?? $responses->default ?? null);
	}//end bodyHolder()

	/**
	 * The schema of a content map, JSON media type first.
	 *
	 * @param mixed $content The `content` object.
	 *
	 * @return mixed The schema, or null when there is none.
	 */
	private static function jsonSchemaOf(mixed $content): mixed {
		if ($content instanceof stdClass === false) {
			return null;
		}

		$media = array_filter(get_object_vars($content), static fn ($entry): bool => $entry instanceof stdClass && isset($entry->schema));
		$json = array_filter($media, static fn (string|int $type): bool => str_contains((string)$type, 'json'), ARRAY_FILTER_USE_KEY);
		$first = reset($json);
		if ($first === false) {
			$first = reset($media);
		}

		if ($first === false) {
			return null;
		}

		return $first->schema;
	}//end jsonSchemaOf()

	/**
	 * Rewrite OpenAPI 3.0 schema keywords JSON Schema reads differently.
	 *
	 * `nullable: true` becomes a type union with `null` (and `null` in an
	 * enum); the rewrite recurses into every subschema.
	 *
	 * @param mixed $schema The resolved schema.
	 *
	 * @return mixed
	 */
	private static function toJsonSchema(mixed $schema): mixed {
		if (is_array($schema) === true) {
			return array_map(static fn ($item) => self::toJsonSchema(schema: $item), $schema);
		}

		if ($schema instanceof stdClass === false) {
			return $schema;
		}

		$copy = self::withoutNullable(schema: $schema);
		if (($schema->nullable ?? false) === true) {
			return self::withNull(schema: $copy);
		}

		return $copy;
	}//end toJsonSchema()

	/**
	 * A converted copy of an object schema without its OpenAPI `nullable` keyword.
	 *
	 * @param stdClass $schema The OpenAPI schema object.
	 *
	 * @return stdClass
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	private static function withoutNullable(stdClass $schema): stdClass {
		$copy = new stdClass();
		foreach (get_object_vars($schema) as $key => $value) {
			if ($key !== 'nullable') {
				$copy->{$key} = self::toJsonSchema(schema: $value);
			}
		}

		return $copy;
	}//end withoutNullable()

	/**
	 * A schema that also accepts null.
	 *
	 * @param stdClass $schema The rewritten schema.
	 *
	 * @return stdClass
	 */
	private static function withNull(stdClass $schema): stdClass {
		if (isset($schema->type) === true) {
			$schema->type = array_values(array_unique(array_merge((array)$schema->type, ['null'])));
		}

		if (isset($schema->enum) === true && is_array($schema->enum) === true && in_array(null, $schema->enum, true) === false) {
			$schema->enum[] = null;
		}

		return $schema;
	}//end withNull()

	/**
	 * The operation a context names, for a message.
	 *
	 * @param array $context The context.
	 *
	 * @return string
	 */
	private static function describeOperation(array $context): string {
		$operationId = (string)($context['operationId'] ?? '');
		if ($operationId !== '') {
			return '"' . $operationId . '"';
		}

		return strtoupper((string)($context['method'] ?? '')) . ' ' . (string)($context['path'] ?? '');
	}//end describeOperation()
}//end class
