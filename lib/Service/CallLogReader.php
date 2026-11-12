<?php

/**
 * Integriq Call Log Reader
 *
 * Reads the parts of a source and of a CallService call log that the RIS
 * connectors (iBabs, NotuBiz) answer from: the source configuration, the
 * status code and the decoded response body.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Readers for a source's configuration and a call log's answer.
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
 */
final class CallLogReader {

	/**
	 * Extract and normalise the source configuration array.
	 *
	 * @param ObjectEntity $source The source entity.
	 *
	 * @return array<string,mixed> Normalised configuration.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
	 */
	public function sourceConfig(ObjectEntity $source): array {
		$sourceData = $source->getObject();
		$config = ($sourceData['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true) ?? [];
		}

		if (is_array($config) === false) {
			return [];
		}

		return $config;

	}//end sourceConfig()

	/**
	 * Get the HTTP status code from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by CallService::call().
	 *
	 * @return int HTTP status code, or 0 if absent.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
	 */
	public function statusCode(?ObjectEntity $callLog): int {
		if ($callLog === null) {
			return 0;
		}

		$data = $callLog->getObject();
		return (int)($data['statusCode'] ?? 0);

	}//end statusCode()

	/**
	 * Get the decoded response body from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by CallService::call().
	 *
	 * @return mixed The decoded response body, the raw string when it is no JSON, or null.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
	 */
	public function responseBody(?ObjectEntity $callLog): mixed {
		if ($callLog === null) {
			return null;
		}

		$data = $callLog->getObject();
		$body = $data['response']['body'] ?? null;
		if (is_string($body) === true) {
			$decoded = json_decode($body, true);
			if ($decoded !== null) {
				return $decoded;
			}
		}

		return $body;

	}//end responseBody()
}//end class
