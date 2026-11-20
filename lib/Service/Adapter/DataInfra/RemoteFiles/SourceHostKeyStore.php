<?php

/**
 * Reads an SFTP source and its pinned host key, and saves a newly pinned one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The host key pinned on an SFTP source.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class SourceHostKeyStore {

	/**
	 * The register sources live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService Reads and saves the source.
	 * @param LoggerInterface $logger          Logs a pin that could not be saved.
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The source with this id, or null when there is none.
	 *
	 * @param string $id The source's UUID.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function find(string $id): ?ObjectEntity {
		try {
			$source = $this->orObjectService->find(
				id: $id,
				options: new ObjectFindOptions(register: self::REGISTER, schema: 'source'),
				_rbac: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($source instanceof ObjectEntity === false) {
			return null;
		}

		return $source;
	}//end find()

	/**
	 * A fingerprint in the form pins are compared in.
	 *
	 * @param string $fingerprint The fingerprint as given.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function normalised(string $fingerprint): string {
		return (new HostKeyPin())->normalise(fingerprint: $fingerprint);
	}//end normalised()

	/**
	 * The fingerprint pinned on the source, normalised, or '' when none.
	 *
	 * @param ObjectEntity $source The source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function pinnedOf(ObjectEntity $source): string {
		$configuration = (array)($source->getObject()['configuration'] ?? []);
		return $this->normalised(fingerprint: (string)($configuration['hostKeyFingerprint'] ?? ''));
	}//end pinnedOf()

	/**
	 * Save the fingerprint as the source's pin.
	 *
	 * @param ObjectEntity $source      The source.
	 * @param string       $fingerprint The fingerprint to pin.
	 *
	 * @return string|null Null when saved, else why it was not.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function pin(ObjectEntity $source, string $fingerprint): ?string {
		$data = $source->getObject();
		$configuration = (array)($data['configuration'] ?? []);
		$configuration['hostKeyFingerprint'] = $fingerprint;
		$data['configuration'] = $configuration;

		try {
			$this->orObjectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: 'source'),
					uuid: (string)$source->getUuid()
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('[SourceHostKeyController] the host key was not pinned', ['exception' => $e->getMessage()]);
			return $e->getMessage();
		}

		return null;
	}//end pin()
}//end class
