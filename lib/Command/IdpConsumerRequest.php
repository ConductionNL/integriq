<?php

/**
 * Integriq IdpConsumerRequest: one run of the IdP consumer command.
 *
 * @category Command
 * @package  OCA\Integriq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console a registration runs on, the consumer it names, and the return
 * addresses and secret reference it settles on.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
final class IdpConsumerRequest {
	/**
	 * The consumer id.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The return addresses the consumer will have.
	 *
	 * @var list<string>
	 */
	public array $returnUrls = [];

	/**
	 * The secret reference the consumer will have.
	 *
	 * @var string
	 */
	public string $secretRef = '';

	/**
	 * Constructor.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 */
	public function __construct(
		public readonly InputInterface $input,
		public readonly OutputInterface $output,
	) {

	}//end __construct()
}//end class
