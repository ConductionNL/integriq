<?php

/**
 * Integriq ConnectionDeclarationValidator.
 *
 * Checks a decoded `connections.json` against the rules of
 * `lib/Settings/connections.schema.json`. The JSON Schema library is only a
 * dev dependency of this app, so the runtime cannot lean on it. This class
 * mirrors the schema rule for rule, and ConnectionDeclarationValidatorTest runs
 * both over the same fixtures so they cannot drift apart unnoticed.
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
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Validates a connection declaration file.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */
class ConnectionDeclarationValidator {

	/**
	 * Top-level fields: name => [required, type].
	 *
	 * @var array<string,array{0:bool,1:string}>
	 */
	private const FILE_FIELDS = [
		'$schema' => [false, 'string'],
		'app' => [true, 'appId'],
		'connections' => [true, 'list'],
	];

	/**
	 * Connection entry fields: name => [required, type].
	 *
	 * @var array<string,array{0:bool,1:string}>
	 */
	private const ENTRY_FIELDS = [
		'key' => [true, 'key'],
		'title' => [true, 'nonEmptyString'],
		'description' => [false, 'string'],
		'order' => [false, 'integer'],
		'settingsUrl' => [false, 'path'],
		'requiredConfig' => [false, 'requiredList'],
		'adapter' => [false, 'object'],
		'available' => [false, 'boolean'],
		'unavailableMessage' => [false, 'string'],
		'switch' => [false, 'object'],
		'disabledMessage' => [false, 'string'],
		'unconfiguredMessage' => [false, 'string'],
		'sourceTemplate' => [false, 'nonEmptyString'],
		'reportedOnly' => [false, 'boolean'],
	];

	/**
	 * Adapter object fields: name => [required, type].
	 *
	 * @var array<string,array{0:bool,1:string}>
	 */
	private const ADAPTER_FIELDS = [
		'configKey' => [false, 'nonEmptyString'],
		'jsonPath' => [false, 'dotPath'],
		'simulatedValues' => [false, 'anyStringList'],
		'simulatedMessage' => [false, 'string'],
	];

	/**
	 * Switch object fields: name => [required, type].
	 *
	 * @var array<string,array{0:bool,1:string}>
	 */
	private const SWITCH_FIELDS = [
		'configKey' => [true, 'nonEmptyString'],
		'jsonPath' => [false, 'dotPath'],
		'offValues' => [false, 'anyStringList'],
	];

	/**
	 * Fields of a `requiredConfig` entry written as an object: name => [required, type].
	 *
	 * @var array<string,array{0:bool,1:string}>
	 */
	private const REQUIRED_ENTRY_FIELDS = [
		'configKey' => [true, 'nonEmptyString'],
		'jsonPath' => [true, 'dotPath'],
	];

	/**
	 * The message per type, used when a value fails its type check.
	 *
	 * @var array<string,string>
	 */
	private const TYPE_MESSAGES = [
		'string' => 'must be a string',
		'nonEmptyString' => 'must be a non-empty string',
		'appId' => 'must be an app id',
		'key' => 'must match ^[a-z0-9]+(-[a-z0-9]+)*$',
		'integer' => 'must be an integer',
		'boolean' => 'must be a boolean',
		'path' => 'must be an absolute path starting with /',
		'list' => 'must be an array',
		'requiredList' => 'must be an array of non-empty strings or {configKey, jsonPath} objects',
		'anyStringList' => 'must be an array of strings',
		'dotPath' => 'must match ^[^.]+(\\.[^.]+)*$',
		'object' => 'must be an object',
	];

	/**
	 * Validate a decoded declaration file.
	 *
	 * @param mixed $data The decoded JSON.
	 *
	 * @return string[] One message per failing path, empty when the file is valid.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-invalid-file-is-skipped-whole
	 */
	public function validate(mixed $data): array {
		if ($this->isObject(value: $data) === false) {
			return ['/: must be an object'];
		}

		$errors = $this->checkObject(data: $data, fields: self::FILE_FIELDS, path: '');
		if (is_array($data['connections'] ?? null) === false || array_is_list($data['connections']) === false) {
			return $errors;
		}

		$seen = [];
		foreach ($data['connections'] as $index => $entry) {
			$path = '/connections/' . $index;
			$errors = array_merge($errors, $this->validateEntry(entry: $entry, path: $path));

			$key = $this->entryKey(entry: $entry);
			if ($key !== null && isset($seen[$key]) === true) {
				$errors[] = $path . '/key: duplicates key "' . $key . '"';
			}

			if ($key !== null) {
				$seen[$key] = true;
			}
		}

		return $errors;
	}//end validate()

	/**
	 * Validate one connection entry.
	 *
	 * @param mixed $entry The entry.
	 * @param string $path JSON pointer of the entry.
	 *
	 * @return string[] Messages per failing path.
	 */
	private function validateEntry(mixed $entry, string $path): array {
		if ($this->isObject(value: $entry) === false) {
			return [$path . ': must be an object'];
		}

		$errors = $this->checkObject(data: $entry, fields: self::ENTRY_FIELDS, path: $path);
		foreach (['adapter' => self::ADAPTER_FIELDS, 'switch' => self::SWITCH_FIELDS] as $name => $fields) {
			if ($this->isObject(value: $entry[$name] ?? null) === true) {
				$errors = array_merge($errors, $this->checkObject(data: $entry[$name], fields: $fields, path: $path . '/' . $name));
			}
		}

		return $errors;
	}//end validateEntry()

	/**
	 * Check one object against a field table: unknown keys, required keys, types.
	 *
	 * @param array<string|int,mixed> $data The object.
	 * @param array<string,array{0:bool,1:string}> $fields The field table.
	 * @param string $path JSON pointer of the object.
	 *
	 * @return string[] Messages per failing path.
	 */
	private function checkObject(array $data, array $fields, string $path): array {
		$errors = [];
		foreach (array_keys($data) as $name) {
			if (array_key_exists((string)$name, $fields) === false) {
				$errors[] = $path . '/' . $name . ': is not allowed';
			}
		}

		foreach ($fields as $name => [$required, $type]) {
			if (array_key_exists($name, $data) === false) {
				$errors = array_merge($errors, $this->requiredError(required: $required, path: $path . '/' . $name));
				continue;
			}

			if ($this->matchesType(type: $type, value: $data[$name]) === false) {
				$errors[] = $path . '/' . $name . ': ' . self::TYPE_MESSAGES[$type];
			}
		}

		return $errors;
	}//end checkObject()

	/**
	 * The error for an absent field, when it is required.
	 *
	 * @param bool $required Whether the field is required.
	 * @param string $path JSON pointer of the field.
	 *
	 * @return string[]
	 */
	private function requiredError(bool $required, string $path): array {
		if ($required === true) {
			return [$path . ': is required'];
		}

		return [];
	}//end requiredError()

	/**
	 * Whether a value matches a named type.
	 *
	 * @param string $type The type name from a field table.
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function matchesType(string $type, mixed $value): bool {
		return match ($type) {
			'string' => is_string($value),
			'nonEmptyString' => is_string($value) === true && $value !== '',
			'integer' => is_int($value),
			'boolean' => is_bool($value),
			'list' => is_array($value) === true && array_is_list($value) === true,
			'object' => $this->isObject(value: $value),
			default => $this->matchesPattern(type: $type, value: $value),
		};
	}//end matchesType()

	/**
	 * Whether a value matches one of the pattern and list types.
	 *
	 * @param string $type The type name.
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function matchesPattern(string $type, mixed $value): bool {
		if ($type === 'anyStringList') {
			return $this->isStringList(value: $value);
		}

		if ($type === 'requiredList') {
			return $this->isRequiredList(value: $value);
		}

		if (is_string($value) === false) {
			return false;
		}

		return match ($type) {
			'appId' => preg_match('/^[a-z0-9_]+$/', $value) === 1,
			'key' => preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value) === 1,
			'path' => str_starts_with($value, '/'),
			'dotPath' => preg_match('/^[^.]+(\.[^.]+)*$/', $value) === 1,
			default => false,
		};
	}//end matchesPattern()

	/**
	 * Whether a value is a list of strings, empty strings included.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isStringList(mixed $value): bool {
		if (is_array($value) === false || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if (is_string($item) === false) {
				return false;
			}
		}

		return true;
	}//end isStringList()

	/**
	 * Whether a value is a valid `requiredConfig` list.
	 *
	 * Each item is a non-empty app-config key, or an object with exactly a
	 * non-empty `configKey` and a dot-path `jsonPath`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-required-value-inside-a-json-setting
	 */
	private function isRequiredList(mixed $value): bool {
		if (is_array($value) === false || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				continue;
			}

			if ($this->isObject(value: $item) === false
				|| $this->checkObject(data: $item, fields: self::REQUIRED_ENTRY_FIELDS, path: '') !== []
			) {
				return false;
			}
		}

		return true;
	}//end isRequiredList()

	/**
	 * Whether a decoded JSON value is an object.
	 *
	 * `json_decode(..., true)` turns `{}` into `[]`, so an empty array counts
	 * as an object. A non-empty list does not.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isObject(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === false);
	}//end isObject()

	/**
	 * The entry's key when it is a string.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string|null
	 */
	private function entryKey(mixed $entry): ?string {
		if (is_array($entry) === true && is_string($entry['key'] ?? null) === true) {
			return $entry['key'];
		}

		return null;
	}//end entryKey()
}//end class
