<?php

/**
 * Integriq Software Catalogue Service.
 *
 * Service for handling Software Catalogue operations. Provides functionality
 * for managing software catalogue items, including version management,
 * synchronization, view extension and event handling.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 */

namespace OCA\Integriq\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use function React\Promise\all;

/**
 * Handles Software Catalogue model/view extension and organisation/contact lifecycle events.
 */
class SoftwareCatalogueService {

	public const SUFFIX = '-sc';

	/**
	 * Software catalogue slug suffix — read from admin-config
	 * `integriq.software_catalogue.suffix` (default `-sc`).
	 *
	 * @var string
	 */
	private string $suffix;

	/**
	 * Array to store all elements from the model.
	 *
	 * @var array
	 */
	private array $elements = [];

	/**
	 * Array to store all relations from the model.
	 *
	 * @var array
	 */
	private array $relations = [];

	/**
	 * Cache of previously-extended views, indexed numerically.
	 *
	 * @var array
	 */
	private array $existingViews = [];

	/**
	 * Constructor for SoftwareCatalogueService.
	 *
	 * @param LoggerInterface $logger The logger instance.
	 * @param SourceMappingService $objectService The source mapping service for accessing OpenRegister.
	 * @param SchemaMapper $schemaMapper The schema mapper for accessing OpenRegister.
	 * @param IAppConfig $appConfig App config to read admin-tunable suffix.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openconnector-adopt-or-abstractions/tasks.md#phase-7
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SourceMappingService $objectService,
		private readonly SchemaMapper $schemaMapper,
		IAppConfig $appConfig,
	) {
		$this->suffix = $appConfig->getValueString(
			app: 'integriq',
			key: 'software_catalogue.suffix',
			default: '-sc'
		);

	}//end __construct()

	/**
	 * Extend all views for a model.
	 *
	 * @param integer|string $modelId The id of the model for which the views should be extended.
	 *
	 * @return PromiseInterface The resulting promises.
	 *
	 * @throws \Psr\Container\ContainerExceptionInterface Container failure.
	 * @throws \Psr\Container\NotFoundExceptionInterface Container miss.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function extendModel(int|string $modelId): PromiseInterface {
		// Create a deferred object to manage the promise.
		$deferred = new Deferred();

		// Get the OpenRegister service.
		$openRegister = $this->objectService->getOpenRegisters();
		if ($openRegister === null) {
			$deferred->reject(new Exception('OpenRegister service is not available'));
			return $deferred->promise();
		}

		$modelObject = $openRegister->find($modelId, register: 'vng-gemma', schema: 'model');
		$model = $modelObject->jsonSerialize();
		$views = $model['views'];

		// Set the register and schema.
		$openRegister->setRegister($modelObject->getRegister());
		$extendViewSchema = $this->schemaMapper->find('extendview');
		$openRegister->setSchema($extendViewSchema);

		// Find all views that have been extended before.
		$this->existingViews = $openRegister->findAll(
			[
				'filters' => [
					'register' => $modelObject->getRegister(),
					'schema' => $extendViewSchema->getId(),
				],
			]
		);

		// Extend all views.
		all([$views, $model])
			->then(
				function ($data) use ($deferred, $openRegister) {
					[$views, $model] = $data;

					$promises = array_map(
						function ($view) use ($model) {
							$this->extendView(viewPromise: $view, modelPromise: $model);
						},
						$views
					);

					all($promises)
						->then(
							onRejected: function ($error) use ($deferred) {
								$deferred->reject($error);
							}
						);
				}
			);

		$deferred->promise()->catch(
			function ($error) {
			}
		);

		return $deferred->promise();
	}//end extendModel()

	/**
	 * Extends a view by fetching it from the object store and processing its nodes in parallel.
	 *
	 * @param array $viewPromise The view to extend.
	 * @param array $modelPromise The model used for extension.
	 *
	 * @return PromiseInterface A promise that resolves when all nodes have been processed.
	 *
	 * @throws DoesNotExistException If the view or model does not exist in the object store.
	 *
	 * @psalm-return PromiseInterface<void>
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function extendView(array $viewPromise, array $modelPromise): PromiseInterface {
		// Create a deferred object to manage the promise.
		$deferred = new Deferred();

		// Get the OpenRegister service.
		$openRegister = $this->objectService->getOpenRegisters();
		if ($openRegister === null) {
			$deferred->reject(new Exception('OpenRegister service is not available'));
			return $deferred->promise();
		}

		// Fetch both view and model objects.
		// Lets get the extendView from the schema mapper by slug.
		$extendViewSchema = $this->schemaMapper->find('extendview');
		unset($viewPromise['@self'], $viewPromise['id']);

		$existingObjects = array_filter(
			$this->existingViews,
			function (ObjectEntity $view) use ($viewPromise) {
				return $view->jsonSerialize()['identifier'] === $viewPromise['identifier'];
			}
		);

		$id = null;

		if ($existingObjects !== []) {
			$id = array_shift($existingObjects)->getUuid();
		}

		// Lets prepare the object service for saving to the extend view.
		$openRegister->setSchema($extendViewSchema);

		$this->elements = $modelPromise['elements'];
		$this->relations = $modelPromise['relationships'];
		$nodes = $viewPromise['nodes'];
		$connections = $viewPromise['connections'];

		// Process both objects.
		all([$viewPromise, $modelPromise, $nodes, $connections])
			->then(
				function (array $results) use ($deferred, $openRegister, $id) {
					[$view, $model, $nodes, $connections] = $results;

					if ($view === null || $model === null) {
						$deferred->reject(new DoesNotExistException('View or model not found'));
						return;
					}

					// Process each node in parallel using ReactPHP.
					$promisesNodes = array_map([$this, 'extendNode'], $nodes);
					$promisesConnections = array_map([$this, 'extendConnection'], $connections);

					$promises = array_merge($promisesNodes, $promisesConnections);

					// Wait for all node processing to complete.
					all($promises)
						->then(
							function (array $results) use ($deferred, $view, $id, $model) {

								$view['model'] = ($model['id'] ?? $model['@self']['id']);
								// Update the view with the extended nodes.
								$view['nodes'] = array_values(
									array_filter(
										$results,
										function ($result) {
											return $result['type'] !== 'Relationship';
										}
									)
								);
								$view['connections'] = array_values(
									array_filter(
										$results,
										function ($result) {
											return $result['type'] === 'Relationship';
										}
									)
								);

								// Save the updated view.
								$this->objectService->getOpenRegisters()->saveObject($view, uuid: $id);
							}
						)
						->otherwise(
							function ($error) use ($deferred) {
								$deferred->reject($error);
							}
						);
				}
			)
			->otherwise(
				function ($error) use ($deferred) {
					$deferred->reject($error);
				}
			);
		$deferred->promise()->catch(
			function ($error) {
			}
		);

		return $deferred->promise();
	}//end extendView()

	/**
	 * Extends a single node using the global elements and relations.
	 *
	 * @param array $node The node to extend.
	 *
	 * @return PromiseInterface A promise that resolves with the extended node.
	 *
	 * @psalm-return PromiseInterface<array>
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function extendNode(array $node): PromiseInterface {
		return new Promise(
			function ($resolve, $reject) use ($node) {
				try {
					if (str_ends_with($node['identifier'], $this->suffix) === false) {
						$node['identifier'] = $node['identifier'] . $this->suffix;
					}

					// Find matching element for this node.
					$element = $this->findElementForNode(node: $node);
					if ($element === null) {
						$this->logger->warning('No matching element found for node', ['node' => $node]);
						$resolve($node);
						return;
					}

					// Find relations for this element.
					$relations = $this->findRelationsForElement(element: $element);

					// Extend the node with element properties.
					$node['element'] = $element;

					// Check if the node has nested nodes that need to be extended.
					if (isset($node['nodes']) === false || is_array($node['nodes']) === false) {
						$resolve($node);
						return;
					}

					// Process nested nodes in parallel.
					$nestedPromises = array_map([$this, 'extendNode'], $node['nodes']);

					all($nestedPromises)
						->then(
							function (array $extendedNestedNodes) use ($node, $resolve) {
								$node['nodes'] = $extendedNestedNodes;
								$resolve($node);
							}
						)
						->otherwise(
							function ($error) use ($reject) {
								$reject($error);
							}
						);
				} catch (\Exception $e) {
					$this->logger->error(
						'Failed to extend node: ' . $e->getMessage(),
						[
							'exception' => $e,
							'node' => $node,
						]
					);
					$reject($e);
				}//end try
			}
		);

	}//end extendNode()

	/**
	 * Extend connections in the same way as we extend nodes.
	 *
	 * @param array $connection The connection to extend.
	 *
	 * @return PromiseInterface The resulting promise.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function extendConnection(array $connection): PromiseInterface {
		return new Promise(
			function ($resolve, $reject) use ($connection) {
				try {
					if (str_ends_with($connection['identifier'], $this->suffix) === false) {
						$connection['identifier'] = $connection['identifier'] . $this->suffix;
					}

					// Find matching element for this node.
					$relationship = $this->findRelationForConnection(connection: $connection);
					if ($relationship === null) {
						$this->logger->warning('No matching element found for node', ['node' => $connection]);
						$resolve($connection);
						return;
					}

					// Find relations for this element.
					// Extend the node with element properties.
					$connection['relationship'] = $relationship;

					if (str_ends_with(haystack: $connection['source'], needle: $this->suffix) === false) {
						$connection['source'] = $connection['source'] . $this->suffix;
					}

					if (str_ends_with(haystack: $connection['target'], needle: $this->suffix) === false) {
						$connection['target'] = $connection['target'] . $this->suffix;
					}

					$resolve($connection);
				} catch (\Exception $e) {
					$this->logger->error(
						'Failed to extend node: ' . $e->getMessage(),
						[
							'exception' => $e,
							'node' => $connection,
						]
					);
					$reject($e);
				}//end try
			}
		);

	}//end extendConnection()

	/**
	 * Finds the matching element for a given node.
	 *
	 * @param array $node The node to find an element for.
	 *
	 * @return array|null The matching element or null if not found.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function findElementForNode(array $node): ?array {
		if (isset($node['elementRef']) === false) {
			return null;
		}

		$index = array_search(
			needle: $node['elementRef'],
			haystack: array_column(array: $this->elements, column_key: 'identifier'),
			strict: true
		);

		if ($index !== false) {
			return $this->elements[$index];
		}

		return null;
	}//end findElementForNode()

	/**
	 * Finds the matching relationship for a given connection.
	 *
	 * @param array $connection The connection to find a relationship for.
	 *
	 * @return array|null The matching relationship or null if not found.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function findRelationForConnection(array $connection): ?array {
		if (isset($connection['relationshipRef']) === false) {
			return null;
		}

		$index = array_search(
			needle: $connection['relationshipRef'],
			haystack: array_column(array: $this->relations, column_key: 'identifier'),
			strict: true
		);

		if ($index !== false) {
			return $this->relations[$index];
		}

		return null;
	}//end findRelationForConnection()

	/**
	 * Finds all relations for a given element.
	 *
	 * @param array $element The element to find relations for.
	 *
	 * @return array Array of relations.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function findRelationsForElement(array $element): array {
		$relations = [];
		// Iterate over each relation to find those associated with the given element.
		foreach ($this->relations as $relation) {
			// Check if the element's ID matches the source or target of the relation.
			if ($relation['source'] === $element['identifier']
				|| $relation['target'] === $element['identifier']
			) {
				// Add the matching relation to the relations array.
				$relations[] = $relation;
			}
		}

		// Return the array of found relations.
		return $relations;
	}//end findRelationsForElement()

	/**
	 * Handles a new organization in the software catalog.
	 *
	 * @param ObjectEntity $organization The organization object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleNewOrganization(ObjectEntity $organization): void {
		// Send welcome email to the organization.
		$this->sendWelcomeEmail(organization: $organization);

		// Send notification to VNG about new organization.
		$this->sendVngNotification(organization: $organization);

		// Create security group for the organization.
		$this->createSecurityGroup(organization: $organization);

	}//end handleNewOrganization()

	/**
	 * Handles a new contact in the software catalog.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleNewContact(ObjectEntity $contact): void {
		// Create or enable user for the contact.
		$this->createOrEnableUser(contact: $contact);

		// Send welcome email to the contact.
		$this->sendContactWelcomeEmail(contact: $contact);

	}//end handleNewContact()

	/**
	 * Handles contact updates in the software catalog.
	 *
	 * @param ObjectEntity $contact The updated contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleContactUpdate(ObjectEntity $contact): void {
		// Update user information.
		$this->updateUser(contact: $contact);

		// Send update notification email.
		$this->sendContactUpdateEmail(contact: $contact);

	}//end handleContactUpdate()

	/**
	 * Handles contact deletion in the software catalog.
	 *
	 * @param ObjectEntity $contact The deleted contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleContactDeletion(ObjectEntity $contact): void {
		// Disable user account.
		$this->disableUser(contact: $contact);

		// Send deletion notification email.
		$this->sendContactDeletionEmail(contact: $contact);

	}//end handleContactDeletion()

	/**
	 * Sends a welcome email to a new organization.
	 *
	 * @param ObjectEntity $organization The organization object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the email sending fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function sendWelcomeEmail(ObjectEntity $organization): void {
		// TODO: Implement email sending logic.
		$this->logger->info('Sending welcome email to organization', ['organization' => $organization]);

	}//end sendWelcomeEmail()

	/**
	 * Sends a notification to VNG about a new organization.
	 *
	 * @param ObjectEntity $organization The organization object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the notification sending fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function sendVngNotification(ObjectEntity $organization): void {
		// TODO: Implement VNG notification logic.
		$this->logger->info('Sending VNG notification about new organization', ['organization' => $organization]);

	}//end sendVngNotification()

	/**
	 * Creates a security group for an organization.
	 *
	 * @param ObjectEntity $organization The organization object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the security group creation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function createSecurityGroup(ObjectEntity $organization): void {
		// TODO: Implement security group creation logic.
		$this->logger->info('Creating security group for organization', ['organization' => $organization]);

	}//end createSecurityGroup()

	/**
	 * Creates or enables a user for a contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the user creation/enabling fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function createOrEnableUser(ObjectEntity $contact): void {
		// TODO: Implement user creation/enabling logic.
		$this->logger->info('Creating or enabling user for contact', ['contact' => $contact]);

	}//end createOrEnableUser()

	/**
	 * Updates user information for a contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the user update fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function updateUser(ObjectEntity $contact): void {
		// TODO: Implement user update logic.
		$this->logger->info('Updating user for contact', ['contact' => $contact]);

	}//end updateUser()

	/**
	 * Disables a user account for a deleted contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the user disabling fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function disableUser(ObjectEntity $contact): void {
		// TODO: Implement user disabling logic.
		$this->logger->info('Disabling user for contact', ['contact' => $contact]);

	}//end disableUser()

	/**
	 * Sends a welcome email to a new contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the email sending fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function sendContactWelcomeEmail(ObjectEntity $contact): void {
		// TODO: Implement contact welcome email logic.
		$this->logger->info('Sending welcome email to contact', ['contact' => $contact]);

	}//end sendContactWelcomeEmail()

	/**
	 * Sends an update notification email to a contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the email sending fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function sendContactUpdateEmail(ObjectEntity $contact): void {
		// TODO: Implement contact update email logic.
		$this->logger->info('Sending update email to contact', ['contact' => $contact]);

	}//end sendContactUpdateEmail()

	/**
	 * Sends a deletion notification email to a contact.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the email sending fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function sendContactDeletionEmail(ObjectEntity $contact): void {
		// TODO: Implement contact deletion email logic.
		$this->logger->info('Sending deletion email to contact', ['contact' => $contact]);

	}//end sendContactDeletionEmail()
}//end class
