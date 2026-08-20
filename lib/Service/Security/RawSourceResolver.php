<?php

/**
 * OpenConnector Raw Source Resolver.
 *
 * Re-resolves a `source` ObjectEntity RAW (`_render: false`) so the direct-Guzzle
 * clients that bypass {@see \OCA\OpenConnector\Service\CallService} keep seeing the
 * credential material an operator authored under `configuration.authentication.*`.
 *
 * WHY THIS CLASS EXISTS (ocon#242, the follow-up to ocon#241 / openregister#459):
 * OpenRegister strips every `x-openregister-writeonly-paths` dot-path on EVERY
 * RENDERED read — unconditionally, admins included, list/search included
 * (openregister#389/#460/#462), plus the `@self.relations` mirror (openregister#429).
 * The strip is SCHEMA-gated, NOT rbac-gated: `_rbac: false` does NOT bring the
 * secret back. `_render: false` is the ONLY read that survives the boundary — the
 * lesson ocon#212 learned the hard way when its first fix used `_rbac: false` and
 * webhooks still went out unsigned until ocon#226.
 *
 * `ObjectService::findAll()` HAS NO `_render` PARAMETER — it renders
 * UNCONDITIONALLY via `renderEntities()`. There is therefore no such thing as a
 * "raw findAll()": a source located by filter is ALWAYS rendered, and its secrets
 * are ALWAYS stripped. Only `ObjectService::find()` takes `_render`. So the
 * six `*SyncService::resolveActiveSource()` methods must keep using `findAll()` to
 * LOCATE the active source, then re-read that one uuid RAW through `find()`.
 * That is exactly the shape {@see \OCA\OpenConnector\Service\CallService::resolveSourceForDispatch()}
 * (ocon#236) already has, and this class is the shared form of it for the clients
 * that never reach CallService.
 *
 * THIS CLASS DOES NOT WIDEN ACCESS. The re-read deliberately keeps `_rbac: true` /
 * `_multitenancy: true` — the caller's `findAll()` already located the source under
 * those same semantics, so re-reading it by uuid under them is access-neutral.
 * Contrast {@see \OCA\OpenConnector\Service\CallService::resolveSourceForDispatch()}
 * and {@see \OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner::readRawSource()},
 * which pass `_rbac: false` because they run in engine / migration contexts that
 * legitimately have no user. ONLY the render mode changes here.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-resolves a rendered `source` entity to its raw, secret-bearing form.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 */
class RawSourceResolver {

	/**
	 * The register the `source` schema lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * The schema slug re-read raw.
	 *
	 * @var string
	 */
	public const SCHEMA = 'source';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger The logger (never receives a secret VALUE).
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Re-read a located source RAW so its write-only credentials survive.
	 *
	 * Fallbacks NEVER throw — a client that dispatched before this hardening must
	 * still dispatch after it. On any miss the passed (rendered) entity is
	 * returned, which is exactly the pre-ocon#242 status quo for that source; the
	 * client's own fail-closed credential check then reports the real problem.
	 *
	 * @param ObjectEntity $source The source as located by a rendered `findAll()`.
	 *
	 * @return ObjectEntity The raw source (secrets intact), or the passed entity on any fallback.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function resolveRaw(ObjectEntity $source): ObjectEntity {
		$uuid = $source->getUuid();

		// Unpersisted / in-memory source (a test fixture, a probe): nothing to re-read.
		if (empty($uuid) === true) {
			return $source;
		}

		try {
			$raw = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: true,
				_multitenancy: true,
				_render: false
			);
		} catch (Throwable $exception) {
			// Secret-free log: the uuid is not a secret; the message is not
			// interpolated in case an upstream ever quotes object data.
			$this->logger->warning(
				'[openconnector] raw source re-resolve failed; using the rendered entity',
				[
					'sourceUuid' => $uuid,
					'errorClass' => get_class($exception),
				]
			);
			return $source;
		}//end try

		if ($raw instanceof ObjectEntity === false) {
			return $source;
		}

		return $raw;
	}//end resolveRaw()
}//end class
