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
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistryInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCP\AppFramework\Http\JSONResponse;

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
	/**
	 * Constructor for RuleService.
	 *
	 * @param ObjectService $objectService Integriq object-service facade.
	 * @param RegisterMapper $registerMapper Mapper used to resolve register IDs.
	 * @param SchemaMapper $schemaMapper Mapper used to resolve schema IDs.
	 * @param CallService $callService Service used for outbound HTTP calls during rule evaluation.
	 * @param ORObjectService $orObjectService OpenRegister object-service used by extend / save rules.
	 * @param EndpointRulePluginRegistryInterface $pluginRegistry The rule plug-ins a `custom` rule names.
	 * @param RegisterResolverService|null $registerResolver Resolves `<context>_property` config keys to property
	 *                                                       identifiers; nullable so unit tests that don't
	 *                                                       exercise the catalogue rule path can omit the
	 *                                                       dependency.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly CallService $callService,
		private readonly ORObjectService $orObjectService,
		private readonly EndpointRulePluginRegistryInterface $pluginRegistry,
		private readonly ?RegisterResolverService $registerResolver = null,
	) {
	}//end __construct()

	/**
	 * Process a custom rule: run the plug-in named by `configuration.plugin`.
	 *
	 * A rule written before plug-ins named its sub-type in `configuration.type`
	 * (`connectRelations`), so that key is read when `plugin` is absent.
	 * `connectRelations` is now the first registered plug-in and runs as before.
	 *
	 * `softwareCatalogus` was removed earlier. It was a rule type hard-coded to
	 * one consumer's domain model, living in the connector's shared rule
	 * pipeline. Logic like that now belongs in a sibling app's plug-in, or in a
	 * flow composed of generic steps.
	 *
	 * @param ObjectEntity $rule The rule to process.
	 * @param array $data The data to process.
	 *
	 * @return array|JSONResponse The updated data array (or a JSONResponse if the rule short-circuits).
	 *
	 * @throws Exception When no plug-in answers to the named id.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function processCustomRule(ObjectEntity $rule, array $data): array|JSONResponse {
		$ruleData = $rule->getObject();
		$configuration = ($ruleData['configuration'] ?? []);
		$pluginId = (string)($configuration['plugin'] ?? $configuration['type'] ?? '');

		$plugin = $this->pluginRegistry->pluginFor(pluginId: $pluginId);
		if ($plugin === null) {
			$installed = implode(', ', $this->pluginRegistry->ids());
			if ($installed === '') {
				$installed = 'none';
			}

			throw new Exception(
				sprintf("No rule plug-in '%s' is installed. Installed plug-ins: %s.", $pluginId, $installed)
			);
		}

		return $plugin->process(rule: $ruleData, data: $data);
	}//end processCustomRule()

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
			config: ['filters' => ['register' => 'integriq', 'schema' => 'source', 'location' => $url]],
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
				register: 'integriq',
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
