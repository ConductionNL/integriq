<?php

/**
 * Schema discovery and statement test runs for a database source.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\DatabaseAdapter;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The source page's database panel: the tables and columns, and a test run
 * of a declared read statement that shows the first page and its duration.
 *
 * Both are for administrators only. A test run reads; a write statement is
 * never run from here.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
class DatabaseSourceController extends Controller {

	/**
	 * The register the sources live in.
	 */
	private const REGISTER = 'integriq';

	/**
	 * The source schema.
	 */
	private const SCHEMA = 'source';

	/**
	 * The most rows a test run returns.
	 */
	private const TEST_ROWS = 50;

	/**
	 * Constructor.
	 *
	 * @param string          $appName       The app name.
	 * @param IRequest        $request       The request.
	 * @param ORObjectService $objectService OpenRegister's object service.
	 * @param DatabaseAdapter $adapter       The database adapter.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ORObjectService $objectService,
		private readonly DatabaseAdapter $adapter,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The tables and views of the source's database, with their columns.
	 *
	 * @param string $id The source.
	 *
	 * @return JSONResponse `{tables, durationMs}`, or `{error}`.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function schema(string $id): JSONResponse {
		return $this->answer(
			id: $id,
			work: fn (array $source): array => $this->adapter->discover(source: $source)
		);
	}//end schema()

	/**
	 * Run a declared read statement once and show its first page.
	 *
	 * @param string $id   The source.
	 * @param string $name The statement.
	 *
	 * @return JSONResponse `{statement, rows, rowCount, more, durationMs}`, or `{error}`.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#scenario-an-administrator-connects-a-back-office-database
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function testStatement(string $id, string $name): JSONResponse {
		$parameters = $this->request->getParam('parameters', []);
		if (is_array($parameters) === false) {
			$parameters = [];
		}

		return $this->answer(
			id: $id,
			work: function (array $source) use ($name, $parameters): array {
				$page = $this->adapter->read(source: $source, statement: $name, parameters: $parameters, after: null, limit: self::TEST_ROWS);

				return [
					'statement' => $name,
					'rows' => $page['rows'],
					'rowCount' => count($page['rows']),
					'more' => ($page['next'] !== null || count($page['rows']) >= self::TEST_ROWS),
					'durationMs' => $page['durationMs'],
				];
			}
		);
	}//end testStatement()

	/**
	 * Load the database source and run the work on it.
	 *
	 * @param string   $id   The source.
	 * @param callable $work The work, given the source object.
	 *
	 * @return JSONResponse 200 with the work's answer, 404 without the source,
	 *                      400 when the adapter refuses, 500 on anything else.
	 */
	private function answer(string $id, callable $work): JSONResponse {
		$entity = $this->objectService->find(
			id: $id,
			options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA)
		);
		if ($entity === null) {
			return new JSONResponse(['error' => 'No such source.'], Http::STATUS_NOT_FOUND);
		}

		$source = $entity->getObject();
		if ((string) ($source['type'] ?? '') !== DatabaseAdapter::SOURCE_TYPE) {
			return new JSONResponse(['error' => 'This source is not a database source.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($work($source));
		} catch (DatabaseSourceException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $exception) {
			$this->logger->error('[integriq] the database source could not be read: ' . $exception->getMessage(), ['exception' => $exception]);

			return new JSONResponse(['error' => 'The database source could not be read.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end answer()
}//end class
