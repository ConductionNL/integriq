<?php

/**
 * Contract of the PublicationGateway.
 *
 * @category Contract
 * @package  OCA\Integriq\Gateway\Adapter
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

namespace OCA\Integriq\Gateway\Adapter;

use OCA\Integriq\Gateway\GatewayDelivery;

/**
 * Public operations of {@see PublicationGateway} that other components call.
 */
interface PublicationGatewayInterface {
	/**
	 * The gateway id.
	 */
	public const ID = 'publicatie';

	/**
	 * Publish a document that is held elsewhere.
	 *
	 * @param array<string,mixed> $reference Where the document is, and who owns it.
	 * @param array<string,mixed> $instruction What to publish, and under which rules.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PublicationGateway::publish()
	 */
	public function publish(array $reference, array $instruction, array $config = []): GatewayDelivery;

	/**
	 * Check a publication before anything leaves.
	 *
	 * @param array<string,mixed> $reference The document reference.
	 * @param array<string,mixed> $instruction The publication instruction.
	 *
	 * @return array<int,string> The refusals, empty when it may go.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PublicationGateway::validate()
	 */
	public function validate(array $reference, array $instruction): array;
}
