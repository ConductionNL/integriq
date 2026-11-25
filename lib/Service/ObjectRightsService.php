<?php

/**
 * ObjectRightsService
 *
 * Writes the object rights of the action matrix onto the configuration
 * schemas. The matrix holds `object.<schema>.<verb>` rows for the nine
 * configuration schemas; this service turns each row into the schema's
 * OpenRegister `authorization` entry for that verb, which OpenRegister
 * enforces on its own object API. Integriq adds no second check.
 *
 * A row still at its seeded value means "as the register declares it": the
 * three locked schemas (source, rule, consumer) get `["admin"]`, the six
 * others lose the verb, which is OpenRegister's default for a schema without
 * a block. So applying an untouched matrix writes what is already in force.
 * A row an administrator changed is written as its groups, always with
 * `admin`.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Kernel\AppIdentity;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the matrix's `object.<schema>.<verb>` rows to the schemas' authorization blocks.
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */
class ObjectRightsService implements ObjectRightsServiceInterface {
	/**
	 * The configuration schemas whose object rights are on the screen.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = [
		'source',
		'mapping',
		'synchronization',
		'job',
		'endpoint',
		'rule',
		'consumer',
		'event_subscription',
		'api_product',
	];

	/**
	 * The verbs of an object right, as OpenRegister names them.
	 *
	 * @var array<int, string>
	 */
	public const VERBS = ['create', 'read', 'update', 'delete'];

	/**
	 * OpenRegister's reserved group for every signed-in user.
	 *
	 * @var string
	 */
	public const EVERY_SIGNED_IN_USER = 'authenticated';

	/**
	 * OpenRegister's schema mapper, resolved lazily so Integriq boots without OpenRegister.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The action seed, whose `object.*` values are the register's own posture.
	 *
	 * @var string
	 */
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (to resolve OpenRegister lazily).
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the matrix's object rights to every configuration schema it has rows for.
	 *
	 * A schema whose block already says what the matrix says is not written.
	 * A schema that cannot be found or whose update is refused is logged and
	 * named in the result; the others are still applied.
	 *
	 * @param array<string, array<int, string>> $matrix The stored action matrix.
	 *
	 * @return array<int, string> The slugs of the schemas whose update did not go through.
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	public function apply(array $matrix): array {
		$grants = $this->grantsPerSchema(matrix: $matrix);
		if ($grants === []) {
			return [];
		}

		try {
			$mapper = $this->container->get(self::SCHEMA_MAPPER);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ObjectRightsService] OpenRegister is not available; object rights not applied: ' . $e->getMessage()
			);
			return array_keys($grants);
		}

		$defaults = $this->seedDefaults();
		$refused  = [];
		foreach ($grants as $slug => $verbs) {
			if ($this->applyToSchema(mapper: $mapper, slug: $slug, verbs: $verbs, defaults: $defaults) === false) {
				$refused[] = $slug;
			}
		}

		return $refused;
	}//end apply()

	/**
	 * Write one schema's block when it differs from what the matrix says.
	 *
	 * @param object                             $mapper   OpenRegister's schema mapper.
	 * @param string                             $slug     The schema slug.
	 * @param array<string, array<int, string>>  $verbs    Verb to the groups the matrix holds.
	 * @param array<string, array<int, string>>  $defaults The seeded value of every object action.
	 *
	 * @return bool False when the schema was not found or its update was refused.
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	private function applyToSchema(object $mapper, string $slug, array $verbs, array $defaults): bool {
		try {
			$schema = $mapper->findByApplicationAndSlug($slug, AppIdentity::APP_ID);
			if ($schema === null) {
				$this->logger->warning(sprintf('[ObjectRightsService] schema "%s" not found; its object rights are not applied', $slug));
				return false;
			}

			$current = $schema->getAuthorization();
			if (is_array($current) === false) {
				$current = [];
			}

			$next = self::authorizationFor(current: $current, slug: $slug, verbs: $verbs, defaults: $defaults);
			if ($next === $current) {
				return true;
			}

			$schema->setAuthorization($next);
			$mapper->update($schema);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[ObjectRightsService] applying the object rights of schema "%s" failed: %s', $slug, $e->getMessage())
			);
			return false;
		}//end try
	}//end applyToSchema()

	/**
	 * The schema's authorization block with the matrix's verbs written into it.
	 *
	 * Keys the matrix does not govern (other verbs, declared actions, flags)
	 * are kept as they are.
	 *
	 * A verb still at its "every signed-in user" seed is left out while the
	 * block holds no verb at all, so the schema keeps the register's own open
	 * posture. Once any verb is written, OpenRegister reads a verb that is
	 * missing from the block as admin only, so the untouched open verbs are
	 * then written out as `authenticated`: granting one verb to a team must not
	 * take reading away from everyone else.
	 *
	 * @param array<string, mixed>               $current  The schema's block as stored.
	 * @param string                             $slug     The schema slug.
	 * @param array<string, array<int, string>>  $verbs    Verb to the groups the matrix holds.
	 * @param array<string, array<int, string>>  $defaults The seeded value of every object action.
	 *
	 * @return array<string, mixed> The block to store.
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	public static function authorizationFor(array $current, string $slug, array $verbs, array $defaults): array {
		$next = $current;
		$open = [];
		foreach ($verbs as $verb => $groups) {
			$chosen  = self::withAdmin(groups: $groups);
			$default = self::withAdmin(groups: ($defaults['object.' . $slug . '.' . $verb] ?? ['admin']));

			if (self::sameGroups(one: $chosen, two: $default) === false) {
				$next[$verb] = $chosen;
				continue;
			}

			// Untouched: the register's own posture, unless another verb is written.
			if (in_array(self::EVERY_SIGNED_IN_USER, $default, true) === true) {
				unset($next[$verb]);
				$open[$verb] = $default;
				continue;
			}

			$next[$verb] = $default;
		}

		if (array_intersect(self::VERBS, array_keys($next)) !== []) {
			foreach ($open as $verb => $default) {
				$next[$verb] = $default;
			}
		}

		return $next;
	}//end authorizationFor()

	/**
	 * The matrix's object rows, as schema slug to verb to groups.
	 *
	 * @param array<string, array<int, string>> $matrix The stored action matrix.
	 *
	 * @return array<string, array<string, array<int, string>>>
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	private function grantsPerSchema(array $matrix): array {
		$grants = [];
		foreach (self::SCHEMAS as $slug) {
			foreach (self::VERBS as $verb) {
				$groups = ($matrix['object.' . $slug . '.' . $verb] ?? null);
				if (is_array($groups) === true) {
					$grants[$slug][$verb] = $groups;
				}
			}
		}

		return $grants;
	}//end grantsPerSchema()

	/**
	 * The groups, without duplicates or non-text entries, with `admin` first.
	 *
	 * @param array<int|string, mixed> $groups The groups.
	 *
	 * @return array<int, string>
	 */
	private static function withAdmin(array $groups): array {
		$clean = ['admin'];
		foreach ($groups as $group) {
			if (is_string($group) === true && $group !== '' && in_array($group, $clean, true) === false) {
				$clean[] = $group;
			}
		}

		return $clean;
	}//end withAdmin()

	/**
	 * Whether two group lists hold the same groups, in any order.
	 *
	 * @param array<int, string> $one The first list.
	 * @param array<int, string> $two The second list.
	 *
	 * @return bool
	 */
	private static function sameGroups(array $one, array $two): bool {
		sort($one);
		sort($two);
		return $one === $two;
	}//end sameGroups()

	/**
	 * The seeded value of every object action; empty when the seed cannot be read.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function seedDefaults(): array {
		if (is_file(self::SEED_PATH) === false) {
			return [];
		}

		$raw = file_get_contents(self::SEED_PATH);
		if ($raw === false) {
			return [];
		}

		try {
			$seed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return [];
		}

		if (is_array($seed) === false) {
			return [];
		}

		$actions = ($seed['actions'] ?? []);
		if (is_array($actions) === false) {
			return [];
		}

		$defaults = [];
		foreach ($actions as $action => $groups) {
			if (is_string($action) === true && str_starts_with($action, 'object.') === true && is_array($groups) === true) {
				$defaults[$action] = $groups;
			}
		}

		return $defaults;
	}//end seedDefaults()
}//end class
