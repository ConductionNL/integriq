<?php

/**
 * An OpenRegister ObjectService test double reproducing the NESTED write-only
 * render boundary (`x-openregister-writeonly-paths`, openregister#459).
 *
 * Sibling of {@see RenderBoundarySimulatingObjectService}, which models the
 * TOP-LEVEL `writeOnly` strip. This one models the schema-level DOT-PATH strip:
 * a declared path and its whole sub-tree vanish on EVERY rendered read —
 * unconditionally, admins included, `_rbac: false` included, list/search
 * included (openregister#389/#429/#460/#462). Only `_render: false` survives it.
 *
 * WHY A FAKE AND NOT A MOCK: a `createMock(ObjectService::class)` stubs `find()`
 * regardless of its arguments, so it returns the secret whether or not the caller
 * passed `_render: false` — the suite goes green while production dispatches
 * unauthenticated. That is exactly how this defect class kept hiding (ocon#212 →
 * #226 → #236 → #242). This double makes the ARGUMENT the thing under test:
 * forget `_render: false` and you observe no secret, precisely as in production.
 *
 * CRITICAL — `findAll()` HAS NO `_render` PARAMETER on the real ObjectService: it
 * renders UNCONDITIONALLY via `renderEntities()`. There is no "raw findAll()".
 * This double therefore ALWAYS strips in findAll(), which is what forces callers
 * to re-read the located uuid through `find(..., _render: false)`.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Helpers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Reproduces OpenRegister's nested dot-path write-only render boundary.
 */
class NestedWriteOnlyRenderBoundaryObjectService extends OrObjectService
{

    /**
     * Every read this double served: the arguments that decide the outcome.
     *
     * @var array<int, array{uuid: string, register: ?string, schema: ?string, _render: bool, _rbac: bool, _multitenancy: bool}>
     */
    public array $reads = [];

    /**
     * The raw stored objects, keyed by uuid.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $stored = [];

    /**
     * The dot-paths stripped on a rendered read (the `source` declaration under test).
     *
     * @var array<int, string>
     */
    public array $writeOnlyPaths = [];

    /**
     * Constructor — deliberately does not call the parent's.
     *
     * @param array<int, string> $writeOnlyPaths The dot-paths the schema declares write-only.
     */
    public function __construct(array $writeOnlyPaths=[])
    {
        $this->writeOnlyPaths = $writeOnlyPaths;

    }//end __construct()

    /**
     * Reproduce ObjectService::find(), including the nested render boundary.
     *
     * @param string|int  $id            The object uuid.
     * @param string|null $register      The register slug.
     * @param string|null $schema        The schema slug.
     * @param bool        $_rbac         Recorded; deliberately does NOT affect the strip.
     * @param bool        $_multitenancy Recorded; deliberately does NOT affect the strip.
     * @param bool        $_render       When true, strip the declared dot-paths.
     *
     * @return ObjectEntity|null The entity, or null when unknown.
     */
    public function find(
        $id,
        ?string $register=null,
        ?string $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_render=true
    ): ?ObjectEntity {
        $this->reads[] = [
            'uuid'          => (string) $id,
            'register'      => $register,
            'schema'        => $schema,
            '_render'       => $_render,
            '_rbac'         => $_rbac,
            '_multitenancy' => $_multitenancy,
        ];

        $data = ($this->stored[(string) $id] ?? null);
        if ($data === null) {
            return null;
        }

        if ($_render === true) {
            // The strip is SCHEMA-gated, NOT rbac-gated: $_rbac is ignored here on
            // purpose. That is the ocon#212/#226 lesson encoded as a fake.
            $data = $this->stripWriteOnlyPaths($data);
        }

        return $this->entity((string) $id, $data);

    }//end find()

    /**
     * Reproduce ObjectService::findAll() — ALWAYS rendered (it has no `_render`).
     *
     * @param array<string, mixed> $config        The find config (filters/limit).
     * @param bool                 $_rbac         Unused by the strip.
     * @param bool                 $_multitenancy Unused by the strip.
     *
     * @return array{results: array<int, ObjectEntity>, total: int}
     */
    public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        $results = [];
        foreach ($this->stored as $uuid => $data) {
            $results[] = $this->entity((string) $uuid, $this->stripWriteOnlyPaths($data));
        }

        return [
            'results' => $results,
            'total'   => count($results),
        ];

    }//end findAll()

    /**
     * Remove each declared dot-path AND its whole sub-tree.
     *
     * @param array<string, mixed> $data The raw object data.
     *
     * @return array<string, mixed> The rendered (stripped) object data.
     */
    private function stripWriteOnlyPaths(array $data): array
    {
        foreach ($this->writeOnlyPaths as $path) {
            $segments = explode('.', $path);
            $cursor   = &$data;
            $depth    = count($segments);

            for ($i = 0; $i < ($depth - 1); $i++) {
                if (is_array($cursor) === false || array_key_exists($segments[$i], $cursor) === false) {
                    continue 2;
                }

                $cursor = &$cursor[$segments[$i]];
            }

            if (is_array($cursor) === true) {
                unset($cursor[$segments[($depth - 1)]]);
            }

            unset($cursor);
        }

        return $data;

    }//end stripWriteOnlyPaths()

    /**
     * Build an ObjectEntity for a uuid + payload.
     *
     * @param string               $uuid The uuid.
     * @param array<string, mixed> $data The object payload.
     *
     * @return ObjectEntity The entity.
     */
    private function entity(string $uuid, array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($data);
        return $entity;

    }//end entity()
}//end class
