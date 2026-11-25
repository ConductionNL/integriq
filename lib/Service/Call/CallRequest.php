<?php

/**
 * What one outbound call asks for, apart from the source it calls.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
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

namespace OCA\Integriq\Service\Call;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;

/**
 * The endpoint, method, configuration and dispatch options of one call, as
 * CallService hands them from preparation through dispatch to the call log.
 *
 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The constructor takes the options of CallService::call() and
 *   callAsync() one to one, so those public signatures stay as other apps call them.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags are the call options themselves, held, not branched on.
 * @SuppressWarnings(PHPMD.LongVariable) $runningSupportRequest keeps the name call() and callAsync() use.
 */
final class CallRequest {

	/**
	 * Holds the values of one call.
	 *
	 * @param string                     $endpoint              The endpoint on the source to call.
	 * @param string                     $method                The method to call the source with.
	 * @param array                      $config                The additional configuration of the call.
	 * @param bool                       $asynchronous          Whether the call is dispatched asynchronously.
	 * @param bool                       $overruleAuth          Whether to overrule the source authentication.
	 * @param bool                       $read                  Whether this is a singular read (vs list) call.
	 * @param bool                       $runningSupportRequest Whether this call is itself a pre/postRequest hook.
	 * @param mixed                      $sink                  Optional temp-file path the body is streamed to.
	 * @param ExecutionTraceContext|null $trace                 The active execution trace, when any.
	 * @param mixed                      $onHeaders             Optional callable run once the response headers arrive.
	 * @param bool                       $persistLog            When false, a transient unsaved call log is returned.
	 * @param bool                       $withholdBodies        When true, the call log and trace step hold no body.
	 */
	public function __construct(
		public readonly string $endpoint = '',
		public readonly string $method = 'GET',
		public readonly array $config = [],
		public readonly bool $asynchronous = false,
		public readonly bool $overruleAuth = false,
		public readonly bool $read = false,
		public readonly bool $runningSupportRequest = false,
		public readonly mixed $sink = null,
		public readonly ?ExecutionTraceContext $trace = null,
		public readonly mixed $onHeaders = null,
		public readonly bool $persistLog = true,
		public readonly bool $withholdBodies = false,
	) {
	}//end __construct()
}//end class
