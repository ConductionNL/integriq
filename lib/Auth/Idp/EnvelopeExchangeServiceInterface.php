<?php

/**
 * Contract of the EnvelopeExchangeService.
 *
 * @category Contract
 * @package  OCA\Integriq\Auth\Idp
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

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * Public operations of {@see EnvelopeExchangeService} that other components call.
 */
interface EnvelopeExchangeServiceInterface {
	/**
	 * Mint an envelope and put it behind a fresh code.
	 *
	 * @param SubjectEnvelope $envelope The verified subject.
	 *
	 * @return string The one-time code to put in the redirect.
	 *
	 * @throws IdpAssertionException When the broker is off, unkeyed, or has no shared cache.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function issueCode(SubjectEnvelope $envelope): string;

	/**
	 * Redeem one code for its envelope.
	 *
	 * Every failure below produces the same exception type and reaches the
	 * caller as one undifferentiated refusal, because a consumer that can
	 * tell "unknown code" from "wrong secret" can probe for either.
	 *
	 * @param string $code The one-time code.
	 * @param string $consumer The consumer redeeming it.
	 * @param string $presentedSecret The consumer's shared secret.
	 *
	 * @return string The signed envelope.
	 *
	 * @throws IdpAssertionException On any failure.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function redeemCode(string $code, string $consumer, string $presentedSecret): string;
}
