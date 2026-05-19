<?php

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Twig\AuthenticationExtension;
use OCA\OpenConnector\Twig\AuthenticationRuntimeLoader;
use OCA\OpenConnector\Twig\MappingExtension;
use OCA\OpenConnector\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Service\FileService;
use Adbar\Dot;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Throwable;
use Exception;

/**
 * Mapping service that delegates core execution to OpenRegister's MappingService
 * when available, falling back to its own implementation.
 *
 * @deprecated The mapping engine has moved to OpenRegister. This service delegates
 *             to OCA\OpenRegister\Service\MappingService for executeMapping().
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Mapping execution requires comprehensive handling
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)      $list parameter clearly indicates list processing mode
 */
class MappingService
{
    /**
     * Create a private variable to store the twig environment.
     *
     * @var Environment
     */
    private Environment $twig;

    /**
     * The OpenRegister mapping service (if available).
     *
     * @var \OCA\OpenRegister\Service\MappingService|null
     */
    private $openRegisterMappingService = null;

	/**
	 * Setting up the base class with required services.
	 *
	 * @param ArrayLoader   $loader		   The ArrayLoader for Twig.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param CallService   $callService   The call service.
	 * @param SourceMapper  $sourceMapper  The source mapper.
	 * @param FileService   $fileService   The file service.
	 * @param ObjectService $objectService The object service.
	 */
    public function __construct(
		ArrayLoader $loader,
		private readonly MappingMapper $mappingMapper,
        CallService $callService,
        SourceMapper $sourceMapper,
        FileService $fileService,
        ObjectService $objectService,
    ) {
        $this->twig = new Environment($loader);
		$this->twig->addExtension(new MappingExtension());
		$this->twig->addRuntimeLoader(new MappingRuntimeLoader(mappingService: $this, mappingMapper: $this->mappingMapper, callService: $callService, sourceMapper: $sourceMapper, fileService: $fileService, objectService: $objectService->getOpenRegisters()));

        // Try to load OpenRegister's MappingService for delegation.
        try {
            $container = \OC::$server;
            $this->openRegisterMappingService = $container->get(
                \OCA\OpenRegister\Service\MappingService::class
            );
        } catch (\Throwable $e) {
            // OpenRegister not available, falling back to local implementation.
            $this->openRegisterMappingService = null;
            \OC::$server->getLogger()->info(
                'OpenConnector MappingService: OpenRegister not available, using local implementation',
                ['app' => 'openconnector']
            );
        }
    }//end __construct()

    /**
     * Replaces strings in array keys, helpful for characters like . in array keys.
     *
     * @param array  $array       The array to encode the array keys for.
     * @param string $toReplace   The character to encode.
     * @param string $replacement The encoded character.
     *
     * @return array The array with encoded array keys
     */
    public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = str_replace($toReplace, $replacement, $key);

            if (\is_array($value) === true && $value !== []) {
                $result[$newKey] = $this->encodeArrayKeys($value, $toReplace, $replacement);
                continue;
            }

            $result[$newKey] = $value;
        }

        return $result;

    }//end encodeArrayKeys()

    /**
     * Maps (transforms) an array (input) to a different array (output).
     *
     * Delegates to OpenRegister's MappingService when available, otherwise
     * uses the local implementation.
     *
     * @param Mapping $mapping The mapping object that forms the recipe for the mapping
     * @param array   $input   The array that need to be mapped (transformed) otherwise known as input
     * @param bool    $list    Whether we want a list instead of a single item
     *
     * @return array The result (output) of the mapping process
     *@throws LoaderError|SyntaxError Twig Exceptions
     *
     */
    public function executeMapping(Mapping $mapping, array $input, bool $list = false): array
    {
        // Delegate to OpenRegister's MappingService if available.
        if ($this->openRegisterMappingService !== null) {
            $orMapping = new \OCA\OpenRegister\Db\Mapping();
            $orMapping->hydrate([
                'name'        => $mapping->getName(),
                'mapping'     => $mapping->getMapping(),
                'unset'       => ($mapping->getUnset() ?? []),
                'cast'        => ($mapping->getCast() ?? []),
                'passThrough' => $mapping->getPassThrough(),
            ]);

            return $this->openRegisterMappingService->executeMapping(
                mapping: $orMapping,
                input: $input,
                list: $list
            );
        }

        return $this->executeMappingLocal($mapping, $input, $list);
    }//end executeMapping()

    /**
     * Local mapping execution (fallback when OpenRegister is not available).
     *
     * @param Mapping $mapping The mapping object
     * @param array   $input   The input array
     * @param bool    $list    Whether to process as list
     *
     * @return array The mapped output
     *
     * @throws Exception When mapping fails
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function executeMappingLocal(Mapping $mapping, array $input, bool $list = false): array
    {
        // Check for list
        if ($list === true) {
            $list        = [];
            $extraValues = [];

            // Allow extra(input)values to be passed down for mapping while dealing with a list.
            if (array_key_exists('listInput', $input) === true) {
                $extraValues = $input;
                $input       = $input['listInput'];
                unset($extraValues['listInput'], $extraValues['value']);
            }

            foreach ($input as $key => $value) {
                // Mapping function expects an array for $input, make sure we always pass an array to this function.
                if (is_array($value) === false || empty($extraValues) === false) {
                    // todo: we want to remove ['value' => $value] from this at some point, for now required for DOWR to work
                    $value = array_merge((array) $value, ['value' => $value], $extraValues);
                }

                $list[$key] = $this->executeMapping($mapping, $value);
            }

            return $list;
        }//end if

        $originalInput = $input;
        $input = $this->encodeArrayKeys($input, '.', '&#46;');

        // Determine pass through.
        // Let's get the dot array based on https://github.com/adbario/php-dot-notation.
        if ($mapping->getPassThrough()) {
            $dotArray = new Dot($input);
        } else {
            $dotArray = new Dot();
        }
        $dotInput = new Dot($input);

        // Let's do the actual mapping.
        foreach ($mapping->getMapping() as $key => $value) {
            // If the value exists in the input dot take it from there.
            if ($dotInput->has($value)) {
                $dotArray->set($key, $dotInput->get($value));
                continue;
            }

            // Render the value from twig.
            if (is_array($value) === true) {
                $dotArray->set($key, $value);
                continue;
            }

            try {
			    $dotArray->set($key, html_entity_decode($this->twig->createTemplate($value)->render($originalInput)));
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

            if ($cast === false) {
                continue;
            }

            foreach ($cast as $singleCast) {
                $this->handleCast($dotArray, $key, $singleCast);
            }
        }

        // Back to array.
        $output = $dotArray->all();

        $output = $this->encodeArrayKeys($output, '&#46;', '.');

        // If something has been defined to work on root level (i.e. the object lives on root level), we can use # to define writing the root object.
        $keys = array_keys($output);
        if (count($keys) === 1 && $keys[0] === '#') {
            // Ensure we always return an array, even if the value is null
            $rootValue = $output['#'];
            $output = is_array($rootValue) ? $rootValue : [$rootValue];
            if ($rootValue === null) {
                $output = [];
            }
        }

        // Ensure output is always an array
        if (is_array($output) === false) {
            $output = $output === null ? [] : [$output];
        }

        return $output;

    }//end executeMappingLocal()

    /**
     * Handles a single cast.
     *
     * @param Dot    $dotArray The dotArray of the array we are mapping.
     * @param string $key      The key of the field we want to cast.
     * @param string $cast     The type of cast we want to do.
     *
     * @return void
     */
    private function handleCast(Dot $dotArray, string $key, string $cast)
    {
        $value = $dotArray->get($key);

        if (str_starts_with($cast, 'unsetIfValue==') === true) {
            $unsetIfValue = substr($cast, 14);
            $cast         = 'unsetIfValue';
        }

        if (str_starts_with($cast, 'setNullIfValue==') === true) {
            $setNullIfValue = substr($cast, 16);
            $cast           = 'setNullIfValue';
        }

        if (str_starts_with($cast, 'countValue:') === true) {
            $countValue = substr($cast, 11);
            $cast       = 'countValue';
        }

        // Todo: Add more casts.
        switch ($cast) {
        case 'string':
            $value = (string) $value;
            break;
        case 'bool':
        case 'boolean':
            if ((int) $value === 1 || strtolower($value) === 'true' || strtolower($value) === 'yes') {
                $value = true;
                break;
            }

            $value = false;
            break;
		case '?bool':
		case '?boolean':
			if($value === null) {
				break;
			}
			if ((int) $value === 1 || strtolower($value) === 'true' || strtolower($value) === 'yes') {
				$value = true;
				break;
			}

			$value = false;

			break;
        case 'int':
        case 'integer':
            $value = (int) $value;
            break;
        case 'float':
            $value = (float) $value;
            break;
        case 'array':
            $value = (array) $value;
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
            // https://www.php.net/manual/en/function.iconv.php
            setlocale(LC_CTYPE, 'cs_CZ');
            $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
            break;
        case 'nullStringToNull':
            if ($value === 'null') {
                $value = null;
            }
            break;
        case 'coordinateStringToArray':
            $value = $this->coordinateStringToArray($value);
            break;
        case 'keyCantBeValue':
            if ($key == $value) {
                $dotArray->delete($key);
            }
            break;
        case 'unsetIfValue':
            if (isset($unsetIfValue) === true
                && $value == $unsetIfValue
                || ($unsetIfValue === '' && empty($value))
                || ($unsetIfValue === '' && $value === null)
            ) {
                $dotArray->delete($key);
            }

            if ($unsetIfValue === '' && is_array($value) === true && $this->areAllArrayKeysNull($value) === true) {
                $dotArray->delete($key);
            }
            break;
        case 'setNullIfValue':
            if (isset($setNullIfValue) === true
                && $value == $setNullIfValue
                || ($setNullIfValue === '' && empty($value))
                || ($setNullIfValue === '' && $value === null)
            ) {
                $value = null;
            }

            if ($setNullIfValue === '' && is_array($value) === true && $this->areAllArrayKeysNull($value) === true) {
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
            $value = (int) str_replace(',', '', $value);
            break;
        case 'intToMoneyString':
            $value = ($value / 100);
            $value = number_format($value, 2, ',', '.');
            break;
        default:
            break;
        }//end switch

        // Don't reset key that was deleted on purpose.
        if ($dotArray->has($key)) {
            $dotArray->set($key, $value);
        }

    }//end handleCast()

    /**
     * Checks if all keys in multi-dimensional array are null.
     *
     * @param array $array Array to check.
     *
     * @return bool True if array keys are null else false.
     */
    private function areAllArrayKeysNull(array $array): bool
    {
        if (empty($array) === true) {
            return true;
        }

        foreach ($array as $value) {
            if (is_array($value) === true) {
                if ($this->areAllArrayKeysNull($value) === false) {
                    return false;
                }
                continue;
            }

            if (empty($value) === false) {
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
     */
    public function coordinateStringToArray(string $coordinates): array
    {
        $halves          = explode(' ', $coordinates);
        $point           = [];
        $coordinateArray = [];
        foreach ($halves as $half) {
            if (count($point) > 1) {
                $coordinateArray[] = $point;
                $point             = [];
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
     * Retrieves a single mapping by its ID.
     *
     * This is a wrapper function that provides controlled access to the mapping mapper.
     * We use this wrapper pattern to ensure other Nextcloud apps can only interact with
     * mappings through this service layer, rather than accessing the mapper directly.
     * This maintains proper encapsulation and separation of concerns.
     *
     * @param string $mappingId The unique identifier of the mapping to retrieve
     * @return Mapping The requested mapping entity
     * @throws \OCP\AppFramework\Db\DoesNotExistException If mapping is not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple mappings found
     */
    public function getMapping(string $mappingId): Mapping
    {
        // Forward the find request to the mapper while maintaining encapsulation
        return $this->mappingMapper->find($mappingId);
    }

    /**
     * Retrieves all available mappings.
     *
     * This is a wrapper function that provides controlled access to the mapping mapper.
     * We use this wrapper pattern to ensure other Nextcloud apps can only interact with
     * mappings through this service layer, rather than accessing the mapper directly.
     * This maintains proper encapsulation and separation of concerns.
     *
     * @return array<Mapping> An array containing all mapping entities
     */
    public function getMappings(): array
    {
        // Forward the findAll request to the mapper while maintaining encapsulation
        // @todo: add filtering options
        return $this->mappingMapper->findAll();
    }

}
