<?php

/**
 * Contract of the DigitalPostResult.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

/**
 * Public operations of {@see DigitalPostResult} that other components call.
 */
interface DigitalPostResultInterface {
	/**
	 * Handed to the provider.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * The stable refusal code, when the refusal has one.
	 *
	 * @return string The code, empty when there is none.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::getCode()
	 */
	public function getCode(): string;

	/**
	 * The status this message is in.
	 *
	 * @return string One of the STATUS_* constants.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::getStatus()
	 */
	public function getStatus(): string;

	/**
	 * The provider's own reference.
	 *
	 * @return string|null The reference.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::getProviderReference()
	 */
	public function getProviderReference(): ?string;

	/**
	 * Why the send was refused.
	 *
	 * @return string The reason, empty when nothing was refused.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::getError()
	 */
	public function getError(): string;

	/**
	 * Whether this went to a binding that sends nothing.
	 *
	 * @return bool True when the send was simulated.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::isSimulated()
	 */
	public function isSimulated(): bool;

	/**
	 * Whether the send was refused.
	 *
	 * @return bool True when it failed.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on DigitalPostResult::isRefused()
	 */
	public function isRefused(): bool;

	/**
	 * The result as it is written onto the message.
	 *
	 * @return array<string,mixed> Serialisable result.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
	 */
	public function toArray(): array;
}
