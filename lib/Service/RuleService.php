<?php

/**
 * Integriq rule service.
 *
 * Service for handling Rule processing in the Integriq app. Provides
 * functionality to process various types of Rules, applying transformations
 * and business logic to data based on rule configurations.
 *
 * Note: The custom rules functionality is experimental and subject to change.
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
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */

namespace OCA\Integriq\Service;

use Adbar\Dot;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCP\AppFramework\Http\JSONResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Service for handling Rule processing in the Integriq app.
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
class RuleService {
	// @TODO: replace this with a way to store these uuids somewhere.

	/**
	 * Index for tracking the current node ID for software catalog.
	 *
	 * Used to ensure consistent node identifiers in the output.
	 *
	 * @var integer
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
		'4e5f6a7b-8c9d-4e0f-1a2b-3c4d5e6f7a8b',
	];

	// @TODO: replace this with a way to store these uuids somewhere.

	/**
	 * // @TODO: replace this with a way to store these uuids somewhere.
	 * /**
	 * Predefined relation IDs for software catalog connections
	 * Used to maintain consistent relationship identifiers between components
	 */
	private const RELATION_IDS = [
		'a1b2c3d4-e5f6-4321-87a9-b1c2d3e4f5g6',
		'b2c3d4e5-f6a1-4321-87a9-c3d4e5f6a1b2',
		'c3d4e5f6-a1b2-4321-87a9-d4e5f6a1b2c3',
		'd4e5f6a1-b2c3-4321-87a9-e5f6a1b2c3d4',
		'e5f6a1b2-c3d4-4321-87a9-f6a1b2c3d4e5',
		'f6a1b2c3-d4e5-4321-87a9-a1b2c3d4e5f6',
		'a2b3c4d5-e6f7-5432-98ba-c2d3e4f5g6h7',
		'b3c4d5e6-f7a2-5432-98ba-d3e4f5g6h7a2',
		'c4d5e6f7-a2b3-5432-98ba-e4f5g6h7a2b3',
		'd5e6f7a8-b3c4-5432-98ba-d5e6f7a8b9c0',
		'e6f7a8b9-c4d5-5432-98ba-e6f7a8b9c0d1',
		'f7a8b9c0-d5e6-5432-98ba-f7a8b9c0d1e2',
		'a8b9c0d1-e6f7-5432-98ba-a8b9c0d1e2f3',
		'b9c0d1e2-f7a8-5432-98ba-b9c0d1e2f3a4',
		'c0d1e2f3-a8b9-5432-98ba-c0d1e2f3a4b5',
		'd1e2f3a4-b9c0-5432-98ba-d1e2f3a4b5c6',
		'e2f3a4b5-c0d1-5432-98ba-e2f3a4b5c6d7',
		'f3a4b5c6-d1e2-5432-98ba-f3a4b5c6d7e8',
		'a4b5c6d7-e2f3-5432-98ba-a4b5c6d7e8f9',
		'b5c6d7e8-f3a4-5432-98ba-b5c6d7e8f9a0',
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
		'id-a5524578-7a1c-464e-b628-c6125dc4a6c6' => 'Bron',
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
	 * Constructor for RuleService.
	 *
	 * @param ObjectService $objectService Integriq object-service facade.
	 * @param SoftwareCatalogueService $catalogueService Software-catalog rule helper.
	 * @param RegisterMapper $registerMapper Mapper used to resolve register IDs.
	 * @param SchemaMapper $schemaMapper Mapper used to resolve schema IDs.
	 * @param CallService $callService Service used for outbound HTTP calls during rule evaluation.
	 * @param ORObjectService $orObjectService OpenRegister object-service used by extend / save rules.
	 * @param RegisterResolverService|null $registerResolver Resolves `<context>_property` config keys to property
	 *                                                       identifiers; nullable so unit tests that don't
	 *                                                       exercise the catalogue rule path can omit the
	 *                                                       dependency.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SoftwareCatalogueService $catalogueService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly CallService $callService,
		private readonly ORObjectService $orObjectService,
		private readonly ?RegisterResolverService $registerResolver = null,
	) {
	}//end __construct()

	/**
	 * Process a custom rule.
	 *
	 * @param ObjectEntity $rule The rule to process.
	 * @param array $data The data to process.
	 *
	 * @return array|JSONResponse The updated data array (or a JSONResponse if the rule short-circuits).
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function processCustomRule(ObjectEntity $rule, array $data): array|JSONResponse {
		$ruleData = $rule->getObject();
		$type = ($ruleData['configuration']['type'] ?? '');

		// Process custom rule based on type.
		//
		// `softwareCatalogus` was removed. It was a rule type hard-coded to one
		// consumer's domain model — Voorziening / VoorzieningGebruik /
		// Organisatie / VoorzieningAanbod schemas, a `vng-gemma` register, an
		// `extendview` schema and literal `propertyDefinitionRef` ids — living
		// in the connector's shared rule pipeline, where every other connector
		// pays for it in surface area and nobody else can use it. That belongs
		// to the software-catalog app, or to a flow composed of generic steps,
		// which is where the OpenRegister flow migration takes the rest of this
		// pipeline anyway.
		$data = match ($type) {
			'connectRelations' => $this->processCustomConnectionsRule(rule: $rule, data: $data),
			default => throw new Exception('Unsupported custom rule type: ' . ($ruleData['type'] ?? '')),
		};

		return $data;
	}//end processCustomRule()

	/**
	 * Create an ArchiMate connection entry tying a relation/source/target triple together.
	 *
	 * @param string $relationId The relationship id to reference.
	 * @param string $sourceId The source element id.
	 * @param string $targetId The target element id.
	 *
	 * @return array The connection array shape consumed by the catalogue exporter.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function createConnection(string $relationId, string $sourceId, string $targetId) {
		$connectionUuid = Uuid::v4();

		return [
			'identifier' => "id-$connectionUuid",
			'relationshipRef' => "$relationId",
			'type' => 'Relationship',
			'source' => $sourceId,
			'target' => $targetId,
			'style' => [
				'lineColor' => [
					'r' => '0',
					'g' => '0',
					'b' => '0',
				],
				'font' => [
					'name' => 'Segoe UI',
					'size' => '9',
				],
				'color' => [
					'r' => '0',
					'g' => '0',
					'b' => '0',
				],
			],
		];
	}//end createConnection()

	/**
	 * Recursively processes nodes and their nested nodes to find matches and create subnodes
	 *
	 * @param array $nodes The nodes to process (passed by reference).
	 * @param string|null $matchIdentification The identificatie to match against elementRef.
	 * @param string $newElementId The ID of the new element to reference.
	 * @param int $totalNewChildren Count of children to add for the current $matchIdentification.
	 * @param array $data The data structure to update (passed by reference).
	 * @param string $relationId The relation id to attach to the new connection.
	 * @param array $connections Connections accumulator (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processNodes(
		array &$nodes,
		?string $matchIdentification,
		string $newElementId,
		int $totalNewChildren,
		array &$data,
		string $relationId,
		array &$connections,
	): void {
		// If matchIdentificatie is null, return early.
		if ($matchIdentification === null) {
			return;
		}

		// Loop through each node in the array.
		foreach ($nodes as &$node) {
			// Check if current node has an elementRef property and if it matches the target identificatie.
			if (isset($node['elementRef']) === true && $node['elementRef'] === $matchIdentification) {
				// Create a subnode with reference to the newly created element.
				$subnodeUuid = 'id-OutOfUniqueUUIDs-' . $this->currentNodeIdIndex;
				if ($this->currentNodeIdIndex < count(self::NODE_IDS)) {
					$subnodeUuid = self::NODE_IDS[$this->currentNodeIdIndex];
				}

				$this->currentNodeIdIndex++;
				$subnodeId = "id-{$subnodeUuid}";

				// Initialize the nodes array if it doesn't exist properly.
				if (isset($node['nodes']) === false || is_array($node['nodes']) === false) {
					$node['nodes'] = [];
				}

				// Count the total amount of children including the new subnodes and store it in the node array.
				if (isset($node['totalChildren']) === false) {
					$node['totalChildren'] = (count($node['nodes']) + $totalNewChildren);
				}

				// Count the total amount of children including the new subnode.
				$totalChildren = $node['totalChildren'];

				// Calculate the index of the child node.
				$childIndex = (count($node['nodes']) + 1);

				$parentPadding = 20;
				$childSpacing = 8;
				$parentWidth = ($node['position']['w'] - ($parentPadding * 2));
				$parentHeight = ($node['position']['h'] - ($parentPadding * 2));

				// Calculate child width: available width = (parent width - left/right padding - spacing between
				// children) / number of children, capped at a maximum of 120px per child.
				$childWidth = min(
					(($parentWidth - ($childSpacing * ($totalChildren - 1))) / $totalChildren),
					120
				);

				// Child height at least 30px and no more than 100px.
				$childHeight = max(30, min($parentHeight, 100));
				// If there is another child node, use the height of that child node, but no more than the parent height.
				if (isset($node['nodes'][0]) === true) {
					$childHeight = max(30, min($node['nodes'][0]['position']['h'], $parentHeight));
				}

				// Calculate X position: start from parent's left edge + padding +
				// (child's index x (child width + spacing)).
				$absoluteX = ($node['position']['x'] + $parentPadding + (($childIndex - 1) * ($childWidth + $childSpacing)));

				// Calculate Y position: position from bottom of parent.
				$absoluteY = ($node['position']['y'] + ($node['position']['h'] - $childHeight - 10));

				// Add subnode with reference to new element.
				$node['nodes'][] = [
					'identifier' => $subnodeId,
					'elementRef' => $newElementId,
					'type' => 'Element',
					'position' => [
						'x' => (int)$absoluteX,
						'y' => (int)$absoluteY,
						'w' => (int)$childWidth,
						'h' => (int)$childHeight,
					],
					'style' => [
						'lineWidth' => '1',
						'fillColor' => [
							'r' => '100',
							'g' => '149',
							'b' => '237',
							'a' => '100',
						],
						'lineColor' => [
							'r' => '0',
							'g' => '0',
							'b' => '0',
							'a' => '100',
						],
						'font' => [
							'name' => 'Arial',
							'size' => '10',
							'style' => 'plain',
						],
						// @TODO: Somehow when all 3 are 0, color is removed from the style array...
						'color' => [
							'r' => '0',
							'g' => '0',
							'b' => '0',
						],
					],
				];

				// @TODO: Create relation between voorziening node and referentieComponent node.
				$connections[] = $this->createConnection(
					relationId: $relationId,
					sourceId: $subnodeId,
					targetId: $node['identifier']
				);
			}//end if

			// Process nested nodes recursively if they exist.
			if (isset($node['nodes']) === true && is_array($node['nodes']) === true) {
				// Call this function recursively on the nested nodes.
				$this->processNodes(
					nodes:$node['nodes'],
					matchIdentification: $matchIdentification,
					newElementId: $newElementId,
					totalNewChildren: $totalNewChildren,
					data: $data,
					relationId: $relationId,
					connections: $connections
				);
			}
		}//end foreach
	}//end processNodes()

	/**
	 * Process the custom-connections rule by extending the catalogue model with the given model id.
	 *
	 * @param ObjectEntity $rule The rule being processed.
	 * @param array $data The rule data envelope.
	 *
	 * @return array|JSONResponse A JSON-response with the outcome, or the data on no-op paths.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function processCustomConnectionsRule(ObjectEntity $rule, array $data): array|JSONResponse {
		$explodedPath = explode(separator: '/', string: (string)($data['path'] ?? ''));

		if (is_string(end($explodedPath)) === true && Uuid::isValid(end($explodedPath)) === true) {
			$this->catalogueService->extendModel(end($explodedPath));

			return new JSONResponse(['message' => 'Connected views succesfully'], statusCode: 200);
		}

		return new JSONResponse(['message' => 'model id was not provided'], 200);
	}//end processCustomConnectionsRule()

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
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	private function getExternalObject(string $url, array $configuration, string|int $schemaId): array {
		// Find an existing source by location, or create one if not found.
		//
		// System context (ocon#147): the `source` schema is admin-only now, but this
		// find-or-create runs inside the rule engine on a URL the rule already names. It
		// is the ENGINE that needs the source — the rule's caller never sees it. Reading
		// (and auto-creating) as the acting user would break every non-admin rule run.
		//
		// The auto-created source carries NO credentials, which is the point: an
		// engine-created source can only ever call an unauthenticated URL. Anything that
		// needs a secret must be configured by an admin and reference a broker credential.
		$matches = $this->orObjectService->findAll(
			config: ['filters' => ['register' => 'openconnector', 'schema' => 'source', 'location' => $url]],
			_rbac: false,
			_multitenancy: false
		);
		$sources = $matches['results'] ?? $matches;

		if (count($sources) > 0) {
			$source = $sources[0];
		} else {
			$source = $this->orObjectService->saveObject(
				object: [
					'location' => $url,
					'name' => basename($url),
					'type' => 'api',
					'isEnabled' => true,
				],
				register: 'openconnector',
				schema: 'source',
				_rbac: false,
				_multitenancy: false,
			);
		}

		$result = $this->callService->call($source);
		$resultData = $result->getObject();

		if (($resultData['statusCode'] ?? 0) !== 200) {
			throw new Exception(message: "The object on $url could not be fetched");
		}

		$object = json_decode(json: ($resultData['response']['body'] ?? '{}'), associative: true, flags: JSON_THROW_ON_ERROR);

		if ($configuration['extend_external_input']['validate'] === false) {
			return $object;
		}

		$validationHandler = $this->objectService->getOpenRegisters()->getValidateHandler();

		$validatedResult = $validationHandler->validateObject($object, $this->schemaMapper->find($schemaId));

		if ($validatedResult->isValid() === true) {
			return $object;
		}

		throw new ValidationException(message: 'Fetched object cannot be validated', code: 400, errors: $validatedResult->error());
	}//end getExternalObject()

	/**
	 * Extend an object with an external url.
	 *
	 * @param ObjectEntity $rule The rule to execute.
	 * @param array $data The data to extend.
	 *
	 * @return array|JSONResponse
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function extendExternalUrl(ObjectEntity $rule, array $data): array|JSONResponse {
		$ruleData = $rule->getObject();
		$config = ($ruleData['configuration'] ?? []);

		$dataDot = new Dot($data);
		$extendedParameters = new Dot();
		foreach ($config['extend_external_input']['properties'] as $property) {
			$url = $dataDot->get($property['property']);
			$propName = explode(separator: '.', string: $property['property']);
			$propName = end($propName);
			try {
				if (is_array($url) === true) {
					$extendedParameters->add(
						$property['property'],
						array_map(
							function (string $url) use ($property, $config) {
								return $this->getExternalObject(
									url: $url,
									configuration: $config,
									schemaId: $property['schema']
								);
							},
							$url
						)
					);
				}

				$extendedParameters->add(
					$property['property'],
					$this->getExternalObject(
						url: $url,
						configuration: $config,
						schemaId: $property['schema']
					)
				);
			} catch (ValidationException $exception) {
				return new JSONResponse(
					data: [
						'message' => 'Invalid Input',
						'error' => 'The object referenced in field ' . $property['property'] . ' is not valid',
						'errors' => [
							[
								'name' => $propName,
								'code' => 'invalid-resource',
								'reason' => 'The resource is not valid',
							],
						],
					],
					statusCode: 400
				);
			} catch (Exception $exception) {
				return new JSONResponse(
					data: [
						'message' => 'Invalid Input',
						'error' => $exception->getMessage(),
						'errors' => [
							[
								'name' => $propName,
								'code' => 'invalid-resource',
								'reason' => 'The resource is not valid',
							],
						],
					],
					statusCode: 400
				);
			}//end try
		}//end foreach

		$existingParams = $data['extendedParameters'] ?? [];
		$data['extendedParameters'] = array_merge($extendedParameters->all(), $existingParams);

		$data['body']['_extendedInput'] = $data['extendedParameters'];

		return $data;
	}//end extendExternalUrl()
}//end class
