<?php

/**
 * Integriq Document Generation Controller.
 *
 * The operator's half: read a vendor's templates for a source, and activate a
 * source only when it can actually render.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderRegistry;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Document generation sources, from the operator's side.
 *
 * Both endpoints are administrator-only: a document generation source is
 * instance configuration, and its template list is a vendor's, not a user's.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
 */
class DocumentGenerationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request object.
	 * @param ORObjectService $objectService Reads and writes the source objects.
	 * @param DocumentGenerationProviderRegistry $registry Resolves the source's binding.
	 * @param LoggerInterface $logger Logger for credential-free diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ORObjectService $objectService,
		private readonly DocumentGenerationProviderRegistry $registry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The vendor's templates for one source.
	 *
	 * @param string $sourceId The document generation source.
	 *
	 * @return JSONResponse The vendor's template ids and names.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-the-operator-sees-the-vendors-templates
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function templates(string $sourceId): JSONResponse {
		try {
			$configuration = $this->sourceConfiguration(sourceId: $sourceId);
			$provider = $this->registry->resolve(sourceConfiguration: $configuration);

			return new JSONResponse(
				[
					'providerId' => $provider->getProviderId(),
					'templates' => $provider->listTemplates(sourceConfiguration: $configuration),
				]
			);
		} catch (DocumentGenerationException $exception) {
			return new JSONResponse(
				['error' => $exception->getMessage(), 'unreachable' => $exception->isUnreachable()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[integriq] the vendor templates could not be listed: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return new JSONResponse(
				['error' => 'The vendor templates could not be listed.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end templates()

	/**
	 * Activate one document generation source, or say why it cannot render.
	 *
	 * @param string $sourceId The document generation source.
	 *
	 * @return JSONResponse Whether the source is now active.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function activate(string $sourceId): JSONResponse {
		try {
			$source = $this->source(sourceId: $sourceId);
			$object = $source->getObject();
			$configuration = (array)($object['configuration'] ?? []);

			$provider = $this->registry->resolve(sourceConfiguration: $configuration);
			$provider->assertActivatable(sourceConfiguration: $configuration);

			$object['isEnabled'] = true;
			$this->objectService->saveObject(
				object: $object,
				register: DocumentGenerationService::REGISTER,
				schema: DocumentGenerationService::SCHEMA_SOURCE,
				uuid: $source->getUuid()
			);

			return new JSONResponse(['isEnabled' => true, 'providerId' => $provider->getProviderId()]);
		} catch (DocumentGenerationException $exception) {
			// The message names the missing configuration key, never a
			// credential: the material lives with the broker.
			return new JSONResponse(
				['error' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[integriq] a document generation source could not be activated: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return new JSONResponse(
				['error' => 'The source could not be activated.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end activate()

	/**
	 * Load one document generation source.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The source.
	 *
	 * @throws DocumentGenerationException When no such document generation source exists.
	 */
	private function source(string $sourceId) {
		$source = $this->objectService->find(
			id: $sourceId,
			register: DocumentGenerationService::REGISTER,
			schema: DocumentGenerationService::SCHEMA_SOURCE
		);

		if ($source === null) {
			throw new DocumentGenerationException(
				message: 'No document generation source "' . $sourceId . '" on this instance.'
			);
		}

		if ((string)($source->getObject()['type'] ?? '') !== DocumentGenerationService::SOURCE_TYPE) {
			throw new DocumentGenerationException(
				message: 'Source "' . $sourceId . '" is not a document generation source.'
			);
		}

		return $source;

	}//end source()

	/**
	 * The configuration of one document generation source.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed> The configuration.
	 *
	 * @throws DocumentGenerationException When no such source exists.
	 */
	private function sourceConfiguration(string $sourceId): array {
		return (array)($this->source(sourceId: $sourceId)->getObject()['configuration'] ?? []);

	}//end sourceConfiguration()
}//end class
