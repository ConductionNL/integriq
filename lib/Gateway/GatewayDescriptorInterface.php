<?php

/**
 * Contract of the GatewayDescriptor.
 *
 * @category Contract
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

/**
 * Public operations of {@see GatewayDescriptor} that other components call.
 */
interface GatewayDescriptorInterface {
	/**
	 * The gateway id.
	 *
	 * @return string Gateway id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getId()
	 */
	public function getId(): string;

	/**
	 * The named standard or law this gateway serves.
	 *
	 * @return string Standard.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getStandard()
	 */
	public function getStandard(): string;

	/**
	 * The transport a call over this gateway uses.
	 *
	 * @return string Transport.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getTransport()
	 */
	public function getTransport(): string;

	/**
	 * Where the endpoint sits, or `unknown` when it was never declared.
	 *
	 * @return string Jurisdiction.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getJurisdiction()
	 */
	public function getJurisdiction(): string;

	/**
	 * The Wmebv obligations this route meets itself.
	 *
	 * @return array<int,string> Obligations.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getWmebvMet()
	 */
	public function getWmebvMet(): array;

	/**
	 * The Wmebv obligations this route hands to its consumer.
	 *
	 * @return array<int,array{obligation:string,consumerDuty:string}> Obligations and the duty each carries.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::getWmebvHandedToConsumer()
	 */
	public function getWmebvHandedToConsumer(): array;

	/**
	 * The entry as the catalogue and the overview render it.
	 *
	 * @return array<string,mixed> Serialisable entry.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayDescriptor::toArray()
	 */
	public function toArray(): array;
}
