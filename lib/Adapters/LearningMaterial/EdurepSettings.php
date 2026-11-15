<?php

/**
 * Integriq Edurep settings.
 *
 * The Edurep connection's settings in the app config: on or off, the
 * collections left out of a search unless asked for (KlasCement by default),
 * the default language, and Kennisnet's terms link.
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

use OCP\IAppConfig;

/**
 * Edurep connection settings.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class EdurepSettings {
	public const APP = 'integriq';
	public const KEY_ENABLED = 'edurep.enabled';
	public const KEY_DISABLED_COLLECTIONS = 'edurep.disabled_collections';
	public const KEY_LANGUAGE = 'edurep.language';
	public const DEFAULT_DISABLED_COLLECTIONS = ['KlasCement'];
	public const TERMS_URL = 'https://www.kennisnet.nl/diensten/edurep/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The app config.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The settings.
	 *
	 * @return array{enabled: bool, disabledCollections: list<string>, language: string, termsUrl: string} The settings.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function toArray(): array {
		return [
			'enabled' => $this->isEnabled(),
			'disabledCollections' => $this->disabledCollections(),
			'language' => $this->config->getValueString(self::APP, self::KEY_LANGUAGE, EdurepCqlBuilder::DEFAULT_LANGUAGE),
			'termsUrl' => self::TERMS_URL,
		];
	}//end toArray()

	/**
	 * Whether the connection is on.
	 *
	 * @return bool True unless switched off.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function isEnabled(): bool {
		return in_array($this->config->getValueString(self::APP, self::KEY_ENABLED, '1'), ['0', 'false'], true) === false;
	}//end isEnabled()

	/**
	 * The collections a search leaves out unless it names them.
	 *
	 * @return list<string> Collection ids (`meta.repository.id`).
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function disabledCollections(): array {
		$stored = json_decode($this->config->getValueString(self::APP, self::KEY_DISABLED_COLLECTIONS, ''), true);
		if (is_array($stored) === false) {
			return self::DEFAULT_DISABLED_COLLECTIONS;
		}

		return array_values(array_map('strval', array_filter($stored, 'is_string')));
	}//end disabledCollections()

	/**
	 * Save the settings a caller sends; keys it leaves out keep their value.
	 *
	 * @param array<string,mixed> $settings `enabled`, `disabledCollections` and `language`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function save(array $settings): void {
		$values = [
			self::KEY_ENABLED => self::enabledValue(settings: $settings),
			self::KEY_DISABLED_COLLECTIONS => self::collectionsValue(settings: $settings),
			self::KEY_LANGUAGE => self::languageValue(settings: $settings),
		];
		foreach (array_filter($values, 'is_string') as $key => $value) {
			$this->config->setValueString(self::APP, $key, $value);
		}
	}//end save()

	/**
	 * The stored form of `enabled`.
	 *
	 * @param array<string,mixed> $settings The sent settings.
	 *
	 * @return string|null `1` or `0`, or null when not sent.
	 */
	private static function enabledValue(array $settings): ?string {
		if (array_key_exists('enabled', $settings) === false) {
			return null;
		}

		return (string)(int)(bool)$settings['enabled'];
	}//end enabledValue()

	/**
	 * The stored form of `disabledCollections`.
	 *
	 * @param array<string,mixed> $settings The sent settings.
	 *
	 * @return string|null A JSON list, or null when not sent as a list.
	 */
	private static function collectionsValue(array $settings): ?string {
		if (is_array($settings['disabledCollections'] ?? null) === false) {
			return null;
		}

		return (string)json_encode(array_values(array_filter($settings['disabledCollections'], 'is_string')));
	}//end collectionsValue()

	/**
	 * The stored form of `language`: empty for every language, else a two or three letter code.
	 *
	 * @param array<string,mixed> $settings The sent settings.
	 *
	 * @return string|null The code, or null when not sent or not a code.
	 */
	private static function languageValue(array $settings): ?string {
		$language = ($settings['language'] ?? null);
		if (is_string($language) === false || preg_match('/^(?:[a-z]{2,3})?$/', $language) !== 1) {
			return null;
		}

		return $language;
	}//end languageValue()
}//end class
