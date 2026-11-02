<?php

/**
 * One downloaded file while SynchronizationService stores it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Carries the fetch descriptor, the call's response and the target object
 * between the stages of SynchronizationService::saveFetchedFile(), so each
 * stage names the file instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
 */
class FetchedFile {

	/**
	 * The endpoint as the action configured it, before rendering.
	 *
	 * @var string
	 */
	public readonly string $originalEndpoint;

	/**
	 * The action configuration.
	 *
	 * @var array
	 */
	public readonly array $config;

	/**
	 * Whether the body streamed to a temp file instead of the call log.
	 *
	 * @var bool
	 */
	public readonly bool $useSink;

	/**
	 * The call log's response, or null when the call had none.
	 *
	 * @var array|null
	 */
	public ?array $response = null;

	/**
	 * The read handle on the streamed temp file.
	 *
	 * @var resource|null
	 */
	public mixed $sink = null;

	/**
	 * The decoded body (call log path only).
	 *
	 * @var mixed
	 */
	public mixed $body = null;

	/**
	 * The content read from the body's contentPath, if configured.
	 *
	 * @var mixed
	 */
	public mixed $content = null;

	/**
	 * The id of the object the file belongs to.
	 *
	 * @var string
	 */
	public string $objectId = '';

	/**
	 * Tags to assign to the file.
	 *
	 * @var array|null
	 */
	public ?array $tags = [];

	/**
	 * The file name, resolved while storing.
	 *
	 * @var string|null
	 */
	public ?string $filename = null;

	/**
	 * The published status of the object.
	 *
	 * @var string|null
	 */
	public ?string $published = null;

	/**
	 * The id of the register the object belongs to.
	 *
	 * @var int|string|null
	 */
	public int|string|null $registerId = null;

	/**
	 * OpenRegister's FileService.
	 *
	 * @var mixed
	 */
	public mixed $fileService = null;

	/**
	 * The object the file was saved on, once found.
	 *
	 * @var mixed
	 */
	public mixed $objectEntity = null;

	/**
	 * Constructor.
	 *
	 * @param array        $prepared The prepareFileFetch() descriptor.
	 * @param ObjectEntity $callLog  The call log of the download.
	 */
	public function __construct(
		public readonly array $prepared,
		public readonly ObjectEntity $callLog,
	) {
		$this->originalEndpoint = $prepared['originalEndpoint'];
		$this->config           = $prepared['config'];
		$this->useSink          = $prepared['useSink'];
	}//end __construct()
}//end class
