<?php

/**
 * Integriq DSO Account Rights.
 *
 * Asks OpenRegister whether an account holds actions on `dso_verzoek`,
 * through `PermissionHandler::hasPermission()`. That handler is not a
 * published OpenRegister contract, so it is resolved lazily from the
 * container: integriq still loads without it, and the answer is then
 * "unknown" (null), never "allowed". See the design's "Contract gaps".
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rights check of the DSO connection's account.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
 */
class DsoAccountRights {

	/**
	 * OpenRegister's PermissionHandler, resolved lazily: not a published contract.
	 *
	 * @var string
	 */
	private const PERMISSION_HANDLER = 'OCA\OpenRegister\Service\Object\PermissionHandler';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper       $schemaMapper Resolves the `dso_verzoek` schema.
	 * @param ContainerInterface $container    Resolves OpenRegister's PermissionHandler lazily.
	 * @param LoggerInterface    $logger       Diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The rights the account lacks on `dso_verzoek`.
	 *
	 * @param string       $userId  The uid to check.
	 * @param list<string> $actions The actions needed.
	 *
	 * @return list<string>|null The missing actions (empty when all are held), or null
	 *                           when OpenRegister cannot answer the question.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function missing(string $userId, array $actions): ?array {
		$handler = $this->resolvePermissionHandler();
		if ($handler === null) {
			return null;
		}

		try {
			$schema = $this->schemaMapper->find(DsoConnection::SCHEMA_VERZOEK, [], false, false);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[DsoAccountRights] the dso_verzoek schema could not be resolved for the rights check',
				['exception' => $exception->getMessage()]
			);
			return null;
		}

		if ($schema === null) {
			return null;
		}

		$missing = [];
		foreach ($actions as $action) {
			try {
				$granted = $handler->hasPermission(schema: $schema, action: $action, userId: $userId);
			} catch (Throwable $exception) {
				$this->logger->error(
					'[DsoAccountRights] the rights check raised an exception; treating the rights as unverifiable',
					['action' => $action, 'exception' => $exception->getMessage()]
				);
				return null;
			}

			if ($granted !== true) {
				$missing[] = $action;
			}
		}

		return $missing;

	}//end missing()

	/**
	 * Resolve OpenRegister's PermissionHandler, or null when it is absent.
	 *
	 * @return object|null The handler.
	 */
	private function resolvePermissionHandler(): ?object {
		try {
			$handler = $this->container->get(self::PERMISSION_HANDLER);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[DsoAccountRights] OpenRegister PermissionHandler unavailable; the rights check cannot run',
				['exception' => $exception->getMessage()]
			);
			return null;
		}

		if (is_object($handler) === false || method_exists($handler, 'hasPermission') === false) {
			$this->logger->error('[DsoAccountRights] OpenRegister PermissionHandler has no hasPermission(); the rights check cannot run');
			return null;
		}

		return $handler;

	}//end resolvePermissionHandler()
}//end class
