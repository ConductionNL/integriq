<?php

/**
 * OpenConnector — flag the source schema's credential fields write-only.
 *
 * Phase 2 of ocon#147 (openregister#380). The register fragment marks apikey/secret/
 * password/jwt/authenticationConfig `writeOnly: true` so OpenRegister's render boundary
 * strips them from every API response — but OR's descriptor import only applies a
 * schema's properties when it CREATES the schema; on an upgrade the source schema already
 * exists and its properties are left untouched (so a runtime UI edit is never clobbered).
 *
 * So on a fresh install the fragment is enough, and on an existing install this repair
 * step patches the same flags directly onto the live schema. Idempotent — it only writes
 * when a flag is actually missing — and a no-op when OpenRegister is absent or the source
 * schema has not been created yet (InitializeRegister runs first and creates it).
 *
 * The engine is unaffected either way: it reads a source's credential via getObject() in
 * system context, which the redaction never touches.
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retro-flags the source schema's credential fields write-only on existing installs.
 */
class FlagSourceSecretsWriteOnly implements IRepairStep {
	/**
	 * OR's SchemaMapper, resolved lazily so OpenConnector still boots without OpenRegister.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The credential fields that must never be returned by the API.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (to resolve OR lazily).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Flag the OpenConnector source schema credential fields as write-only';
	}//end getName()

	/**
	 * Patch the write-only flags onto the live source schema.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\' . self::SCHEMA_MAPPER) === false) {
			$output->info('OpenConnector: OpenRegister not available; skipping source-secret write-only flagging.');
			return;
		}

		try {
			$schemaMapper = $this->container->get(self::SCHEMA_MAPPER);

			// The source schema is created by InitializeRegister (which runs first). Resolve
			// it by slug; if it is not there yet, there is nothing to flag.
			$schema = $schemaMapper->find('source');
			$properties = $schema->getProperties();
			if (is_array($properties) === false) {
				return;
			}

			$changed = false;
			foreach (self::SECRET_FIELDS as $field) {
				if (isset($properties[$field]) === false || is_array($properties[$field]) === false) {
					continue;
				}

				if (($properties[$field]['writeOnly'] ?? false) !== true) {
					$properties[$field]['writeOnly'] = true;

					$changed = true;
				}
			}

			if ($changed === false) {
				$output->info('OpenConnector: source-secret write-only flags already set.');
				return;
			}

			$schema->setProperties($properties);
			$schemaMapper->update($schema);

			$output->info('OpenConnector: flagged source credential fields (apikey/secret/password/jwt/authenticationConfig) write-only.');
		} catch (Throwable $e) {
			// Never fatal: leaving the flags unset is the pre-existing state. But it is a
			// security control, so say so loudly.
			$output->warning('OpenConnector: could not flag source secrets write-only: ' . $e->getMessage());
			$this->logger->error(
				'OpenConnector: FlagSourceSecretsWriteOnly failed; source credentials may still be returned by the API',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()
}//end class
