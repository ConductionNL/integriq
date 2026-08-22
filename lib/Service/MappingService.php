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
class MappingService {
	/**
	 * The OpenRegister register slug mappings live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'openconnector';

	/**
	 * The OpenRegister schema slug for mapping objects.
	 *
	 * @var string
	 */
	private const SCHEMA = 'mapping';

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

		// String/int -> resolve via OpenRegister UUID first, then imported
		// configuration identifiers (`slug`/`reference`).
		try {
			$object = $this->orObjectService->find(
				id: (string)$mapping,
				register: self::REGISTER,
				schema: self::SCHEMA
			);
		} catch (DoesNotExistException $e) {
			throw new InvalidArgumentException(
				sprintf('Mapping "%s" could not be resolved through OpenRegister.', (string)$mapping),
				0,
				$e
			);
		}

		if ($object === null) {
			$object = $this->findMappingByIdentifier(identifier: (string)$mapping);
		}

		if ($object === null) {
			throw new InvalidArgumentException(
				sprintf('Mapping "%s" could not be resolved through OpenRegister.', (string)$mapping)
			);
		}

		return (new OrMapping())->hydrate($object->getObject());
	}//end normaliseMapping()

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
	 */
	public function executeMapping(OrMapping|ObjectEntity|array|string|int $mapping, array $input, bool $list = false): array {
		$mapping = $this->normaliseMapping(mapping: $mapping);

		// Check for list.
		if ($list === true) {
			$list = [];
			$extraValues = [];

			// Allow extra(input)values to be passed down for mapping while dealing with a list.
			if (array_key_exists('listInput', $input) === true) {
				$extraValues = $input;
				$input = $input['listInput'];
				unset($extraValues['listInput'], $extraValues['value']);
			}

			foreach ($input as $key => $value) {
				// Mapping function expects an array for $input, make sure we always pass an array to this function.
				if (is_array($value) === false || empty($extraValues) === false) {
					// Todo: we want to remove ['value' => $value] from this at some point, for now required for DOWR to work.
					$value = array_merge((array)$value, ['value' => $value], $extraValues);
				}

				$list[$key] = $this->executeMapping(mapping: $mapping, input: $value);
			}

			return $list;
		}//end if

		$originalInput = $input;
		$input = $this->encodeArrayKeys(array: $input, toReplace: '.', replacement: '&#46;');

		// Determine pass through.
		// Let's get the dot array based on https://github.com/adbario/php-dot-notation.
		if ($mapping->getPassThrough() === true) {
			$dotArray = new Dot($input);
		} else {
			$dotArray = new Dot();
		}

		$dotInput = new Dot($input);

		// Let's do the actual mapping.
		foreach ($mapping->getMapping() as $key => $value) {
			// If the value exists in the input dot take it from there.
			if ($dotInput->has($value) === true) {
				$dotArray->set($key, $dotInput->get($value));
				continue;
			}

			// Render the value from twig.
			if (is_array($value) === true) {
				$dotArray->set($key, $value);
				continue;
			}

			try {
				$dotArray->set($key, $this->renderTemplateString(template: $value, context: $originalInput));
			} catch (Throwable $e) {
				throw new Exception("Error for mapping: {$mapping->getName()}, key: $key, value: $value and with message thrown: {$e->getMessage()}");
			}
		}

		// Unset unwanted key's.
		$unsets = ($mapping->getUnset() ?? []);
		foreach ($unsets as $unset) {
			if ($dotArray->has($unset) === false) {
				continue;
			}

			$dotArray->delete($unset);
		}

		// Cast values to a specific type.
		$casts = ($mapping->getCast() ?? []);

		foreach ($casts as $key => $cast) {
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
		$output = $dotArray->all();
		$output = $this->encodeArrayKeys(array: $output, toReplace: '&#46;', replacement: '.');

		// If something has been defined to work on root level (i.e. the object lives on root level), we can use # to define writing the root object.
		$keys = array_keys($output);
		if (count($keys) === 1 && $keys[0] === '#') {
			// Ensure we always return an array, even if the value is null.
			$rootValue = $output['#'];
			if ($rootValue === null) {
				$output = [];
			} elseif (is_array($rootValue) === true) {
				$output = $rootValue;
			} else {
				$output = [$rootValue];
			}
		}

		// Defensive coercion: prior branches set $output from $dotArray->all() (always array)
		// or from the # root-level extraction (always array). The is_array() guard is dead
		// per static analysis but kept for safety against future refactors.
		if (is_array($output) === false) {
			$output = [];
		}

		return $output;
	}//end executeMapping()

	/**
	 * Handles a single cast.
	 *
	 * @param Dot $dotArray The dotArray of the array we are mapping.
	 * @param string $key The key of the field we want to cast.
	 * @param string $cast The type of cast we want to do.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function handleCast(Dot $dotArray, string $key, string $cast) {
		$value = $dotArray->get($key);
		$unsetIfValue = null;
		$setNullIfValue = null;
		$countValue = null;

		if (str_starts_with($cast, 'unsetIfValue==') === true) {
			$unsetIfValue = substr($cast, 14);
			$cast = 'unsetIfValue';
		} elseif (str_starts_with($cast, 'setNullIfValue==') === true) {
			$setNullIfValue = substr($cast, 16);
			$cast = 'setNullIfValue';
		} elseif (str_starts_with($cast, 'countValue:') === true) {
			$countValue = substr($cast, 11);
			$cast = 'countValue';
		}

		// Todo: Add more casts.
		switch ($cast) {
			case 'string':
				$value = (string)$value;
				break;
			case 'bool':
			case 'boolean':
				if ((int)$value === 1 || strtolower($value) === 'true' || strtolower($value) === 'yes') {
					$value = true;
					break;
				}

				$value = false;
				break;
			case '?bool':
			case '?boolean':
				if ($value === null) {
					break;
				}

				if ((int)$value === 1 || strtolower($value) === 'true' || strtolower($value) === 'yes') {
					$value = true;
					break;
				}

				$value = false;

				break;
			case 'int':
			case 'integer':
				$value = (int)$value;
				break;
			case 'float':
				$value = (float)$value;
				break;
			case 'array':
				$value = (array)$value;
				break;
			case 'date':
				$value = date($value);
				break;
			case 'url':
				$value = urlencode($value);
				break;
			case 'urlDecode':
				$value = urldecode($value);
				break;
			case 'rawurl':
				$value = rawurlencode($value);
				break;
			case 'rawurlDecode':
				$value = rawurldecode($value);
				break;
			case 'html':
				$value = htmlentities($value);
				break;
			case 'htmlDecode':
				$value = html_entity_decode($value);
				break;
			case 'base64':
				$value = base64_encode($value);
				break;
			case 'base64Decode':
				$value = base64_decode($value);
				break;
			case 'json':
				$value = json_encode($value);
				break;
			case 'jsonToArray':
				if (is_array($value) === true) {
					break;
				}

				$value = html_entity_decode($value);
				$value = json_decode($value, true);
				break;
			case 'utf8':
				// See https://www.php.net/manual/en/function.iconv.php for details.
				setlocale(LC_CTYPE, 'cs_CZ');
				$value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
				break;
			case 'nullStringToNull':
				if ($value === 'null') {
					$value = null;
				}
				break;
			case 'coordinateStringToArray':
				$value = $this->coordinateStringToArray(coordinates: $value);
				break;
			case 'keyCantBeValue':
				if ($key === $value) {
					$dotArray->delete($key);
				}
				break;
			case 'unsetIfValue':
				if (isset($unsetIfValue) === true
					&& $value === $unsetIfValue
					|| ($unsetIfValue === '' && empty($value) === true)
					|| ($unsetIfValue === '' && $value === null)
				) {
					$dotArray->delete($key);
				}

				if ($unsetIfValue === '' && is_array($value) === true && $this->areAllArrayKeysNull(array: $value) === true) {
					$dotArray->delete($key);
				}
				break;
			case 'setNullIfValue':
				if (isset($setNullIfValue) === true
					&& $value === $setNullIfValue
					|| ($setNullIfValue === '' && empty($value) === true)
					|| ($setNullIfValue === '' && $value === null)
				) {
					$value = null;
				}

				if ($setNullIfValue === '' && is_array($value) === true && $this->areAllArrayKeysNull(array: $value) === true) {
					$value = null;
				}
				break;
			case 'countValue':
				if (isset($countValue) === true
					&& empty($countValue) === false
					&& $dotArray->has($countValue) === true
					&& is_countable($dotArray->get($countValue)) === true
				) {
					$value = count($dotArray->get($countValue));
				}
				break;
			case 'moneyStringToInt':
				$value = str_replace('.', '', $value);
				$value = (int)str_replace(',', '', $value);
				break;
			case 'intToMoneyString':
				$value = ($value / 100);
				$value = number_format($value, 2, ',', '.');
				break;
			default:
				// @todo: error handling
				break;
		}//end switch

		// Don't reset key that was deleted on purpose.
		if ($dotArray->has($key) === true) {
			$dotArray->set($key, $value);
		}

	}//end handleCast()

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
