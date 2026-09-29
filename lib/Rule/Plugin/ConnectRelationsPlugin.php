<?php

/**
 * Integriq connectRelations rule plug-in.
 *
 * @category Rule
 * @package  OCA\Integriq\Rule\Plugin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Rule\Plugin;

use OCA\Integriq\Service\SoftwareCatalogueService;
use OCP\AppFramework\Http\JSONResponse;
use Symfony\Component\Uid\Uuid;

/**
 * The `connectRelations` custom rule, unchanged, as the first registered
 * plug-in: when the request path ends in a model uuid, it extends that model's
 * views through the software catalogue service.
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */
class ConnectRelationsPlugin implements EndpointRulePluginInterface {
	public const ID = 'connectRelations';

	/**
	 * Constructor.
	 *
	 * @param SoftwareCatalogueService $catalogueService Extends the model's views.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function __construct(
		private readonly SoftwareCatalogueService $catalogueService,
	) {
	}//end __construct()

	/**
	 * The plug-in id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function pluginId(): string {
		return self::ID;
	}//end pluginId()

	/**
	 * Extend the model named by the last path segment.
	 *
	 * @param array $rule The rule object.
	 * @param array $data The pipeline data.
	 *
	 * @return array|JSONResponse A response saying whether the views were connected.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::isValid is Symfony's static validator; there is no instance API.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function process(array $rule, array $data): array|JSONResponse {
		$explodedPath = explode(separator: '/', string: (string)($data['path'] ?? ''));
		$modelId = end($explodedPath);

		if (is_string($modelId) === true && Uuid::isValid($modelId) === true) {
			$this->catalogueService->extendModel($modelId);

			return new JSONResponse(['message' => 'Connected views succesfully'], statusCode: 200);
		}

		return new JSONResponse(['message' => 'model id was not provided'], 200);
	}//end process()
}//end class
