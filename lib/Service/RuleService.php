<?php

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use Exception;
use OCA\OpenConnector\Db\Rule;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use DateTime;

/**
 * Service for handling Rule processing in the OpenConnector app.
 *
 * This service provides functionality to process various types of Rules,
 * applying transformations and business logic to data based on rule configurations.
 *
 * Note: The custom rules functionality is experimental and subject to change.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class RuleService
{
    // @TODO: replace this with a way to store these uuids somewhere.
    /**
     * Index for tracking the current node ID for software catalog
     * Used to ensure consistent node identifiers in the output
     */
    private int $currentNodeIdIndex = 0;

    // @TODO: replace this with a way to store these uuids somewhere.
    /**
     * Predefined node IDs for software catalog visualization
     * Used to ensure consistent node identifiers in the output
     */
    private const NODE_IDS = [
        'f8c3de3d-1fea-4d7c-a8b0-29f63c4c3454',
        '7b9e4c1d-0f8a-48b5-9e27-edb90a92e610',
        'a6d3b4c2-9e8f-4c5d-b2a1-6e5f7a8b9c0d',
        'e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b',
        'b7c8d9e0-f1a2-4b3c-5d6e-7f8a9b0c1d2e',
        'd4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a',
        '9a8b7c6d-5e4f-4a3b-2c1d-0e9f8a7b6c5d',
        '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f',
        '5f6e7d8c-9b0a-4f1e-2d3c-4b5a6c7d8e9f',
        '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
        'c5d6e7f8-a9b0-4c1d-2e3f-4a5b6c7d8e9f',
        '8e9f0a1b-2c3d-4e5f-6a7b-8c9d0e1f2a3b',
        '3b4c5d6e-7f8a-49b0-c1d2-e3f4a5b6c7d8',
        '6a7b8c9d-0e1f-4a2b-3c4d-5e6f7a8b9c0d',
        '0e1f2a3b-4c5d-4e6f-7a8b-9c0d1e2f3a4b',
        'd1e2f3a4-b5c6-4d7e-8f9a-0b1c2d3e4f5a',
        '7f8a9b0c-1d2e-4f3a-4b5c-6d7e8f9a0b1c',
        '2d3e4f5a-6b7c-48d9-e0f1-a2b3c4d5e6f7',
        'a0b1c2d3-e4f5-4a6b-7c8d-9e0f1a2b3c4d',
        '4e5f6a7b-8c9d-4e0f-1a2b-3c4d5e6f7a8b'
    ];

    // @TODO: replace this with a way to store these uuids somewhere.
    /**
     * Index for tracking the current node ID for software catalog
     * Used to ensure consistent node identifiers in the output
     */
    private int $currentRelationIdIndex = 0;

    // @TODO: replace this with a way to store these uuids somewhere.
    /**
     * Predefined relation IDs for software catalog connections
     * Used to maintain consistent relationship identifiers between components
     */
    private const RELATION_IDS = [
        "a1b2c3d4-e5f6-4321-87a9-b1c2d3e4f5g6",
        "b2c3d4e5-f6a1-4321-87a9-c3d4e5f6a1b2",
        "c3d4e5f6-a1b2-4321-87a9-d4e5f6a1b2c3",
        "d4e5f6a1-b2c3-4321-87a9-e5f6a1b2c3d4",
        "e5f6a1b2-c3d4-4321-87a9-f6a1b2c3d4e5",
        "f6a1b2c3-d4e5-4321-87a9-a1b2c3d4e5f6",
        "a2b3c4d5-e6f7-5432-98ba-c2d3e4f5g6h7",
        "b3c4d5e6-f7a2-5432-98ba-d3e4f5g6h7a2",
        "c4d5e6f7-a2b3-5432-98ba-e4f5g6h7a2b3",
        "d5e6f7a8-b3c4-5432-98ba-d5e6f7a8b9c0",
        "e6f7a8b9-c4d5-5432-98ba-e6f7a8b9c0d1",
        "f7a8b9c0-d5e6-5432-98ba-f7a8b9c0d1e2",
        "a8b9c0d1-e6f7-5432-98ba-a8b9c0d1e2f3",
        "b9c0d1e2-f7a8-5432-98ba-b9c0d1e2f3a4",
        "c0d1e2f3-a8b9-5432-98ba-c0d1e2f3a4b5",
        "d1e2f3a4-b9c0-5432-98ba-d1e2f3a4b5c6",
        "e2f3a4b5-c0d1-5432-98ba-e2f3a4b5c6d7",
        "f3a4b5c6-d1e2-5432-98ba-f3a4b5c6d7e8",
        "a4b5c6d7-e2f3-5432-98ba-a4b5c6d7e8f9",
        "b5c6d7e8-f3a4-5432-98ba-b5c6d7e8f9a0"
    ];

    /**
     * Property definitions for the software catalog
     * These are used to ensure consistent property identifiers across objects
     */
    private const PROPERTY_DEFINITIONS = [
        'id-7d91e5c8-f624-48a3-b529-173e4b6d5f9c' => 'Datum export',
        'id-9358c742-a631-47b5-80d4-f8e69b3a5d12' => 'SWC type',
        'id-21f8e937-65b4-42d1-9c3a-a8b7f6d4e215' => 'Extern Pakket',
        'id-b4a7c523-8f19-4e67-9d38-c26517a9e8b4' => 'Omschrijving gebruik',
        'id-a7c84b23-9f56-42e1-b5d7-8c3e9a2f4b8a' => 'Titel view SWC',
        'id-65d23a1f-b9c7-483e-a612-d4f8e7b3c529' => 'Verbindingsrol',
        'id-f18e5d2c-7b4a-496c-85e3-9a2b1c6d7e4f' => 'Object ID',
        'id-a5524578-7a1c-464e-b628-c6125dc4a6c6' => 'Bron'
    ];

    /**
     * Property definition keys for easier reference
     */
    private const PROP_DATUM_EXPORT = 'id-7d91e5c8-f624-48a3-b529-173e4b6d5f9c';
    private const PROP_SWC_TYPE = 'id-9358c742-a631-47b5-80d4-f8e69b3a5d12';
    private const PROP_EXTERN_PAKKET = 'id-21f8e937-65b4-42d1-9c3a-a8b7f6d4e215';
    private const PROP_OMSCHRIJVING = 'id-b4a7c523-8f19-4e67-9d38-c26517a9e8b4';
    private const PROP_TITEL_VIEW = 'id-a7c84b23-9f56-42e1-b5d7-8c3e9a2f4b8a';
    private const PROP_VERBINDINGSROL = 'id-65d23a1f-b9c7-483e-a612-d4f8e7b3c529';
    private const PROP_OBJECT_ID = 'id-f18e5d2c-7b4a-496c-85e3-9a2b1c6d7e4f';
    private const BRON = 'id-a5524578-7a1c-464e-b628-c6125dc4a6c6';

    /**
     * Stores relation IDs created during processing for the software catalog
     * Used to add all relations to the relations folder in a single batch
     */
    private array $createdRelationIds = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly SoftwareCatalogueService $catalogueService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
		private readonly CallService $callService,
		private readonly SourceMapper $sourceMapper,
    )
    {
    }

    /**
     * Process a custom rule
     *
     * @param Rule $rule The rule to process
     * @param array $data The data to process
     *
     * @return array The updated data array.
     */
    public function processCustomRule(Rule $rule, array $data): array|JSONResponse
    {
        $type = $rule->getConfiguration()['type'];

        // Process custom rule based on type
        $data = match ($type) {
            'softwareCatalogus' => $this->processSoftwareCatalogusRule($rule, $data),
            'connectRelations' => $this->processCustomConnectionsRule($rule, $data),
            default => throw new Exception('Unsupported custom rule type: ' . $rule->getType()),
        };

        return $data;
    }

    /**
     * Process a Software Catalogus rule
     *
     * @param Rule $rule The rule to process
     * @param array $data The data to process
     *
     * @return array The updated data array
     */
    private function processSoftwareCatalogusRule(Rule $rule, array $data): array
    {
        $config = $rule->getConfiguration()['configuration'];

        // Get register ID and schema IDs
        $registerId = $config['register'];
        $voorzieningSchemaId = $config['VoorzieningSchema'];
        $voorzieningGebruikSchemaId = $config['VoorzieningGebruikSchema'];
        $organisatieSchemaId = $config['OrganisatieSchema'];
        $voorzieningAanbodSchemaId = $config['VoorzieningAanbodSchema'];

        // Get OpenRegisters instance and set the register
        $openRegisters = $this->objectService->getOpenRegisters();
        $openRegisters->setRegister($registerId);

        // Fetch Voorziening objects
        $openRegisters->setSchema($voorzieningSchemaId);
        $objectEntityMapper = $openRegisters->getMapper('objectEntity');

        // Fetch VoorzieningGebruik objects
        $openRegisters->setSchema($voorzieningGebruikSchemaId);
        $objectEntityMapper = $openRegisters->getMapper('objectEntity');
        $voorzieningGebruiken = $objectEntityMapper->findAll(
            filters: [
                'register' => $registerId,
                'schema' => $voorzieningGebruikSchemaId,
                'organisatieId' => $data['parameters']['organisatie'],
            ]
        );

        $voorzieningIds = array_map(function(ObjectEntity $voorzieningGebruik):?string {
            $json = $voorzieningGebruik->jsonSerialize();

            return $json['voorzieningId'] ?? null;
        }, $voorzieningGebruiken);

        $voorzieningIds = array_values(array_filter($voorzieningIds));

        $voorzieningen = $objectEntityMapper->findAll(
            filters: [
                'register' => $registerId,
                'schema' => $voorzieningSchemaId,
            ],
            ids: $voorzieningIds,
        );


        // Process property definitions and update basic metadata
        $data = $this->processPropertyDefinitionsAndMetadata($data);

        $register = $this->registerMapper->find('vng-gemma');
        $schema = $this->schemaMapper->find('extendview');
        $addedViews = $this->objectService->getOpenRegisters()->findAll(['filters' => ['register' => $register->getId(), 'schema' => $schema->getId()]]);

        $addedViews = array_map(function(ObjectEntity $view): array {
            $view = $view->jsonSerialize();

            if(str_ends_with($view['identifier'], SoftwareCatalogueService::SUFFIX) === false) {
                $view['identifier'] = $view['identifier'].SoftwareCatalogueService::SUFFIX;
            }
            return $view;
        }, $addedViews);

        $publishPropertyId = $this->getPublishPropertyId($data['body']['propertyDefinitions']);

        $addedViews = array_filter($addedViews, function (array $view) use ($publishPropertyId) {
            return count($properties = array_filter($view['properties'], function($property) use ($publishPropertyId) {return $property['propertyDefinitionRef'] === $publishPropertyId;})) !== 0
                && array_shift($properties)['value'] === 'Softwarecatalogus en GEMMA Online en redactie';
        });

        // Find and configure organizational folders
        list($applicationFolderKey, $relationsFolderKey, $applicationFolderCount, $relationsFolderCount)
            = $this->setupOrganizationalFolders($data);

        // Process voorzieningen data and add to export
        $data = $this->processVoorzieningenData(
            $data,
            $voorzieningen,
            $applicationFolderKey,
            $applicationFolderCount,
            $addedViews
        );

        $data['body']['views'] = array_merge($data['body']['views'], $addedViews);

        $viewIds = array_column($addedViews, 'identifier');

        $organization = [
            "label"      => 'Views (Softwarecatalogus)',
            "label-lang" => "en",
        ];
        foreach($viewIds as $viewId) {
            $organization['item'][] = ['identifierRef' => $viewId];
        }

        $data['body']['organizations'][] = $organization;

        // Add all created relations to the Relations folder
        foreach ($this->createdRelationIds as $relationId) {
            $data['body']['organizations'][$relationsFolderKey]['item'][$relationsFolderCount]['item'][] = [
                'identifierRef' => $relationId
            ];
        }

        // // Add organisaties (leveranciers) to response data
        // foreach ($organisaties as $organisatie) {
        //     $organisatie = $organisatie->jsonSerialize();

        //     $newUuid = Uuid::v4();
        //     $elementId = "id-{$newUuid}";
        //     $data['body']['elements'][] = [
        //         'identifier' => $elementId,
        //         'name' => $organisatie['naam'],
        //         'name-lang' => 'nl',
        //         'documentation' => $organisatie['beschrijving'],
        //         'documentation-lang' => 'nl',
        //         'type' => 'BusinessActor',
        //         'properties' => [
        //             0 => [
        //                 'propertyDefinitionRef' => 'id-c3355444b6cb8fb34b62e241dd073043', // SWC type
        //                 'value' => 'Leverancier',
        //             ],
        //             1 => [
        //                 'propertyDefinitionRef' => 'propid-2', // Object ID
        //                 'value' => $newUuid,
        //             ],
        //             2 => [
        //                 'propertyDefinitionRef' => 'propid-39', // URL
        //                 'value' => '',
        //             ],
        //         ],
        //     ];
        // }

        // foreach ($voorzieningAanbod as $voorzieningAanbod) {
        //     $voorzieningAanbod = $voorzieningAanbod->jsonSerialize();
        // }

        return $data;
    }

    /**
     * Find the 'publiceren' property by name in the list of propertyDefinitions
     *
     * @param array $propertyDefinitions The list of propertyDefinitions
     * @return string The id of the 'Publiceren' property
     */
    private function getPublishPropertyId(array $propertyDefinitions): string
    {
        $ids = array_column(array_filter($propertyDefinitions, function($propertyDefinition){
            return $propertyDefinition['name'] === 'Publiceren';
        }), 'identifier');

        return array_shift($ids);
    }//end getPublishPropertyId

    /**
     * Process property definitions and update basic metadata in the data structure
     *
     * Validates existing property definitions, adds missing ones, and sets up
     * basic metadata like export date and file name.
     *
     * @param array $data The data structure to update
     *
     * @return array The updated data
     */
    private function processPropertyDefinitionsAndMetadata(array $data): array
    {
        // Check if property definitions already exist
        $propertyDefinitions = array_fill_keys(array_keys(self::PROPERTY_DEFINITIONS), false);

        foreach ($data['body']['propertyDefinitions'] as $propertyDefinition) {
            if (isset($propertyDefinition['identifier']) === true && array_key_exists($propertyDefinition['identifier'], $propertyDefinitions) === true) {
                // Verify the name matches what we expect
                if (isset($propertyDefinition['name']) === true && $propertyDefinition['name'] !== self::PROPERTY_DEFINITIONS[$propertyDefinition['identifier']]) {
                    throw new Exception(sprintf(
                        'Property definition with ID %s has unexpected name: "%s". Expected: "%s"',
                        $propertyDefinition['identifier'],
                        $propertyDefinition['name'],
                        self::PROPERTY_DEFINITIONS[$propertyDefinition['identifier']]
                    ));
                }
                $propertyDefinitions[$propertyDefinition['identifier']] = true;
            }
        }

        // Add property definitions that don't exist yet
        foreach (self::PROPERTY_DEFINITIONS as $id => $name) {
            if ($propertyDefinitions[$id] === false) {
                $data['body']['propertyDefinitions'][] = [
                    'identifier' => $id,
                    'type' => 'string',
                    'name' => $name
                ];
            }
        }

        // Add datum export
        $datumExport = new DateTime();
        $data['body']['properties'][] = [
            'propertyDefinitionRef' => self::PROP_DATUM_EXPORT, // Datum export
            'value' => $datumExport->format('Y-m-d H:i:s'),
            'value-lang' => 'nl',
        ];

        // Update Model name
        $data['body']['name'] = "Turfburg (test VNG Realisatie)";

        // Update filename
        $filename = $datumExport->format('d-m-Y') . '_GEMMA 2_' . $data['body']['name'] . '_ameff_model.xml';
        $data['headers']['Content-Disposition'] = 'attachment; filename="' . $filename . '"';

        return $data;
    }

    /**
     * Find and configure organization folders in the data structure
     *
     * Locates Application and Relations folders, validates their existence,
     * and adds appropriate subfolders for the software catalog.
     *
     * @param array $data The data structure to update
     *
     * @return array An array with [$applicationFolderKey, $relationsFolderKey, $applicationFolderCount, $relationsFolderCount]
     */
    private function setupOrganizationalFolders(array &$data): array
    {
        // Get all folder keys
        $applicationFolderKey = null;
        $relationsFolderKey = null;

        foreach ($data['body']['organizations'] as $key => $organization) {
            if (isset($organization['label']) === true) {
                switch ($organization['label']) {
                    case 'Application':
                        $applicationFolderKey = $key;
                        break;
                    case 'Relations':
                        $relationsFolderKey = $key;
                        break;
                    // Add more cases here as needed for future folder types
                }
            }
        }

        // Check if both required folders exist
        if ($applicationFolderKey === null || $relationsFolderKey === null) {
            $missingFolders = array_filter([
                'Application' => $applicationFolderKey === null,
                'Relations' => $relationsFolderKey === null,
            ]);
            throw new Exception('Required folder(s) not found in organizations: ' . implode(', ', array_keys($missingFolders)));
        }

        // Add the Applicaties / Pakketten (Softwarecatalogus) folder
        $applicationFolderCount = count($data['body']['organizations'][$applicationFolderKey]['item']); // Index for adding to this organization/folder later on.
        $data['body']['organizations'][$applicationFolderKey]['item'][] = [
            'identifier' => "id-29ec7061-0aba-c9eb-25fd-7c9232e4f0",
            'label' => "Applicaties (Softwarecatalogus)",
            'label-lang' => "nl"
        ];

        // Add the Relaties (Softwarecatalogus) folder
        $relationsFolderCount = count($data['body']['organizations'][$relationsFolderKey]['item']); // Index for adding to this organization/folder later on.
        $data['body']['organizations'][$relationsFolderKey]['item'][] = [
            'identifier' => "id-8e7d5c3b-6a2f-9d4e-1b3c-7a9e2d5f8c0b",
            'label' => "Relaties (Softwarecatalogus)",
            'label-lang' => "nl"
        ];

        return [$applicationFolderKey, $relationsFolderKey, $applicationFolderCount, $relationsFolderCount];
    }

    /**
     * Process voorzieningen data and add to export structure
     *
     * Processes voorzieningen objects, counts children for reference components,
     * creates elements and relationships, and adds them to the appropriate folders.
     *
     * @param array $data The data structure to update
     * @param array $voorzieningen The voorzieningen to process
     * @param string $applicationFolderKey The key of the Application folder
     * @param int $applicationFolderCount The count of items in the Application folder
     * @param string $relationsFolderKey The key of the Relations folder
     * @param int $relationsFolderCount The count of items in the Relations folder
     *
     * @return array The updated data
     */
    private function processVoorzieningenData(
        array $data,
        array $voorzieningen,
        string $applicationFolderKey,
        int $applicationFolderCount,
        array &$views,
    ): array {
        // Reset relation IDs array
        $this->createdRelationIds = [];

        // Count the total amount of children we are going to add for each referentieComponent.
        $newChildrenCount = [];
        foreach ($voorzieningen as $voorziening) {
            $voorziening = $voorziening->jsonSerialize();

            foreach ($voorziening['referentieComponenten'] as $referentieComponent) {
                if (isset($newChildrenCount[$referentieComponent]) === false) {
                    $newChildrenCount[$referentieComponent] = 0;
                }
                $newChildrenCount[$referentieComponent]++;
            }
        }

        // Add voorzieningen (pakketten/applicaties) to response data
        foreach ($voorzieningen as $voorziening) {
            $voorziening = $voorziening->jsonSerialize();

            if(isset($voorziening['id']) === false) {
                $voorziening['id'] = $voorziening['@self']['id'];
            }

            $elementId = "id-{$voorziening['id']}";

            // Add voorziening to Application folder
            $data['body']['organizations'][$applicationFolderKey]['item'][$applicationFolderCount]['item'][] = [
                'identifierRef' => $elementId
            ];

            // Add voorziening to elements
            $data['body']['elements'][] = [
                'identifier' => $elementId,
                'name' => $voorziening['naam'],
                'name-lang' => 'nl',
                'documentation' => $voorziening['beschrijving'],
                'documentation-lang' => 'nl',
                'type' => 'ApplicationComponent',
                'properties' => [
                    [
                        'propertyDefinitionRef' => self::PROP_SWC_TYPE, // SWC type
                        'value' => 'Pakket',
                    ],
                    [
                        'propertyDefinitionRef' => self::PROP_OBJECT_ID, // Object ID
                        'value' => $voorziening['id'],
                    ],
                    [
                        'propertyDefinitionRef' => 'propid-39', // URL
                        'value' => '',
                    ],
                    [
                        'propertyDefinitionRef' => self::PROP_EXTERN_PAKKET, // Extern Pakket
                        'value' => 'n',
                    ],
                    [
                        'propertyDefinitionRef' => self::PROP_OMSCHRIJVING, // Omschrijving gebruik
                        'value' => '',
                    ],
                    [
                        'propertyDefinitionRef' => self::BRON, // Omschrijving gebruik
                        'value' => 'Softwarecatalogus',
                    ],
                ],
            ];

            // Add new nodes and add relations between voorziening and referentiecomponent
            foreach ($voorziening['referentieComponenten'] as $referentieComponent) {
                // Create relation between voorziening and referentieComponent
                $relationId = $this->createRelation(
                    data: $data,
                    sourceId: $elementId,
                    targetId: $referentieComponent,
                    relationType:'Specialization'
                );
                    foreach ($views as &$view) {

                        foreach ($view['connections'] as &$connection) {
                            $connection['source']     = $connection['source'];
                            $connection['target']     = $connection['target'];
                            $connection['identifier'] = $connection['identifier'];
                        }

                        $connections = [];
                        if (isset($view['nodes']) && is_array($view['nodes'])) {
                            $this->processNodes($view['nodes'], $referentieComponent, $elementId, $newChildrenCount[$referentieComponent] ?? 0, $data, $relationId, $connections);
                        }

                        if (isset($view['connections']) === false || empty($view['connections']) === true) {
                            $view['connections'] = [];
                        }

                        $view['connections'] = array_merge($view['connections'], $connections);
                    }
                }


            }

        return $data;
    }

    private function createConnection(string $relationId, string $sourceId, string $targetId)
    {
        $connectionUuid = Uuid::v4();


        return [
            "identifier" => "id-$connectionUuid",
            "relationshipRef" => "$relationId",
            "type" => "Relationship",
            "source" => $sourceId,
            "target" => $targetId,
            "style" => [
                "lineColor" => [
                    "r" => "0",
                    "g" => "0",
                    "b" => "0"
                ],
                "font" => [
                    "name" => "Segoe UI",
                    "size" => "9"
                ],
                "color" => [
                    "r" => "0",
                    "g" => "0",
                    "b" => "0"
                ]
            ]
        ];
    }

    /**
     * Recursively processes nodes and their nested nodes to find matches and create subnodes
     *
     * @param array &$nodes The nodes to process
     * @param string|null $matchIdentificatie The identificatie to match against elementRef
     * @param string $newElementId The ID of the new element to reference
     * @param int $totalNewChildren The total amount of children we are going to add for the current $matchIdentificatie.
     * @param array &$data The data structure to update
     *
     * @return void
     */
    private function processNodes(array &$nodes, ?string $matchIdentificatie, string $newElementId, int $totalNewChildren, array &$data, string $relationId, array &$connections): void
    {
        // If matchIdentificatie is null, return early
        if ($matchIdentificatie === null) {
            return;
        }

        // Loop through each node in the array
        foreach ($nodes as &$node) {

            // Check if current node has an elementRef property and if it matches the target identificatie
            if (isset($node['elementRef']) === true && $node['elementRef'] === $matchIdentificatie) {
                // Create a subnode with reference to the newly created element
                $subnodeUuid = 'id-OutOfUniqueUUIDs-' . $this->currentNodeIdIndex;
                if ($this->currentNodeIdIndex < count(self::NODE_IDS)) {
                    $subnodeUuid = self::NODE_IDS[$this->currentNodeIdIndex];
                }
                $this->currentNodeIdIndex++;
                $subnodeId = "id-{$subnodeUuid}";

                // Initialize the nodes array if it doesn't exist properly
                if (isset($node['nodes']) === false || is_array($node['nodes']) === false) {
                    $node['nodes'] = [];
                }

                // Count the total amount of children including the new subnodes and store it in the node array.
                if (isset($node['totalChildren']) === false) {
                    $node['totalChildren'] = count($node['nodes']) + $totalNewChildren;
                }

                // Count the total amount of children including the new subnode
                $totalChildren = $node['totalChildren'];

                // Calculate the index of the child node
                $childIndex = count($node['nodes']) + 1;

                $parentPadding = 20;
                $childSpacing = 8;
                $parentWidth = $node['position']['w'] - ($parentPadding * 2);
                $parentHeight = $node['position']['h'] - ($parentPadding * 2);

                // Calculate child width:
                // Available width = (parent width - left/right padding - spacing between children) / number of children
                $childWidth = min(
                    ($parentWidth - ($childSpacing * ($totalChildren - 1))) / $totalChildren,
                    120 // Maximum width of 120px
                );

                // Child height at least 30px and no more than 100px
                $childHeight = max(30, min($parentHeight, 100));
                // If there is another child node, use the height of that child node, but no more than the parent height.
                if (isset($node['nodes'][0]) === true) {
                    $childHeight = max(30, min($node['nodes'][0]['position']['h'], $parentHeight));
                }

                // Calculate X position:
                // Start from parent's left edge + padding + (child's index × (child width + spacing))
                $absoluteX = $node['position']['x'] + $parentPadding + (($childIndex - 1) * ($childWidth + $childSpacing));

                // Calculate Y position:
                // Position from bottom of parent
                $absoluteY = $node['position']['y'] + ($node['position']['h'] - $childHeight - 10);

                // Add subnode with reference to new element
                $node['nodes'][] = [
                    'identifier' => $subnodeId,
                    'elementRef' => $newElementId,
                    'type' => 'Element',
                    'position' => [
                        'x' => (int) $absoluteX,
                        'y' => (int) $absoluteY,
                        'w' => (int) $childWidth,
                        'h' => (int) $childHeight
                    ],
                    'style' => [
                        'lineWidth' => "1",
                        'fillColor' => [
                            'r' => "100",
                            'g' => "149",
                            'b' => "237",
                            'a' => "100"
                        ],
                        'lineColor' => [
                            'r' => "0",
                            'g' => "0",
                            'b' => "0",
                            'a' => "100"
                        ],
                        'font' => [
                            'name' => 'Arial',
                            'size' => "10",
                            'style' => 'plain'
                        ],
                        'color' => [
                            'r' => "0",
                            'g' => "0",
                            'b' => "0"
                        ] // @TODO: Somehow when all 3 are 0, color is removed from the style array...
                    ]
                ];

                // @TODO: Create relation between voorziening node and referentieComponent node
                 $connections[] = $this->createConnection(
                     relationId: $relationId, sourceId: $subnodeId, targetId: $node['identifier']
                 );
            }

            // Process nested nodes recursively if they exist
            if (isset($node['nodes']) === true && is_array($node['nodes']) === true) {
                // Call this function recursively on the nested nodes
                $this->processNodes(
                    nodes:$node['nodes'],
                    matchIdentificatie: $matchIdentificatie,
                    newElementId: $newElementId,
                    totalNewChildren: $totalNewChildren,
                    data: $data,
                    relationId: $relationId,
                    connections: $connections);
            }
        }
    }

    /**
     * Creates a relation between elements and adds it to the data structure
     *
     * @param array &$data The data structure to update
     * @param string $sourceId The source element ID
     * @param string $targetId The target element ID
     * @param string $relationType The type of relation
     *
     * @return string The relation ID
     */
    private function createRelation(
        array &$data,
        string $sourceId,
        string $targetId,
        string $relationType
    ): string {
        // Create relation UUID
        $relationUuid = 'id-OutOfUniqueUUIDs-' . $this->currentRelationIdIndex;

        // Use predefined relation ID if available
        if ($this->currentRelationIdIndex < count(self::RELATION_IDS)) {
            $relationUuid = self::RELATION_IDS[$this->currentRelationIdIndex];
        }
        $this->currentRelationIdIndex++;

        // Add relation to relationships array
        $relationId = "id-{$relationUuid}";
        $data['body']['relationships'][] = [
            'identifier' => $relationId,
            'source' => $sourceId,
            'target' => $targetId,
            'type' => $relationType,
            'properties' => [
                [
                    'propertyDefinitionRef' => self::PROP_OBJECT_ID, // Object ID
                    'value' => $relationUuid,
                ],
                [
                    'propertyDefinitionRef' => self::BRON,
                    'value' => 'Softwarecatalogus'
                ]
            ],
        ];

        // Store relation ID for later folder assignment
        $this->createdRelationIds[] = $relationId;

        return $relationId;
    }

    private function processCustomConnectionsRule(Rule $rule, array $data): array|JSONResponse
    {
        $explodedPath = explode(separator: '/', string: $data['path']);

        if(is_string(end($explodedPath)) === true && Uuid::isValid(end($explodedPath)) === true) {
            $this->catalogueService->extendModel(end($explodedPath));

            return new JSONResponse(['message' => 'Connected views succesfully'], statusCode: 200);
        }

        return new JSONResponse(['message' => 'model id was not provided'], 200);
    }

	/**
	 * Fetches an external object and if requested, validate it.
	 *
	 * @param string $url The url to retrieve the object from.
	 * @param array $configuration Configuration of the rule
	 * @param string|int $schemaId The schema to validate against
	 *
	 * @return array The object found on $url
	 *
	 * @throws ValidationException
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 * @throws \Twig\Error\LoaderError
	 * @throws \Twig\Error\SyntaxError
	 */
	private function getExternalObject(string $url, array $configuration, string|int $schemaId): array
	{
		$source = $this->sourceMapper->findOrCreateByLocation($url);

		//@TODO The previous line returns an incomplete source, by fetching it again from the database we receive a working source
		$source = $this->sourceMapper->find($source->getId());

		$result = $this->callService->call($source);

		if($result->getStatusCode() !== 200) {
			throw new Exception(message: "The object on $url could not be fetched");
		}


		$object = json_decode(json: $result->getResponse()['body'], associative: true, flags: JSON_THROW_ON_ERROR);

		if ($configuration['extend_external_input']['validate'] === false) {
			return $object;
		}

		$validationHandler = $this->objectService->getOpenRegisters()->getValidateHandler();

		$validatedResult = $validationHandler->validateObject($object, $this->schemaMapper->find($schemaId));

		if($validatedResult->isValid() === true) {
			return $object;
		}

		throw new ValidationException(message: 'Fetched object cannot be validated', code: 400, errors: $validatedResult->error());

	}

	/**
	 * Extend an object with an external url
	 *
	 * @param Rule $rule The rule to execute.
	 * @param array $data The data to extend.
	 * @return array|JSONResponse
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	public function extendExternalUrl(Rule $rule, array $data): array|JSONResponse
	{
		$config = $rule->getConfiguration();

		$dataDot = new Dot($data);
		$extendedParameters = new Dot();
        foreach ($config['extend_external_input']['properties'] as $property) {
            $url = $dataDot->get($property['property']);
            $propName = explode(separator: '.', string: $property['property']);
            $propName = end($propName);
            try {
				if (is_array($url) === true) {
					$extendedParameters->add($property['property'], array_map(function (string $url) use ($property, $config) {
						return $this->getExternalObject($url, $config, $property['schema']);
					}, $url));
				}

				$extendedParameters->add($property['property'], $this->getExternalObject($url, $config, $property['schema']));
			} catch (ValidationException $exception) {

				return new JSONResponse(data: ['message' => 'Invalid Input', 'error' => 'The object referenced in field '. $property['property'] . ' is not valid', 'errors' => [['name' => $propName, 'code' => 'invalid-resource', 'reason' => 'The resource is not valid']]], statusCode: 400);
			} catch (Exception $exception) {
				return new JSONResponse(data: ['message' => 'Invalid Input', 'error' => $exception->getMessage(), 'errors' => [['name' => $propName, 'code' => 'invalid-resource', 'reason' => 'The resource is not valid']]], statusCode: 400);
			}
		}

		$existingParams = $data['extendedParameters'] ?? [];
		$data['extendedParameters'] = array_merge($extendedParameters->all(), $existingParams);

		$data['body']['_extendedInput'] = $data['extendedParameters'];

		return $data;

	}
}
