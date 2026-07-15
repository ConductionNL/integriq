<?php

/**
 * OpenConnector Tables v1-REST Client.
 *
 * Concrete {@see TablesClientInterface} implementation. The class name is
 * kept from the original brief for continuity even though it targets the
 * `index.php/apps/tables/api/1/*` surface, not an OCS endpoint — see
 * design.md Decision 2 for why v1 (not the newer `ocs/v2.php/apps/tables/api/2/*`
 * surface) is the only one with authenticated single-row GET/PUT/DELETE.
 *
 * Transport is exclusively {@see \OCA\OpenConnector\Service\CallService::call()}
 * against the `Source` object configured on the synchronization — no new
 * HTTP client, no new secret storage. CallLog persistence, rate-limit
 * tracking, and brokered-credential dispatch are inherited for free.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Tables;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Exception\TablesNotFoundException;
use OCA\OpenConnector\Exception\TablesPermissionDeniedException;
use OCA\OpenConnector\Exception\TablesUpstreamException;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Speaks the Tables `index.php/apps/tables/api/1/*` REST dialect over `CallService`.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */
class TablesOcsClient implements TablesClientInterface
{

    /**
     * Base path of the Tables v1 REST surface (verified live + versioned in
     * discovery.md; NOT under `ocs/v2.php`, no OCS response envelope).
     *
     * @var string
     */
    private const BASE_PATH = '/index.php/apps/tables/api/1';

    /**
     * Constructor.
     *
     * @param CallService     $callService The shared HTTP dispatcher (CallLog, rate-limit,
     *                                     brokered-credential resolution — all inherited).
     * @param LoggerInterface $logger      Logger for upstream-failure diagnostics.
     */
    public function __construct(
        private readonly CallService $callService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List the tables accessible to the Source's configured identity.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     *
     * @return array<int, array{id: int, title: string, ownerType: string}>
     *
     * @throws TablesUpstreamException On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function listTables(ObjectEntity $source): array
    {
        $body = $this->dispatch(source: $source, endpoint: self::BASE_PATH.'/tables', method: 'GET');

        $tables = [];
        foreach ($this->asArray(value: $body) as $table) {
            if (is_array($table) === false) {
                continue;
            }

            $tables[] = [
                'id'        => (int) ($table['id'] ?? 0),
                'title'     => (string) ($table['title'] ?? ''),
                'ownerType' => (string) ($table['ownerType'] ?? 'user'),
            ];
        }

        return $tables;

    }//end listTables()

    /**
     * List a table's columns (id, title, type, subtype, mandatory, constraints).
     *
     * @param ObjectEntity $source  The `Source` whose credentials are used.
     * @param int          $tableId The Tables table id.
     *
     * @return array<int, array{id: int, title: string, type: string, subtype: ?string,
     *               mandatory: bool, constraints: array<string, mixed>}>
     *
     * @throws TablesNotFoundException         When the table does not exist upstream.
     * @throws TablesPermissionDeniedException When the identity cannot read the table's columns.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function listColumns(ObjectEntity $source, int $tableId): array
    {
        $body = $this->dispatch(
            source: $source,
            endpoint: self::BASE_PATH."/tables/{$tableId}/columns",
            method: 'GET'
        );

        $columns = [];
        foreach ($this->asArray(value: $body) as $column) {
            if (is_array($column) === false) {
                continue;
            }

            $columns[] = $this->normaliseColumn(column: $column);
        }

        return $columns;

    }//end listColumns()

    /**
     * Read one page of rows from a table (optionally scoped to a view).
     *
     * Pagination note: the Tables v1 rows endpoint's server-side pagination
     * support is not guaranteed by the published schema (discovery.md); this
     * client forwards `limit`/`offset` query parameters (Nextcloud's common
     * REST-listing convention) on a best-effort basis. `TablesSyncAdapter`'s
     * loop stays correct even if the upstream ignores them (it stops once a
     * page returns fewer than `pageSize` rows).
     *
     * @param ObjectEntity $source   The `Source` whose credentials are used.
     * @param int          $tableId  The Tables table id.
     * @param int|null     $viewId   Optional Tables view id to scope the read to.
     * @param int          $page     1-based page number.
     * @param int          $pageSize Maximum rows to return for this page.
     *
     * @return array<int, array{id: int, tableId: int, data: array<string, mixed>}>
     *
     * @throws TablesNotFoundException         When the table does not exist upstream.
     * @throws TablesPermissionDeniedException When the identity cannot read the table's rows.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function listRows(ObjectEntity $source, int $tableId, ?int $viewId, int $page, int $pageSize): array
    {
        $endpoint = self::BASE_PATH."/tables/{$tableId}/rows";
        if ($viewId !== null) {
            $endpoint = self::BASE_PATH."/views/{$viewId}/rows";
        }

        $offset = ($page - 1) * $pageSize;
        $body   = $this->dispatch(
            source: $source,
            endpoint: $endpoint,
            method: 'GET',
            config: [
                'query' => [
                    'limit'  => $pageSize,
                    'offset' => $offset,
                ],
            ]
        );

        $rows = [];
        foreach ($this->asArray(value: $body) as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $rows[] = $this->normaliseRow(row: $row);
        }

        return $rows;

    }//end listRows()

    /**
     * Read a single row by id.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>}
     *
     * @throws TablesNotFoundException         When the row does not exist upstream.
     * @throws TablesPermissionDeniedException When the identity cannot read the row.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function getRow(ObjectEntity $source, int $rowId): array
    {
        $body = $this->dispatch(source: $source, endpoint: self::BASE_PATH."/rows/{$rowId}", method: 'GET');

        return $this->normaliseRow(row: $this->asArray(value: $body));

    }//end getRow()

    /**
     * Create a new row in a table.
     *
     * @param ObjectEntity $source  The `Source` whose credentials are used.
     * @param int          $tableId The Tables table id.
     * @param array        $data    `columnId => value` payload (Tables write shape).
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>} The created row.
     *
     * @throws TablesNotFoundException         When the table does not exist upstream.
     * @throws TablesPermissionDeniedException When the identity cannot write to the table.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function createRow(ObjectEntity $source, int $tableId, array $data): array
    {
        $body = $this->dispatch(
            source: $source,
            endpoint: self::BASE_PATH."/tables/{$tableId}/rows",
            method: 'POST',
            config: ['json' => ['data' => $data]]
        );

        return $this->normaliseRow(row: $this->asArray(value: $body));

    }//end createRow()

    /**
     * Update an existing row.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     * @param array        $data   `columnId => value` payload (Tables write shape).
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>} The updated row.
     *
     * @throws TablesNotFoundException         When the row does not exist upstream.
     * @throws TablesPermissionDeniedException When the identity cannot write to the row.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function updateRow(ObjectEntity $source, int $rowId, array $data): array
    {
        $body = $this->dispatch(
            source: $source,
            endpoint: self::BASE_PATH."/rows/{$rowId}",
            method: 'PUT',
            config: ['json' => ['data' => $data]]
        );

        return $this->normaliseRow(row: $this->asArray(value: $body));

    }//end updateRow()

    /**
     * Delete a row.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     *
     * @return void
     *
     * @throws TablesNotFoundException         When the row does not exist upstream (tolerated by callers).
     * @throws TablesPermissionDeniedException When the identity cannot delete the row.
     * @throws TablesUpstreamException         On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function deleteRow(ObjectEntity $source, int $rowId): void
    {
        $this->dispatch(source: $source, endpoint: self::BASE_PATH."/rows/{$rowId}", method: 'DELETE');

    }//end deleteRow()

    /**
     * Dispatch a call through `CallService::call()` and decode/classify the response.
     *
     * @param ObjectEntity $source   The Source to call.
     * @param string       $endpoint The Tables endpoint (already includes {@see BASE_PATH}).
     * @param string       $method   HTTP method.
     * @param array        $config   Extra Guzzle-shaped config (headers/query/json).
     *
     * @return array|null The JSON-decoded response body, or null for an empty/204 body.
     *
     * @throws TablesNotFoundException         On an upstream 404.
     * @throws TablesPermissionDeniedException On an upstream 401/403.
     * @throws TablesUpstreamException         On transport failure or another non-2xx status.
     */
    private function dispatch(ObjectEntity $source, string $endpoint, string $method, array $config=[]): ?array
    {
        try {
            $callLog = $this->callService->call(source: $source, endpoint: $endpoint, method: $method, config: $config);
        } catch (GuzzleException | LoaderError | SyntaxError | \OCP\DB\Exception $exception) {
            $this->logger->warning(
                'TablesOcsClient: transport failure calling Tables API',
                ['endpoint' => $endpoint, 'method' => $method, 'error' => $exception->getMessage()]
            );
            throw new TablesUpstreamException(
                message: "Failed to reach the Tables API ({$method} {$endpoint}): ".$exception->getMessage()
            );
        }

        $callLogBody = $callLog->getObject();
        $statusCode  = (int) ($callLogBody['response']['statusCode'] ?? 0);
        $rawBody     = (string) ($callLogBody['response']['body'] ?? '');

        if ($statusCode === 401 || $statusCode === 403) {
            throw new TablesPermissionDeniedException(
                message: "Tables API denied {$method} {$endpoint} (HTTP {$statusCode})",
                statusCode: $statusCode
            );
        }

        if ($statusCode === 404) {
            throw new TablesNotFoundException(message: "Tables API resource not found: {$method} {$endpoint}");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new TablesUpstreamException(
                message: "Tables API returned HTTP {$statusCode} for {$method} {$endpoint} (see CallLog {$callLog->getUuid()})"
            );
        }

        if ($rawBody === '') {
            return null;
        }

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return null;

    }//end dispatch()

    /**
     * Coerce a value to an array, treating any non-array as empty.
     *
     * @param mixed $value The value to coerce.
     *
     * @return array
     */
    private function asArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [];

    }//end asArray()

    /**
     * Normalise a raw Tables `Column` payload into the contract.md editor shape.
     *
     * @param array $column Raw Tables Column object.
     *
     * @return array{id: int, title: string, type: string, subtype: ?string,
     *               mandatory: bool, constraints: array<string, mixed>}
     */
    private function normaliseColumn(array $column): array
    {
        $constraintKeys = [
            'numberDefault',
            'numberMin',
            'numberMax',
            'numberDecimals',
            'numberPrefix',
            'numberSuffix',
            'textDefault',
            'textAllowedPattern',
            'textMaxLength',
            'textUnique',
            'selectionOptions',
            'selectionDefault',
            'datetimeDefault',
            'usergroupDefault',
            'usergroupMultipleItems',
            'usergroupSelectUsers',
            'usergroupSelectGroups',
            'usergroupSelectTeams',
        ];

        $constraints = [];
        foreach ($constraintKeys as $key) {
            if (isset($column[$key]) === true) {
                $constraints[$key] = $column[$key];
            }
        }

        $subtype = null;
        if (isset($column['subtype']) === true) {
            $subtype = (string) $column['subtype'];
        }

        return [
            'id'          => (int) ($column['id'] ?? 0),
            'title'       => (string) ($column['title'] ?? ''),
            'type'        => (string) ($column['type'] ?? ''),
            'subtype'     => $subtype,
            'mandatory'   => (bool) ($column['mandatory'] ?? false),
            'constraints' => $constraints,
        ];

    }//end normaliseColumn()

    /**
     * Normalise a raw Tables `Row` payload into the shape this client's callers expect.
     *
     * @param array $row Raw Tables Row object.
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id'      => (int) ($row['id'] ?? 0),
            'tableId' => (int) ($row['tableId'] ?? 0),
            'data'    => $this->asArray(value: ($row['data'] ?? null)),
        ];

    }//end normaliseRow()
}//end class
