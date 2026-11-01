<?php

/**
 * Integriq MappingsController.
 *
 * Controller for mapping pages, mapping execution tests, and persistence helpers
 * exposed to the Integriq frontend.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Controller;

use Exception;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for mapping execution tests and persistence helpers.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class MappingsController extends Controller {

	/**
	 * The CANONICAL slug of this app's own register, which is not what to write with.
	 *
	 * It is the name asked ABOUT. What to write with comes back from
	 * {@see RegisterSlugResolverInterface}, because this app's repair step
	 * renames the register from `openconnector` per instance and both names are
	 * live across the estate. See {@see \OCA\Integriq\Repair\MigrateRegisterSlug},
	 * which is the authority for that rename and the only file in this app
	 * entitled to name the old slug.
	 *
	 * @var string
	 */
	private const SELF_REGISTER = 'integriq';

	/**
	 * Constructor for the MappingsController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param MappingServiceInterface $mappingService The mapping service.
	 * @param SourceMappingServiceInterface $objectService The object service (OC).
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param ContainerInterface $container App container OpenRegister's mappers are resolved from.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly MappingServiceInterface $mappingService,
		private readonly SourceMappingServiceInterface $objectService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Tests a mapping.
	 *
	 * This method tests a mapping with provided input data and optional schema validation.
	 *
	 * @param SourceMappingServiceInterface $objectService Source mapping service used to access OpenRegister.
	 *
	 * @return JSONResponse A JSON response containing the test results.
	 *
	 * @throws ContainerExceptionInterface Container resolution failure.
	 * @throws NotFoundExceptionInterface Container lookup miss.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 *
	 * @example
	 * Request:
	 * {
	 *     "inputObject": "{\"name\":\"John Doe\",\"age\":30,\"email\":\"john@example.com\"}",
	 *     "mapping": {
	 *            "mapping": {
	 *                "fullName":"{{name}}",
	 *                "userAge":"{{age}}",
	 *                "contactEmail":"{{email}}"
	 *            }
	 *       },
	 *     "schema": "user_schema_id",
	 *     "validation": true
	 * }
	 *
	 * Response:
	 * {
	 *     "resultObject": {
	 *         "fullName": "John Doe",
	 *         "userAge": 30,
	 *         "contactEmail": "john@example.com"
	 *     },
	 *     "isValid": true,
	 *     "validationErrors": []
	 * }
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(SourceMappingServiceInterface $objectService): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'mapping.test');

		$openRegisters = $objectService->getOpenRegisters();

		// Get all parameters from the request.
		$data = $this->request->getParams();

		// Validate that required parameters are present. Missing params are a client
		// error, so return a clean 400 rather than letting an exception escape as a 500.
		if (isset($data['inputObject']) === false || isset($data['mapping']) === false) {
			return new JSONResponse(
				data: [
					'error' => $this->l->t('Bad request'),
					'message' => $this->l->t('Both `inputObject` and `mapping` are required'),
				],
				statusCode: 400
			);
		}

		$schema = $this->resolveTestSchema(openRegisters: $openRegisters, schemaId: ($data['schema'] ?? null));
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		// MappingService::executeMapping() accepts a polymorphic payload and
		// hydrates arrays into a Mapping value object itself.
		$mappingPayload = $data['mapping'];
		if (is_array($mappingPayload) === false) {
			$mappingPayload = ['mapping' => $mappingPayload];
		}

		try {
			$resultObject = $this->mappingService->executeMapping(mapping: $mappingPayload, input: $data['inputObject']);
		} catch (Exception $e) {
			return new JSONResponse(
				[
					'error' => $this->l->t('Mapping error'),
					'message' => $e->getMessage(),
				],
				400
			);
		}

		$report = ['isValid' => true, 'validationErrors' => []];

		// A resolved schema implies OpenRegister is installed (412 above otherwise).
		if ($schema !== false && empty($data['validation']) === false) {
			// Validation lives on the ValidateObject handler; passing the Schema
			// entity lets it derive the schema object and run the unique-field
			// and extended-type checks too.
			$report = $this->validationReport(
				result: $openRegisters->getValidateHandler()->validateObject(object: $resultObject, schema: $schema)
			);
		}

		return new JSONResponse(['resultObject' => $resultObject] + $report);

	}//end test()

	/**
	 * Resolve the schema a mapping test validates against.
	 *
	 * Resolved via setSchema(), not getMapper('schema'): getMapper()'s first
	 * parameter is a register, so it searched every register for an object with
	 * the schema id. setSchema() resolves numeric ids, uuids and slugs and
	 * rethrows DoesNotExistException.
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService|null $openRegisters OpenRegister's object service, null when not installed.
	 * @param mixed                                          $schemaId      The requested schema, empty for none.
	 *
	 * @return object|false The schema entity, false when none was requested, or the error response.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function resolveTestSchema(?\OCA\OpenRegister\Service\ObjectService $openRegisters, mixed $schemaId): object|false {
		if (empty($schemaId) === true) {
			return false;
		}

		if ($openRegisters === null) {
			return new JSONResponse(
				data: [
					'error' => $this->l->t('Setup error'),
					'message' => $this->l->t('OpenRegisters must be installed to validate schema.'),
				],
				statusCode: 412
			);
		}

		try {
			$schema = $openRegisters->setSchema($schemaId)->getCurrentSchemaEntity();
		} catch (DoesNotExistException $exception) {
			$schema = null;
		}

		if ($schema === null) {
			return new JSONResponse(
				data: [
					'error' => $this->l->t('Not found'),
					'message' => $this->l->t('The specified schema could not be found.'),
				],
				statusCode: 404
			);
		}

		return $schema;
	}//end resolveTestSchema()

	/**
	 * Turn a schema validation result into the test response's validity fields.
	 *
	 * @param object $result The validation result (Opis ValidationResult).
	 *
	 * @return array{isValid: bool, validationErrors: array} The validity fields.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function validationReport(object $result): array {
		$validationErrors = [];
		if ($result->hasError() === true) {
			// Class imported without use because it only exists when OpenRegisters is installed.
			$validationErrors = (new \Opis\JsonSchema\Errors\ErrorFormatter())->format(error: $result->error());
		}

		return ['isValid' => $result->isValid(), 'validationErrors' => $validationErrors];
	}//end validationReport()

	/**
	 * Saves a mapping object.
	 *
	 * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
	 *
	 * @return JSONResponse|null The saved mapping JSON or an error response, null when no error.
	 *
	 * @throws ContainerExceptionInterface Container resolution failure.
	 * @throws NotFoundExceptionInterface Container lookup miss.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function saveObject(): ?JSONResponse {
		// Check if the OpenRegister service is available.
		$openRegisters = $this->objectService->getOpenRegisters();
		if ($openRegisters === null) {
			return new JSONResponse(['error' => $this->l->t('OpenRegister is not installed')], 412);
		}

		$data = $this->request->getParams();
		if (isset($data['object']) === false) {
			return new JSONResponse(['error' => $this->l->t('Missing required `object` field')], 400);
		}

		// 🔴 THE MAPPING RESULT MAY NOT ADDRESS AN EXISTING OBJECT.
		//
		// This endpoint saves the OUTPUT OF A MAPPING TEST as a new object —
		// the UI button is "save result as object". A mapping transforms source
		// data, and source data very often carries an `id`. `saveObject()`
		// resolves its target from the payload (`@self.id` first, then `id`)
		// and the write is PUT-semantic, so a result carrying either would
		// silently REPLACE whatever object shares that identifier, nulling
		// every field the result omitted, and report success.
		//
		// Identity here belongs to the new object, not to the source record the
		// mapping happened to read.
		$object = (array)$data['object'];
		unset($object['id'], $object['uuid'], $object['@self']);

		// 🔴 THE DEFAULT REGISTER IS A QUESTION, NOT A LITERAL.
		//
		// This used to read `($data['register'] ?? 'openconnector')`. The app's
		// own register was renamed to `integriq` by
		// {@see \OCA\Integriq\Repair\MigrateRegisterSlug}, and that step runs
		// per instance, so both slugs are live across the estate. On an instance
		// that has run it, every request that did not name a register wrote into
		// a register that is not there. OpenRegister does not raise for that: the
		// row is absent, so the write lands nowhere the UI will read back, and
		// the response is a 200 carrying an object nobody can find again.
		//
		// A caller-supplied register is used as given. Those come from the
		// register picker, whose options are live `openregister_registers` rows
		// fetched by getObjects() below, so they already carry this instance's
		// actual slug and resolving them a second time would only add a way to
		// be wrong.
		$register = trim((string)($data['register'] ?? ''));
		if ($register === '') {
			$resolution = $this->resolveOwnRegister();
			if ($resolution === null || $resolution->isResolved() === false) {
				// 409, not 404: the ROUTE is fine and the mapping result is
				// fine. What is missing is this instance's register, which an
				// admin fixes by running the repair step or provisioning the
				// register. Returning the empty success this branch used to
				// produce is the defect itself.
				return new JSONResponse(
					[
						'error' => $this->l->t(
							'This instance has no Integriq register, so there is nowhere to save the result. '
							. 'Run the Integriq repair step, or name a register explicitly in the request.'
						),
					],
					409
				);
			}

			$register = $resolution->slug;
		}//end if

		// OR's ObjectService::saveObject signature is `(object, register?,
		// schema?)`. Prior code passed the register slug as the first arg
		// — a TypeError under the new signature, which surfaced as 500.
		$saved = $openRegisters->saveObject(
			object:   $object,
			register: $register,
			schema:   ($data['schema'] ?? 'mapping')
		);

		return new JSONResponse($saved->getObject());
	}//end saveObject()

	/**
	 * Ask this instance which slug its Integriq register answers to.
	 *
	 * Resolved from the container rather than injected, for the same reason
	 * getObjects() resolves RegisterMapper that way: OpenRegister is an optional
	 * dependency, and a constructor argument typed to one of its classes makes
	 * every route on this controller fail to build on an instance without it,
	 * including the routes that never touch a register. The one caller has
	 * already established that OpenRegister is present before it asks.
	 *
	 * @return RegisterSlugResolution|null The resolution, or null when the
	 *                                     resolver itself could not be resolved,
	 *                                     which is an OpenRegister too old to
	 *                                     publish the contract. Handled like an
	 *                                     absent register by the caller, because
	 *                                     in both cases this instance cannot say
	 *                                     where the write should go.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	private function resolveOwnRegister(): ?RegisterSlugResolution {
		try {
			$resolver = $this->container->get(RegisterSlugResolverInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[MappingsController] could not resolve RegisterSlugResolverInterface, so the default register '
				. 'cannot be determined: ' . $e->getMessage(),
				['exception' => $e]
			);

			return null;
		}

		return $resolver->resolve(canonical: self::SELF_REGISTER);
	}//end resolveOwnRegister()

	/**
	 * Retrieves a list of objects to map to.
	 *
	 * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
	 *
	 * @return JSONResponse
	 *
	 * @throws ContainerExceptionInterface Container resolution failure.
	 * @throws NotFoundExceptionInterface Container lookup miss.
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getObjects(): JSONResponse {
		// Check if the OpenRegister service is available.
		$openRegisters = $this->objectService->getOpenRegisters();
		$data = [];
		$data['openRegisters'] = false;
		if ($openRegisters !== null) {
			$data['openRegisters'] = true;
			// OpenRegister's ObjectService no longer exposes getRegisters();
			// fetch the register list via the mapper directly.
			try {
				$registerMapper = $this->container->get(RegisterMapper::class);
				$data['availableRegisters'] = $registerMapper->findAll();
			} catch (\Throwable $e) {
				$this->logger->warning(
					'[MappingsController] could not resolve RegisterMapper, returning empty register list: ' . $e->getMessage(),
					['exception' => $e]
				);
				$data['availableRegisters'] = [];
			}
		}

		return new JSONResponse($data);
	}//end getObjects()
}//end class
