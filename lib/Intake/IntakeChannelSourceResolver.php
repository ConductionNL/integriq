<?php

/**
 * Integriq IntakeChannelSourceResolver.
 *
 * Finds the `source` that configures one intake channel: its webhook secret
 * and, where the channel can answer, its reply gateway. The channel id lives
 * inside the source's configuration rather than in a filter, so the lookup
 * reads the sources of this type and filters in the reading. Asking the
 * objects endpoint to filter on a nested key returns a confident wrong answer
 * rather than an error.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Resolves one intake channel's source configuration.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
 */
class IntakeChannelSourceResolver {

	/**
	 * The `source.type` an intake channel configuration is.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'intake-channel';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the sources.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * The source configuring one channel.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return ObjectEntity|null The source, or null when the channel has none.
	 */
	public function sourceFor(string $channelId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => IntakeRoutingService::REGISTER,
					'schema' => 'source',
					'type' => self::SOURCE_TYPE,
					'isEnabled' => true,
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return null;
		}

		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			$configuration = ($row->getObject()['configuration'] ?? []);
			if (is_array($configuration) === false) {
				continue;
			}

			if ((string)($configuration['channelId'] ?? '') === $channelId) {
				return $row;
			}
		}

		return null;

	}//end sourceFor()

	/**
	 * One channel's configuration block.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return array<string,mixed>|null The configuration, or null when the channel has no source.
	 */
	public function configurationFor(string $channelId): ?array {
		$source = $this->sourceFor($channelId);
		if ($source === null) {
			return null;
		}

		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return null;
		}

		return $configuration;

	}//end configurationFor()

}//end class
