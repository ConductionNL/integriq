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
}
