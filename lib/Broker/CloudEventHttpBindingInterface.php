<?php

/**
 * Contract of the CloudEventHttpBinding.
 *
 * @category Contract
 * @package  OCA\Integriq\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

/**
 * Public operations of {@see CloudEventHttpBinding} that other components call.
 */
interface CloudEventHttpBindingInterface {
	/**
	 * The whole event in the body.
	 *
	 * @var string
	 */
	public const MODE_STRUCTURED = 'structured';

	/**
	 * Render one CloudEvent for HTTP.
	 *
	 * @param array<string,mixed> $cloudEvent The event.
	 * @param string $contentMode `structured` or `binary`. Anything else is read as `structured`.
	 *
	 * @return array{headers: array<string,string>, body: string} The headers and the serialised body.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
	 */
	public function render(array $cloudEvent, string $contentMode = self::MODE_STRUCTURED): array;

	/**
	 * Read one received CloudEvent from its HTTP body and headers.
	 *
	 * @param string   $body     The raw request body.
	 * @param callable $headerOf Returns a request header by name, '' when absent.
	 *
	 * @return array<string,mixed> The event, its required attributes checked.
	 *
	 * @throws \OCA\Integriq\Exception\CloudEventRefusedException When the event cannot be read or misses a required attribute.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	public function decode(string $body, callable $headerOf): array;
}
