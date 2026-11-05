<?php

/**
 * Integriq Edurep connection.
 *
 * What a consumer's connections page shows for Edurep: name, health,
 * settings and the terms link, and where it saves the settings.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * The Edurep connection.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class EdurepConnection {
	public const NAME = 'Open lesmateriaal via Edurep';

	/**
	 * Constructor.
	 *
	 * @param EdurepHealthCheck $health The health check.
	 * @param EdurepSettings $settings The settings.
	 */
	public function __construct(
		private readonly EdurepHealthCheck $health,
		private readonly EdurepSettings $settings,
	) {
	}//end __construct()

	/**
	 * The connection status.
	 *
	 * @return array<string,mixed> `source`, `name`, `available`, `health` and `settings`.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function status(): array {
		return [
			'source' => LomRecordReader::SOURCE,
			'name' => self::NAME,
			'available' => true,
			'health' => $this->health->check(),
			'settings' => $this->settings->toArray(),
		];
	}//end status()

	/**
	 * The settings.
	 *
	 * @return EdurepSettings The settings.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function settings(): EdurepSettings {
		return $this->settings;
	}//end settings()
}//end class
