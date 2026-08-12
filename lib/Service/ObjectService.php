<?php

/**
 * OpenConnector ObjectService — deprecated alias.
 *
 * This class is a one-minor-version compatibility shim.  All logic now lives in
 * SourceMappingService.  Instantiating this class fires an E_USER_DEPRECATED
 * notice pointing consumers to the new name.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @deprecated Use SourceMappingService instead.
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7
 */

namespace OCA\OpenConnector\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Deprecated alias for SourceMappingService.
 *
 * @deprecated 1.x Use OCA\OpenConnector\Service\SourceMappingService.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ObjectService extends SourceMappingService {
	/**
	 * Constructor — fires E_USER_DEPRECATED on instantiation.
	 *
	 * @param IAppManager $appManager App manager (forwarded to parent).
	 * @param ContainerInterface $container DI container (forwarded to parent).
	 *
	 * @deprecated 1.x Use SourceMappingService::__construct() directly.
	 */
	public function __construct(
		IAppManager $appManager,
		ContainerInterface $container,
	) {
		trigger_error(
			'OCA\OpenConnector\Service\ObjectService has been renamed to SourceMappingService. '
			. 'Update your code to use SourceMappingService before the next major release.',
			E_USER_DEPRECATED
		);

		parent::__construct(appManager: $appManager, container: $container);

	}//end __construct()
}//end class
