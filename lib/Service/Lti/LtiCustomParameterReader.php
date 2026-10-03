<?php

/**
 * Integriq LtiCustomParameterReader.
 *
 * Reads the LTI custom parameters a launched placement carries from the
 * synchronization that wrote it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * The custom claim values for a placement a synchronization wrote (design D8).
 *
 * The learniq placement has no field for the provider's course id, so the id
 * stays the origin id of the contract whose target is the placement. A
 * synchronization that writes placements declares
 * `targetConfig.ltiCustomOriginIdParameter`, the custom parameter name the
 * provider's tool reads the course id from. At launch this reader finds the
 * placement's contract, reads its synchronization's declaration, and answers
 * `[<name> => <origin id>]`.
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */
class LtiCustomParameterReader {

	/**
	 * The synchronization key that names the custom parameter.
	 *
	 * @var string
	 */
	public const ORIGIN_ID_PARAMETER = 'ltiCustomOriginIdParameter';

	/**
	 * Constructor.
	 *
	 * @param SynchronizationContractService $contracts     The contract store.
	 * @param ObjectService                  $objectService OpenRegister, for the synchronization.
	 * @param LoggerInterface                $logger        Logs an unreadable store.
	 */
	public function __construct(
		private readonly SynchronizationContractService $contracts,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The custom parameters for a placement.
	 *
	 * A placement no synchronization wrote, a synchronization that declares
	 * nothing, and a store that cannot be read all answer an empty list: the
	 * launch then goes out without a custom claim, as before.
	 *
	 * @param string $placementId The placement's id (the contract's target id).
	 *
	 * @return array<string, string> Custom parameter name to value.
	 *
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
	 */
	public function forPlacement(string $placementId): array {
		if ($placementId === '') {
			return [];
		}

		try {
			$contracts = $this->contracts->findAllObjects(filters: ['targetId' => $placementId]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'integriq LTI launch: the contract of placement {placement} could not be read, so the launch carries no custom claim',
				['placement' => $placementId, 'exception' => $e]
			);
			return [];
		}

		foreach ($contracts as $contract) {
			$data = (array)$contract->getObject();
			$originId = (string)($data['originId'] ?? '');
			$parameter = $this->declaredParameter(synchronizationId: (string)($data['synchronizationId'] ?? ''));
			if ($originId !== '' && $parameter !== null) {
				return [$parameter => $originId];
			}
		}

		return [];
	}//end forPlacement()

	/**
	 * The custom parameter name a synchronization declares, if any.
	 *
	 * @param string $synchronizationId The synchronization's OpenRegister id.
	 *
	 * @return string|null The name, or null when it declares none or cannot be read.
	 */
	private function declaredParameter(string $synchronizationId): ?string {
		if ($synchronizationId === '') {
			return null;
		}

		try {
			$synchronization = $this->objectService->find(
				id: $synchronizationId,
				register: 'integriq',
				schema: 'synchronization'
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($synchronization === null) {
			return null;
		}

		$parameter = (((array)$synchronization->getObject())['targetConfig'][self::ORIGIN_ID_PARAMETER] ?? null);
		if (is_string($parameter) === false || $parameter === '') {
			return null;
		}

		return $parameter;
	}//end declaredParameter()
}//end class
