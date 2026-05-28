<?php
/**
 * OpenConnector MappingService.
 *
 * Mapping service that delegates core execution to OpenRegister's MappingService
 * when available, falling back to its own implementation.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Twig\AuthenticationExtension;
use OCA\OpenConnector\Twig\AuthenticationRuntimeLoader;
use OCA\OpenConnector\Twig\MappingExtension;
use OCA\OpenConnector\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Adbar\Dot;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Throwable;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
     * @param ArrayLoader        $loader          The ArrayLoader for Twig.
     * @param CallService        $callService     The call service.
     * @param FileService        $fileService     The file service.
     * @param ObjectService      $objectService   The OpenConnector object service.
     * @param ORObjectService    $orObjectService The OpenRegister object service.
     * @param ContainerInterface $container       The PSR container (injected; replaces \OC::$server for service lookup).
     * @param LoggerInterface    $logger          The logger (injected; replaces \OC::$server->getLogger()).
     */
    public function __construct(
        ArrayLoader $loader,
        CallService $callService,
        FileService $fileService,
        ObjectService $objectService,
        private readonly ORObjectService $orObjectService,
        ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        $this->twig = new Environment($loader);

        // Sandbox the mapping Twig environment so template authors cannot call
        // arbitrary PHP methods on objects or use undeclared functions.
        // Allowed functions mirror MappingExtension::getFunctions(); allowed
        // filters mirror MappingExtension::getFilters().
        // NOTE: `callSource` and `executeMapping` are deliberately excluded —
        // see MappingExtension::getFunctions() for the security rationale.
        $sandboxPolicy = new SecurityPolicy(
            allowedTags: [
                'if',
                'for',
                'set',
                'block',
                'extends',
                'include',
                'macro',
                'import',
                'from',
                'with',
            ],
            allowedFilters: [
                'b64enc',
                'b64dec',
                'json_decode',
                'slugify',
                'upper',
                'lower',
                'trim',
                'replace',
                'default',
                'join',
                'split',
                'keys',
                'merge',
                'slice',
                'first',
                'last',
                'reverse',
                'sort',
                'length',
                'abs',
                'round',
                'date',
                'date_modify',
                'escape',
                'raw',
                'nl2br',
                'number_format',
                'title',
                'capitalize',
                'striptags',
                'format',
                'batch',
            ],
            allowedFunctions: [
                'generateUuid',
                'getFileContents',
                'getFiles',
                'range',
                'cycle',
                'random',
                'date',
                'include',
                'source',
                'max',
                'min',
                'attribute',
                'block',
                'parent',
                'dump',
            ],
        );
        $this->twig->addExtension(new SandboxExtension(policy: $sandboxPolicy, sandboxed: true));

        $this->twig->addExtension(new MappingExtension());
        $this->twig->addRuntimeLoader(
            new MappingRuntimeLoader(
                mappingService: $this,
                callService: $callService,
                fileService: $fileService,
                objectService: $objectService
            )
        );

        // Try to load OpenRegister's MappingService for delegation.
        try {
            $this->openRegisterMappingService = $container->get(
                \OCA\OpenRegister\Service\MappingService::class
            );
        } catch (\Throwable $e) {
            // OpenRegister not available, falling back to local implementation.
            $this->openRegisterMappingService = null;
            $this->logger->info(
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-1
     */
    public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array
    {
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
     * @param array  $context  The context available inside the template.
     *
     * @return string The rendered template result.
     *
     * @throws LoaderError|SyntaxError Twig exceptions.
     *
     * @psalm-param array<string, mixed> $context
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-1
     */
    public function renderTemplateString(string $template, array $context=[]): string
    {
        return html_entity_decode($this->twig->createTemplate($template)->render($context));

    }//end renderTemplateString()

    /**
     * Maps (transforms) an array (input) to a different array (output).
     *
     * Delegates to OpenRegister's MappingService when available, otherwise
     * uses the local implementation.
     *
     * @param \OCA\OpenRegister\Db\Mapping|ObjectEntity $mapping The mapping object that forms the recipe for the mapping
     * @param array                                     $input   The array that need to be mapped (transformed) otherwise known as input
     * @param bool                                      $list    Whether we want a list instead of a single item
     *
     * @return array The result (output) of the mapping process
     * @throws LoaderError|SyntaxError Twig Exceptions
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-1
     */
    public function executeMapping(\OCA\OpenRegister\Db\Mapping|ObjectEntity $mapping, array $input, bool $list=false): array
    {
        // Normalise: if we received an ObjectEntity, hydrate an OR Mapping from it.
        if ($mapping instanceof ObjectEntity) {
            $orMapping = new \OCA\OpenRegister\Db\Mapping();
            $orMapping->hydrate($mapping->getObject());
            $mapping = $orMapping;
        }

        // Delegate to OpenRegister's MappingService if available.
        if ($this->openRegisterMappingService !== null) {
            return $this->openRegisterMappingService->executeMapping(
                mapping: $mapping,
                input: $input,
                list: $list
            );
        }

        return $this->executeMappingLocal(mapping: $mapping, input: $input, list: $list);

    }//end executeMapping()

    /**
     * Local mapping execution (fallback when OpenRegister is not available).
     *
     * @param \OCA\OpenRegister\Db\Mapping $mapping The mapping object
     * @param array                        $input   The input array
     * @param bool                         $list    Whether to process as list
     *
     * @return array The mapped output
     *
     * @throws Exception When mapping fails
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-1
     */
    private function executeMappingLocal(\OCA\OpenRegister\Db\Mapping $mapping, array $input, bool $list=false): array
    {
        // Check for list.
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
                    // Todo: we want to remove ['value' => $value] from this at some point, for now required for DOWR to work.
                    $value = array_merge((array) $value, ['value' => $value], $extraValues);
                }

                $list[$key] = $this->executeMapping(mapping: $mapping, input: $value);
            }

            return $list;
        }//end if

        $originalInput = $input;
        $input         = $this->encodeArrayKeys(array: $input, toReplace: '.', replacement: '&#46;');

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
            if (is_array($rootValue) === true) {
                $output = $rootValue;
            } else {
                $output = [$rootValue];
            }

            if ($rootValue === null) {
                $output = [];
            }
        }

        // Defensive coercion: prior branches set $output from $dotArray->all() (always array)
        // or from the # root-level extraction (always array). The is_array() guard is dead
        // per static analysis but kept for safety against future refactors.
        if (is_array($output) === false) {
            $output = [];
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-2
     */
    private function handleCast(Dot $dotArray, string $key, string $cast)
    {
        $value          = $dotArray->get($key);
        $unsetIfValue   = null;
        $setNullIfValue = null;
        $countValue     = null;

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
                if ($value === null) {
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
                // See https://www.php.net/manual/en/function.iconv.php.
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
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-2
     */
    private function areAllArrayKeysNull(array $array): bool
    {
        if (empty($array) === true) {
            return true;
        }

        foreach ($array as $value) {
            if (is_array($value) === true) {
                if ($this->areAllArrayKeysNull(array: $value) === false) {
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-2
     */
    public function coordinateStringToArray(string $coordinates): array
    {
        $halves          = explode(' ', $coordinates);
        $point           = [];
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
     * Retrieves a single mapping by its ID.
     *
     * This is a wrapper function that provides controlled access to the mapping data.
     * We use this wrapper pattern to ensure other Nextcloud apps can only interact with
     * mappings through this service layer, rather than accessing the storage directly.
     * This maintains proper encapsulation and separation of concerns.
     *
     * @param string $mappingId The unique identifier of the mapping to retrieve.
     *
     * @return ObjectEntity The requested mapping entity.
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-3
     */
    public function getMapping(string $mappingId): ObjectEntity
    {
        return $this->orObjectService->find(id: $mappingId, register: 'openconnector', schema: 'mapping');

    }//end getMapping()

    /**
     * Retrieves all available mappings.
     *
     * This is a wrapper function that provides controlled access to the mapping data.
     * We use this wrapper pattern to ensure other Nextcloud apps can only interact with
     * mappings through this service layer, rather than accessing the storage directly.
     * This maintains proper encapsulation and separation of concerns.
     *
     * @return array<ObjectEntity> An array containing all mapping entities
     *
     * @spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-3
     */
    public function getMappings(): array
    {
        $result = $this->orObjectService->findAll(config: ['filters' => ['register' => 'openconnector', 'schema' => 'mapping']]);
        return ($result['results'] ?? $result);

    }//end getMappings()
}//end class
