<?php

/**
 * Integriq ConnectionConfigReader.
 *
 * Reads the declaring app's settings for the connection status resolver
 * (hydra umbrella design D4 rules 3 and 5, amended by D12): whether every
 * required setting is filled, and the value that selects an adapter.
 *
 * The adapter value is the config value at `adapter.configKey`, or, with
 * `adapter.jsonPath`, the scalar at that dot path inside the JSON object the
 * key holds. A missing path, invalid JSON or a non-scalar value reads as the
 * empty string. `adapter.simulatedValues` defaults to `[""]`, so a declaration
 * without it keeps its old meaning: an empty key means a mock answers.
 *
 * A `requiredConfig` entry is a whole app-config key, dots included, or
 * `{configKey, jsonPath}`, read through the same path walk as the adapter.
 * What a value then means, filled or empty, and whether it equals a declared
 * value, lives in {@see ConnectionConfigValue}.
 *
 * A `switch` is read through the same path walk. Without `offValues` it is
 * off when its value is empty. With `offValues` it is off only when its value
 * is one of them, compared like `simulatedValues`, so an unset key is off only
 * when `""` is listed (umbrella D2, D4 rule 2b).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;

/**
 * Reads another app's config for the D4 rules.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */
class ConnectionConfigReader {

	/**
	 * The adapter values that mean a mock answers, when a declaration names none.
	 *
	 * @var string[]
	 */
	public const DEFAULT_SIMULATED_VALUES = [''];

	/**
	 * Judges the values this class reads.
	 *
	 * @var ConnectionConfigValue
	 */
	private readonly ConnectionConfigValue $value;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the declaring app's settings.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
		$this->value = new ConnectionConfigValue();
	}//end __construct()

	/**
	 * Whether the adapter value is one of the values that mean a mock answers.
	 *
	 * @param string $app The declaring app id.
	 * @param array<string|int,mixed> $adapter The declared adapter object, with a non-empty `configKey`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-provider-name-selects-simulated
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-json-path-reads-inside-a-settings-blob
	 */
	public function isSimulated(string $app, array $adapter): bool {
		$configKey = (string)($adapter['configKey'] ?? '');
		if ($configKey === '') {
			return false;
		}

		$simulatedValues = $adapter['simulatedValues'] ?? null;
		if (is_array($simulatedValues) === false) {
			$simulatedValues = self::DEFAULT_SIMULATED_VALUES;
		}

		$text = $this->adapterValue(app: $app, configKey: $configKey, jsonPath: $adapter['jsonPath'] ?? null);

		return $this->value->isOneOf(value: $text, candidates: $simulatedValues);
	}//end isSimulated()

	/**
	 * Whether a declared switch reads as off.
	 *
	 * The value at `configKey`, through `jsonPath` when one is set, is read the
	 * way a `requiredConfig` entry is. Without `offValues` the switch is off
	 * when that value is empty. With `offValues` it is off only when the value,
	 * as trimmed text, equals one of them case-insensitively. A non-empty JSON
	 * list or object never equals an off value.
	 *
	 * @param string $app The declaring app id.
	 * @param mixed $switch The declared switch object, if any.
	 *
	 * @return bool False when there is no switch, or it has no usable `configKey`.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-connection-switched-off-reads-disabled
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-off-values-leave-a-working-default-alone
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-off-value-switches-the-connection-off
	 */
	public function isSwitchedOff(string $app, mixed $switch): bool {
		if (is_array($switch) === false || is_string($switch['configKey'] ?? null) === false || $switch['configKey'] === '') {
			return false;
		}

		$value = $this->requiredValue(app: $app, entry: $this->switchEntry(switch: $switch));
		$offValues = $switch['offValues'] ?? null;
		if (is_array($offValues) === false) {
			return $this->value->isFilled(value: $value) === false;
		}

		return $this->value->isOneOf(value: $value, candidates: $offValues);
	}//end isSwitchedOff()

	/**
	 * The switch as a `requiredConfig` entry: a key, or a key and a dot path.
	 *
	 * @param array<string|int,mixed> $switch The declared switch object.
	 *
	 * @return mixed
	 */
	private function switchEntry(array $switch): mixed {
		$jsonPath = $switch['jsonPath'] ?? null;
		if (is_string($jsonPath) === true && $jsonPath !== '') {
			return ['configKey' => $switch['configKey'], 'jsonPath' => $jsonPath];
		}

		return $switch['configKey'];
	}//end switchEntry()

	/**
	 * Whether every required entry holds a filled value in the app's config.
	 *
	 * @param string $app The declaring app id.
	 * @param array<int|string,mixed> $entries The `requiredConfig` entries: keys, or `{configKey, jsonPath}` objects.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-saved-settings-show-configured
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-switch-stored-as-false-is-not-filled
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-required-value-inside-a-json-setting
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-dotted-key-is-read-as-one-key
	 */
	public function allFilled(string $app, array $entries): bool {
		foreach ($entries as $entry) {
			if ($this->value->isFilled(value: $this->requiredValue(app: $app, entry: $entry)) === false) {
				return false;
			}
		}

		return true;
	}//end allFilled()

	/**
	 * The value one `requiredConfig` entry points at.
	 *
	 * A string is always the whole key, even when it contains dots. An object
	 * reads the value at its `jsonPath` inside the JSON its `configKey` holds.
	 *
	 * @param string $app The declaring app id.
	 * @param mixed $entry The entry.
	 *
	 * @return mixed The value, or null when the entry is invalid or the path is missing.
	 */
	private function requiredValue(string $app, mixed $entry): mixed {
		if (is_string($entry) === true) {
			return $this->readAnyType(app: $app, key: $entry);
		}

		$configKey = null;
		$jsonPath = null;
		if (is_array($entry) === true) {
			$configKey = $entry['configKey'] ?? null;
			$jsonPath = $entry['jsonPath'] ?? null;
		}

		if (is_string($configKey) === false || is_string($jsonPath) === false || $jsonPath === '') {
			return null;
		}

		return $this->valueAtPath(tree: $this->readObject(app: $app, key: $configKey), path: $jsonPath);
	}//end requiredValue()

	/**
	 * The value that selects the adapter, trimmed.
	 *
	 * @param string $app The declaring app id.
	 * @param string $configKey The config key.
	 * @param mixed $jsonPath The declared dot path, if any.
	 *
	 * @return string
	 */
	private function adapterValue(string $app, string $configKey, mixed $jsonPath): string {
		if (is_string($jsonPath) === false || $jsonPath === '') {
			return $this->read(app: $app, key: $configKey);
		}

		return $this->scalarAtPath(tree: $this->readObject(app: $app, key: $configKey), path: $jsonPath);
	}//end adapterValue()

	/**
	 * Read one value from another app's config.
	 *
	 * `lazy: true` makes Nextcloud return lazy and non-lazy values alike, so a
	 * key the app stored as lazy is not mistaken for an empty one. A value
	 * stored under another type makes getValueString() throw; such a key does
	 * hold a value, so it counts as filled.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The config key.
	 *
	 * @return string The trimmed value, or '' when absent.
	 */
	private function read(string $app, string $key): string {
		if ($app === '' || $key === '') {
			return '';
		}

		try {
			return trim($this->appConfig->getValueString($app, $key, '', true));
		} catch (AppConfigTypeConflictException $e) {
			return 'typed';
		}
	}//end read()

	/**
	 * Read one value from another app's config, in the type it is stored under.
	 *
	 * A value stored under another type makes getValueString() throw. Such a
	 * value is read with the getter for its type, so a switch stored as a
	 * boolean `false` reads as `false`, not as a filled key.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The config key.
	 *
	 * @return mixed The value, or '' when absent.
	 */
	private function readAnyType(string $app, string $key): mixed {
		if ($app === '' || $key === '') {
			return '';
		}

		try {
			return $this->appConfig->getValueString($app, $key, '', true);
		} catch (AppConfigTypeConflictException $e) {
			return $this->readTyped(app: $app, key: $key);
		}
	}//end readAnyType()

	/**
	 * Read a value stored under a type other than string.
	 *
	 * A type this reader does not know still holds a value, so it counts as filled.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The config key.
	 *
	 * @return mixed
	 */
	private function readTyped(string $app, string $key): mixed {
		try {
			return match ($this->appConfig->getValueType($app, $key, true)) {
				IAppConfig::VALUE_BOOL => $this->appConfig->getValueBool($app, $key, false, true),
				IAppConfig::VALUE_INT => $this->appConfig->getValueInt($app, $key, 0, true),
				IAppConfig::VALUE_FLOAT => $this->appConfig->getValueFloat($app, $key, 0.0, true),
				IAppConfig::VALUE_ARRAY => $this->readArray(app: $app, key: $key),
				default => true,
			};
		} catch (AppConfigTypeConflictException | AppConfigUnknownKeyException $e) {
			return true;
		}
	}//end readTyped()

	/**
	 * Read a config value holding a JSON object, decoded.
	 *
	 * A value Nextcloud stores under the array type makes getValueString()
	 * throw; getValueArray() reads that one.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The config key.
	 *
	 * @return mixed The decoded value, or null when it is absent or not JSON.
	 */
	private function readObject(string $app, string $key): mixed {
		if ($app === '' || $key === '') {
			return null;
		}

		try {
			return json_decode($this->appConfig->getValueString($app, $key, '', true), true);
		} catch (AppConfigTypeConflictException $e) {
			return $this->readArray(app: $app, key: $key);
		}
	}//end readObject()

	/**
	 * Read a config value stored under the array type.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The config key.
	 *
	 * @return array<mixed>|null The value, or null when it is stored under another type.
	 */
	private function readArray(string $app, string $key): ?array {
		try {
			return $this->appConfig->getValueArray($app, $key, [], true);
		} catch (AppConfigTypeConflictException $e) {
			return null;
		}
	}//end readArray()

	/**
	 * The scalar at a dot path, as a trimmed string.
	 *
	 * @param mixed $tree The decoded JSON.
	 * @param string $path The dot path, such as `chat.provider`.
	 *
	 * @return string The value, or '' when the path is missing or the value is not a scalar.
	 */
	private function scalarAtPath(mixed $tree, string $path): string {
		return $this->value->text(value: $this->valueAtPath(tree: $tree, path: $path));
	}//end scalarAtPath()

	/**
	 * The value at a dot path.
	 *
	 * @param mixed $tree The decoded JSON.
	 * @param string $path The dot path, such as `chat.provider`.
	 *
	 * @return mixed The value, or null when the path is missing.
	 */
	private function valueAtPath(mixed $tree, string $path): mixed {
		foreach (explode('.', $path) as $segment) {
			if (is_array($tree) === false || array_key_exists($segment, $tree) === false) {
				return null;
			}

			$tree = $tree[$segment];
		}

		return $tree;
	}//end valueAtPath()

}//end class
