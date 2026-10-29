<?php

/**
 * Integriq MappingService.
 *
 * Mapping service that delegates core execution to OpenRegister's MappingService
 * when available, falling back to its own implementation.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

/**
 * MappingService.
 *
 * Twig-driven mapping service that transforms inbound payloads against
 * configured Mapping objects + runtime authentication helpers.
 *
 * Post chain-C cutover (services-direct-or-usage) this service consumes the
 * OpenRegister-owned mapping value object directly via
 * {@see \OCA\OpenRegister\Service\ObjectService}: no more references to the
 * legacy `OCA\Integriq\Db\Mapping*` types.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

use Adbar\Dot;
use Exception;
use InvalidArgumentException;
use OCA\Integriq\Twig\MappingExtension;
use OCA\Integriq\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;

/**
 * MappingService — Twig-driven payload transformer.
 *
 * Consumes either a fully hydrated OpenRegister Mapping value object, a raw
 * ObjectEntity, or a plain array describing a mapping configuration. The
 * normalisation helper resolves any of those into an OrMapping internally,
 * preserving the existing per-key Twig + cast pipeline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
class MappingService implements MappingServiceInterface {
	/**
	 * The OpenRegister register slug mappings live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * The OpenRegister schema slug for mapping objects.
	 *
	 * @var string
	 */
	private const SCHEMA = 'mapping';

	/**
	 * Cast prefixes that carry an argument, mapped to their cast name.
	 *
	 * @var array<string, string>
	 */
	private const CAST_ARGUMENT_PREFIXES = [
		'unsetIfValue=='   => 'unsetIfValue',
		'setNullIfValue==' => 'setNullIfValue',
		'countValue:'      => 'countValue',
	];

	/**
	 * Casts that read or change other keys of the data.
	 *
	 * @var string[]
	 */
	private const CONTEXT_CASTS = ['keyCantBeValue', 'unsetIfValue', 'setNullIfValue', 'countValue'];

	/**
	 * Create a private variable to store the twig environment.
	 *
	 * @var Environment
	 */
	private Environment $twig;

	/**
	 * Setting up the base class with required services.
	 *
	 * @param ArrayLoader $loader The ArrayLoader for Twig.
	 * @param CallService $callService Outbound HTTP caller used by the Twig runtime.
	 * @param FileService $fileService The OR-side file lookup helper.
	 * @param ObjectService $objectService The Integriq object service.
	 * @param OrObjectService $orObjectService The OpenRegister object service (chain-C entry point).
	 * @param SynchronizationContractService $synchronizationContractService The synchronization contract service.
	 */
	public function __construct(
		ArrayLoader $loader,
		CallService $callService,
		FileService $fileService,
		ObjectService $objectService,
		private readonly OrObjectService $orObjectService,
		SynchronizationContractService $synchronizationContractService,
	) {
		$this->twig = new Environment($loader);
		$this->twig->addExtension(new MappingExtension());
		$this->twig->addRuntimeLoader(
			new MappingRuntimeLoader(
				mappingService:                 $this,
				callService:                    $callService,
				fileService:                    $fileService,
				objectService:                  $objectService,
				synchronizationContractService: $synchronizationContractService,
			)
		);

	}//end __construct()

	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array $array The array to encode the array keys for.
	 * @param string $toReplace The character to encode.
	 * @param string $replacement The encoded character.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array {
		$result = [];
		foreach ($array as $key => $value) {
			$newKey = str_replace($toReplace, $replacement, $key);

			if (\is_array($value) === true && $value !== []) {
				$result[$newKey] = $this->encodeArrayKeys(array: $value, toReplace: $toReplace, replacement: $replacement);
				continue;
			}

			$result[$newKey] = $value;
		}

		return $result;
	}//end encodeArrayKeys()

	/**
	 * Renders a Twig template string using the mapping Twig environment.
	 *
	 * @param string $template The Twig template to render.
	 * @param array $context The context available inside the template.
	 *
	 * @return string The rendered template result.
	 * @throws LoaderError|SyntaxError Twig exceptions
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function renderTemplateString(string $template, array $context = []): string {
		return html_entity_decode($this->twig->createTemplate($template)->render($context));
	}//end renderTemplateString()

	/**
	 * Normalise the polymorphic $mapping input to a concrete OR Mapping value object.
	 *
	 * Accepts:
	 *  - {@see OrMapping}: returned as-is.
	 *  - {@see ObjectEntity}: hydrates a fresh OrMapping from `getObject()`.
	 *  - array: hydrates a fresh OrMapping from the raw array shape.
	 *  - string|int: resolved through {@see OrObjectService::find()} as an
	 *    `openconnector/mapping` lookup.
	 *
	 * @param OrMapping|ObjectEntity|array|string|int $mapping The polymorphic mapping reference.
	 *
	 * @return OrMapping The normalised value object.
	 *
	 * @throws \InvalidArgumentException When the reference cannot be resolved.
	 */
	private function normaliseMapping(OrMapping|ObjectEntity|array|string|int $mapping): OrMapping {
		if ($mapping instanceof OrMapping) {
			return $mapping;
		}

		if ($mapping instanceof ObjectEntity) {
			return (new OrMapping())->hydrate($mapping->getObject());
		}

		if (is_array($mapping) === true) {
			return (new OrMapping())->hydrate($mapping);
		}

		$object = $this->findMapping(reference: (string)$mapping);
		if ($object === null) {
			throw new InvalidArgumentException(
				sprintf('Mapping "%s" could not be resolved through OpenRegister.', (string)$mapping)
			);
		}

		return (new OrMapping())->hydrate($object->getObject());
	}//end normaliseMapping()

	/**
	 * Resolve a mapping by uuid, id or slug, then by imported identifiers.
	 *
	 * OpenRegister's find() throws DoesNotExistException for an identifier it
	 * cannot resolve. That used to end the lookup before the `slug`/`reference`
	 * fallback could run, so a mapping known only by its imported reference
	 * never resolved (mapping-woo-index-field-mapping D4).
	 *
	 * @param string $reference The uuid, id, slug or reference.
	 *
	 * @return ObjectEntity|null The mapping object, or null when nothing matches.
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function findMapping(string $reference): ?ObjectEntity {
		try {
			$object = $this->orObjectService->find(
				id: $reference,
				register: self::REGISTER,
				schema: self::SCHEMA
			);
		} catch (DoesNotExistException) {
			$object = null;
		}

		if ($object === null) {
			$object = $this->findMappingByIdentifier(identifier: $reference);
		}

		return $object;
	}//end findMapping()

	/**
	 * Find a mapping by imported configuration identifiers.
	 *
	 * @param string $identifier The mapping slug/reference to resolve.
	 *
	 * @return ObjectEntity|null The matching mapping object, when found.
	 */
	private function findMappingByIdentifier(string $identifier): ?ObjectEntity {
		foreach (['slug', 'reference'] as $field) {
			try {
				$matches = $this->orObjectService->findAll(
					config: [
						'filters' => [
							'register' => self::REGISTER,
							'schema' => self::SCHEMA,
							$field => $identifier,
						],
					]
				);
			} catch (DoesNotExistException) {
				continue;
			}

			$results = ($matches['results'] ?? $matches);
			if (empty($results) === false) {
				return $results[0];
			}
		}

		return null;
	}//end findMappingByIdentifier()

	/**
	 * Maps (transforms) an array (input) to a different array (output).
	 *
	 * @param OrMapping|ObjectEntity|array|string|int $mapping The mapping recipe (polymorphic).
	 * @param array $input The array to be mapped.
	 * @param bool $list Whether we want a list instead of a single item.
	 *
	 * @return array The result (output) of the mapping process.
	 *
	 * @throws LoaderError|SyntaxError Twig Exceptions.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) MappingShape is a final set of pure steps on arrays; there is nothing to inject.
	 */
	public function executeMapping(OrMapping|ObjectEntity|array|string|int $mapping, array $input, bool $list = false): array {
		$mapping = $this->normaliseMapping(mapping: $mapping);

		if ($list === true) {
			$list = [];
			foreach (MappingShape::listItems(input: $input) as $key => $item) {
				$list[$key] = $this->executeMapping(mapping: $mapping, input: $item);
			}

			return $list;
		}

		$dotArray = $this->mapToDot(mapping: $mapping, input: $input);
		MappingShape::removeUnsets(mapping: $mapping, dotArray: $dotArray);

		// Cast values to a specific type: a list or a comma-separated string of casts, applied in order.
		foreach (($mapping->getCast() ?? []) as $key => $cast) {
			if ($dotArray->has($key) === false) {
				continue;
			}

			if (is_array($cast) === false) {
				$cast = explode(',', $cast);
			}

			foreach ($cast as $singleCast) {
				$this->handleCast(dotArray: $dotArray, key: $key, cast: $singleCast);
			}
		}

		// Back to array.
		$output = $this->encodeArrayKeys(array: $dotArray->all(), toReplace: '&#46;', replacement: '.');

		return MappingShape::rootLevelOutput(output: $output);
	}//end executeMapping()

	/**
	 * Apply a mapping's key => value pairs to one input.
	 *
	 * Input keys containing a dot are encoded first, so Dot does not read them
	 * as paths. A value that is a path in the input is copied from there, an
	 * array value is set as is, and any other value is rendered as a Twig
	 * template against the original input. With `passThrough` the result
	 * starts from the input instead of empty.
	 *
	 * @param OrMapping $mapping The mapping recipe.
	 * @param array     $input   The input.
	 *
	 * @return Dot The mapped values, keys still encoded.
	 *
	 * @throws Exception When a template fails to render.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	private function mapToDot(OrMapping $mapping, array $input): Dot {
		$encodedInput = $this->encodeArrayKeys(array: $input, toReplace: '.', replacement: '&#46;');
		$dotInput = new Dot($encodedInput);
		$dotArray = new Dot();
		if ($mapping->getPassThrough() === true) {
			$dotArray = new Dot($encodedInput);
		}

		foreach ($mapping->getMapping() as $key => $value) {
			if ($dotInput->has($value) === true) {
				$dotArray->set($key, $dotInput->get($value));
				continue;
			}

			if (is_array($value) === true) {
				$dotArray->set($key, $value);
				continue;
			}

			try {
				$dotArray->set($key, $this->renderTemplateString(template: $value, context: $input));
			} catch (Throwable $e) {
				throw new Exception("Error for mapping: {$mapping->getName()}, key: $key, value: $value and with message thrown: {$e->getMessage()}");
			}
		}

		return $dotArray;
	}//end mapToDot()

	/**
	 * Handles a single cast.
	 *
	 * A cast is either a plain name (`int`) or a name with an argument
	 * (`unsetIfValue==x`, `setNullIfValue==x`, `countValue:path`).
	 *
	 * @param Dot $dotArray The dotArray of the array we are mapping.
	 * @param string $key The key of the field we want to cast.
	 * @param string $cast The type of cast we want to do.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function handleCast(Dot $dotArray, string $key, string $cast): void {
		[$castName, $argument] = $this->splitCastArgument(cast: $cast);

		if (in_array($castName, self::CONTEXT_CASTS, true) === true) {
			$value = $this->applyContextCast(dotArray: $dotArray, key: $key, cast: $castName, argument: $argument);
		} else {
			$value = $this->applyValueCast(value: $dotArray->get($key), cast: $castName);
		}

		// Don't reset key that was deleted on purpose.
		if ($dotArray->has($key) === true) {
			$dotArray->set($key, $value);
		}
	}//end handleCast()

	/**
	 * Splits a cast into its name and its optional argument.
	 *
	 * @param string $cast The cast as configured on the mapping.
	 *
	 * @return array{0: string, 1: string|null} The cast name and its argument.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function splitCastArgument(string $cast): array {
		foreach (self::CAST_ARGUMENT_PREFIXES as $prefix => $castName) {
			if (str_starts_with($cast, $prefix) === true) {
				return [$castName, substr($cast, strlen($prefix))];
			}
		}

		return [$cast, null];
	}//end splitCastArgument()

	/**
	 * Applies a cast that only depends on the value itself.
	 *
	 * Unknown casts leave the value unchanged.
	 *
	 * @param mixed $value The value to cast.
	 * @param string $cast The cast name.
	 *
	 * @return mixed The cast value.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function applyValueCast(mixed $value, string $cast): mixed {
		$casts = $this->getValueCasts();
		if (isset($casts[$cast]) === false) {
			return $value;
		}

		return $casts[$cast]($value);
	}//end applyValueCast()

	/**
	 * Returns the casts that only depend on the value, keyed by cast name.
	 *
	 * @return array<string, callable(mixed): mixed>
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function getValueCasts(): array {
		return array_merge($this->getTypeCasts(), $this->getEncodingCasts());
	}//end getValueCasts()

	/**
	 * Returns the casts that convert a value to another type, keyed by cast name.
	 *
	 * @return array<string, callable(mixed): mixed>
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function getTypeCasts(): array {
		$toBool = fn ($value) => $this->castToBool(value: $value);
		$toNullableBool = fn ($value) => $this->castToNullableBool(value: $value);

		return [
			'string'                  => fn ($value) => (string) $value,
			'bool'                    => $toBool,
			'boolean'                 => $toBool,
			'?bool'                   => $toNullableBool,
			'?boolean'                => $toNullableBool,
			'int'                     => fn ($value) => (int) $value,
			'integer'                 => fn ($value) => (int) $value,
			'float'                   => fn ($value) => (float) $value,
			'array'                   => fn ($value) => (array) $value,
			'jsonToArray'             => fn ($value) => $this->castJsonToArray(value: $value),
			'nullStringToNull'        => fn ($value) => $this->castNullStringToNull(value: $value),
			'coordinateStringToArray' => fn ($value) => $this->coordinateStringToArray(coordinates: $value),
			'moneyStringToInt'        => fn ($value) => (int) str_replace(',', '', str_replace('.', '', $value)),
			'intToMoneyString'        => fn ($value) => number_format(($value / 100), 2, ',', '.'),
		];
	}//end getTypeCasts()

	/**
	 * Returns the casts that encode or decode a string, keyed by cast name.
	 *
	 * @return array<string, callable(mixed): mixed>
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function getEncodingCasts(): array {
		return [
			'date'                    => date(...),
			'url'                     => urlencode(...),
			'urlDecode'               => urldecode(...),
			'rawurl'                  => rawurlencode(...),
			'rawurlDecode'            => rawurldecode(...),
			'html'                    => htmlentities(...),
			'htmlDecode'              => html_entity_decode(...),
			'base64'                  => base64_encode(...),
			'base64Decode'            => base64_decode(...),
			'json'                    => json_encode(...),
			'utf8'                    => fn ($value) => $this->castToAscii(value: $value),
		];
	}//end getEncodingCasts()

	/**
	 * Casts a value to a boolean: 1, "true" and "yes" (any case) are true.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return bool The boolean value.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function castToBool(mixed $value): bool {
		return (int) $value === 1 || strtolower($value) === 'true' || strtolower($value) === 'yes';
	}//end castToBool()

	/**
	 * Casts a value to a boolean, keeping null as null.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return bool|null The boolean value, or null.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function castToNullableBool(mixed $value): ?bool {
		if ($value === null) {
			return null;
		}

		return $this->castToBool(value: $value);
	}//end castToNullableBool()

	/**
	 * Turns the string "null" into null; other values pass through.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return mixed The cast value.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function castNullStringToNull(mixed $value): mixed {
		if ($value === 'null') {
			return null;
		}

		return $value;
	}//end castNullStringToNull()

	/**
	 * Decodes an HTML-encoded JSON string to an array; arrays pass through.
	 *
	 * @param mixed $value The value to decode.
	 *
	 * @return mixed The decoded value.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function castJsonToArray(mixed $value): mixed {
		if (is_array($value) === true) {
			return $value;
		}

		return json_decode(html_entity_decode($value), true);
	}//end castJsonToArray()

	/**
	 * Transliterates a UTF-8 string to ASCII.
	 *
	 * See https://www.php.net/manual/en/function.iconv.php for details.
	 *
	 * @param mixed $value The value to transliterate.
	 *
	 * @return string|false The ASCII string, or false on failure.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function castToAscii(mixed $value): string|false {
		setlocale(LC_CTYPE, 'cs_CZ');

		return iconv('UTF-8', 'ASCII//TRANSLIT', $value);
	}//end castToAscii()

	/**
	 * Applies a cast that reads or changes other keys of the data.
	 *
	 * @param Dot $dotArray The data being mapped.
	 * @param string $key The key of the field to cast.
	 * @param string $cast The cast name, one of CONTEXT_CASTS.
	 * @param string|null $argument The cast argument, if any.
	 *
	 * @return mixed The value to write back to the key.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function applyContextCast(Dot $dotArray, string $key, string $cast, ?string $argument): mixed {
		$value = $dotArray->get($key);

		if ($cast === 'countValue') {
			return $this->countReferencedValue(dotArray: $dotArray, path: $argument, value: $value);
		}

		$matches = $this->valueMatchesCastArgument(value: $value, argument: $argument);
		if ($cast === 'keyCantBeValue') {
			$matches = $key === $value;
		}

		if ($matches === false) {
			return $value;
		}

		if ($cast === 'setNullIfValue') {
			return null;
		}

		$dotArray->delete($key);

		return $value;
	}//end applyContextCast()

	/**
	 * Checks whether a value matches a cast argument.
	 *
	 * An empty argument matches empty values and arrays whose keys are all null.
	 *
	 * @param mixed $value The value to check.
	 * @param string|null $argument The cast argument.
	 *
	 * @return bool True when the value matches.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function valueMatchesCastArgument(mixed $value, ?string $argument): bool {
		if ($argument === null) {
			return false;
		}

		if ($value === $argument) {
			return true;
		}

		if ($argument !== '') {
			return false;
		}

		return empty($value) === true
			|| (is_array($value) === true && $this->areAllArrayKeysNull(array: $value) === true);
	}//end valueMatchesCastArgument()

	/**
	 * Counts the countable value at another path, or keeps the value.
	 *
	 * @param Dot $dotArray The data being mapped.
	 * @param string|null $path The path of the value to count.
	 * @param mixed $value The current value.
	 *
	 * @return mixed The count, or the current value when the path is unusable.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function countReferencedValue(Dot $dotArray, ?string $path, mixed $value): mixed {
		if (empty($path) === true
			|| $dotArray->has($path) === false
			|| is_countable($dotArray->get($path)) === false
		) {
			return $value;
		}

		return count($dotArray->get($path));
	}//end countReferencedValue()

	/**
	 * Checks if all keys in multi-dimensional array are null.
	 *
	 * @param array $array Array to check.
	 *
	 * @return bool True if array keys are null else false.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function areAllArrayKeysNull(array $array): bool {
		if (empty($array) === true) {
			return true;
		}

		foreach ($array as $value) {
			if (is_array($value) === true) {
				if ($this->areAllArrayKeysNull(array: $value) === false) {
					return false;
				}
			} elseif (empty($value) === false) {
				return false;
			}
		}

		return true;
	}//end areAllArrayKeysNull()

	/**
	 * Converts a coordinate string to an array of coordinates.
	 *
	 * @param string $coordinates A string containing coordinates.
	 *
	 * @return array An array of coordinates.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function coordinateStringToArray(string $coordinates): array {
		$halves = explode(' ', $coordinates);
		$point = [];
		$coordinateArray = [];
		foreach ($halves as $half) {
			if (count($point) > 1) {
				$coordinateArray[] = $point;
				$point = [];
			}

			$point[] = $half;
		}//end foreach

		$coordinateArray[] = $point;

		if (count($coordinateArray) === 1) {
			$coordinateArray = $coordinateArray[0];
		}

		return $coordinateArray;
	}//end coordinateStringToArray()

	/**
	 * Retrieves a single mapping by its ID/UUID/slug.
	 *
	 * Post chain-C cutover this routes directly through OpenRegister's ObjectService.
	 * The returned OR Mapping value object exposes the same methods the previous
	 * `OCA\Integriq\Db\Mapping` shape did (`getMapping`, `getPassThrough`, ...).
	 *
	 * @param string $mappingId The unique identifier of the mapping to retrieve.
	 *
	 * @return OrMapping The hydrated mapping value object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If mapping is not found.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function getMapping(string $mappingId): OrMapping {
		$object = $this->orObjectService->find(
			id: $mappingId,
			register: self::REGISTER,
			schema: self::SCHEMA
		);

		if ($object === null) {
			throw new DoesNotExistException(
				sprintf('Mapping "%s" does not exist.', $mappingId)
			);
		}

		return (new OrMapping())->hydrate($object->getObject());
	}//end getMapping()

	/**
	 * Retrieves all available mappings.
	 *
	 * Routes directly through OpenRegister's ObjectService.
	 *
	 * @return array<OrMapping> An array containing all hydrated mapping value objects.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function getMappings(): array {
		$results = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
				],
			]
		);

		$rows = ($results['results'] ?? $results);

		return array_map(
			function ($object): OrMapping {
				if ($object instanceof ObjectEntity) {
					$objectData = $object->getObject();
				} else {
					$objectData = (array)$object;
				}

				return (new OrMapping())->hydrate($objectData);
			},
			$rows
		);

	}//end getMappings()

	/**
	 * VNG double-underscore lookup operators supported by {@see translateVngFilterOperators()}.
	 *
	 * @var array<int, string>
	 */
	private const VNG_FILTER_OPERATORS = ['icontains', 'gte', 'lte', 'gt', 'lt', 'in'];

	/**
	 * Translate VNG list-filter query semantics onto OpenRegister search filters.
	 *
	 * Dialect-agnostic search-compiler mechanic (REQ-006). Double-underscore
	 * lookup keys (`field__icontains`, `field__gte`, ...) are translated to a
	 * nested `[field => [operator => value]]` OpenRegister filter fragment; a
	 * bare key with no `__` suffix is passed through as an equality filter.
	 * The VNG `partijIdentificator__codeSoortObjectId` /
	 * `partijIdentificator__objectId` pair is folded into a single identity
	 * filter via {@see translatePartijIdentificatorFilter()} so a `bsn`-typed
	 * lookup always resolves against the stored hash, never a raw value.
	 *
	 * @param array<string, mixed> $filters Raw request filters (already query-string-parsed).
	 *
	 * @return array<string, mixed> OpenRegister-shaped search filters.
	 *
	 * @throws Exception When a lookup key uses an operator this compiler does not recognise.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function translateVngFilterOperators(array $filters): array {
		$codeKindByField = [];
		foreach ($filters as $key => $value) {
			if (str_ends_with($key, '__codeSoortObjectId') === true) {
				$codeKindByField[substr($key, 0, -strlen('__codeSoortObjectId'))] = $value;
			}
		}

		$translated = [];

		foreach ($filters as $key => $value) {
			if (str_contains($key, '__') === false) {
				$translated[$key] = $value;
				continue;
			}

			[$field, $operator] = explode('__', $key, 2);

			if ($operator === 'codeSoortObjectId') {
				// Folded into the paired *__objectId filter below; emit standalone
				// only when there is no paired identity-value filter to fold with.
				if (isset($filters[$field . '__objectId']) === false) {
					$translated[$field]['codeSoortObjectId'] = $value;
				}

				continue;
			}

			if ($operator === 'objectId' && isset($codeKindByField[$field]) === true) {
				$translated[$field] = $this->translatePartyIdentifierFilter(
					codeKind: (string)$codeKindByField[$field],
					objectId: (string)$value
				);
				continue;
			}

			if (in_array($operator, self::VNG_FILTER_OPERATORS, true) === false) {
				throw new Exception(sprintf('Unsupported VNG filter operator "%s" on field "%s".', $operator, $field));
			}

			$translated[$field][$operator] = $value;
		}//end foreach

		return $translated;
	}//end translateVngFilterOperators()

	/**
	 * Fold a `partijIdentificator` codeSoort + objectId pair into one identity filter.
	 *
	 * When the identity type is `bsn`, the supplied objectId (expected to be a
	 * raw BSN from the caller's perspective) is SHA-256-hashed before being
	 * placed in the filter, matching the AVG BSN policy's storage shape (only
	 * the hash is ever persisted — see {@see \OCA\Integriq\Rule\AvgBsnPolicyRule}).
	 * Non-BSN identity types pass through unchanged.
	 *
	 * @param string $codeKind The VNG identity type code (e.g. `bsn`, `rsin`, `vestigingsnummer`).
	 * @param string $objectId The identity value as supplied by the caller.
	 *
	 * @return array{codeSoortObjectId: string, objectId: string} The folded identity filter.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function translatePartyIdentifierFilter(string $codeKind, string $objectId): array {
		if (strtolower($codeKind) !== 'bsn') {
			return ['codeSoortObjectId' => $codeKind, 'objectId' => $objectId];
		}

		return [
			'codeSoortObjectId' => $codeKind,
			'objectId' => hash('sha256', $objectId),
		];
	}//end translatePartyIdentifierFilter()

	/**
	 * Embed named relations inline (VNG `expand=` semantics), bounded by depth.
	 *
	 * Dialect-agnostic search-compiler mechanic (REQ-007). Each entry in
	 * `$expand` is a dot-path (e.g. `digitaleAdressen` or
	 * `betrokkene.digitaleAdressen`); the first segment names a key already
	 * present on `$data` (holding a UUID, or a list of UUIDs) which is
	 * resolved to the full related object via OpenRegister. Nesting deeper
	 * than `$maxDepth` stops expanding and the response documents the
	 * truncation under `_truncatedExpand` rather than fanning out
	 * unboundedly.
	 *
	 * @param array<string, mixed> $data The result (single item) to expand relations on.
	 * @param array<int, string> $expand Dot-path relation keys requested via `expand=`.
	 * @param int $maxDepth Maximum expansion depth (default per orchestrator ruling: 2).
	 *
	 * @return array<string, mixed> $data with the requested relations embedded inline.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function expandRelations(array $data, array $expand, int $maxDepth = 2): array {
		if ($expand === []) {
			return $data;
		}

		if ($maxDepth <= 0) {
			$data['_truncatedExpand'] = $expand;
			return $data;
		}

		foreach ($expand as $expandPath) {
			$segments = explode('.', $expandPath, 2);
			$key = $segments[0];
			$nested = $segments[1] ?? null;

			if (isset($data[$key]) === false) {
				continue;
			}

			$data[$key] = $this->resolveExpandValue(value: $data[$key], nestedExpand: $nested, depthRemaining: $maxDepth);
		}

		return $data;
	}//end expandRelations()

	/**
	 * Resolve a single expand target (a UUID, or a list of UUIDs) to the full related object(s).
	 *
	 * @param mixed $value The raw relation value (UUID string, list of UUIDs, or already-embedded data).
	 * @param string|null $nestedExpand Remaining dot-path to expand on the resolved object, if any.
	 * @param int $depthRemaining Expansion levels left, including this one.
	 *
	 * @return mixed The resolved object (or list of objects); the original value when it cannot be resolved.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function resolveExpandValue(mixed $value, ?string $nestedExpand, int $depthRemaining): mixed {
		if (is_array($value) === true && array_is_list($value) === true) {
			return array_map(
				fn ($item) => $this->resolveExpandValue(value: $item, nestedExpand: $nestedExpand, depthRemaining: $depthRemaining),
				$value
			);
		}

		if (is_string($value) === false || $value === '') {
			return $value;
		}

		$resolved = $this->orObjectService->find(id: $value);
		if ($resolved === null) {
			return $value;
		}

		$resolvedArray = (array)$resolved;
		if ($resolved instanceof ObjectEntity) {
			$resolvedArray = $resolved->getObject();
		}

		if ($nestedExpand === null) {
			return $resolvedArray;
		}

		if ($depthRemaining > 1) {
			return $this->expandRelations(data: $resolvedArray, expand: [$nestedExpand], maxDepth: ($depthRemaining - 1));
		}

		$resolvedArray['_truncatedExpand'] = [$nestedExpand];
		return $resolvedArray;
	}//end resolveExpandValue()
}//end class
