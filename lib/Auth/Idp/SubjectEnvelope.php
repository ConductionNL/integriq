<?php

/**
 * Integriq SubjectEnvelope.
 *
 * The only thing a consuming app ever receives from a government login. No
 * SAML assertion, no id_token, no attribute the envelope does not name. A
 * consumer that cannot read the assertion cannot leak it.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * One verified subject, as a consuming app sees it.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-broker-boundary-integriq-owns-the-government-idp-conversation
 */
final class SubjectEnvelope {

	/**
	 * The `use` claim that marks an envelope as an envelope.
	 *
	 * A consumer's session resolver rejects any token carrying a non-empty
	 * `use`, which is what keeps a leaked envelope from working as a session.
	 *
	 * @var string
	 */
	public const USE_CLAIM = 'idp-envelope';

	/**
	 * The issuer every envelope carries.
	 *
	 * @var string
	 */
	public const ISSUER = 'openconnector-idp-broker';

	/**
	 * The longest an envelope may live.
	 *
	 * @var integer
	 */
	public const MAX_TTL_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param string $subject The pseudonym, KvK, RSIN or eIDAS PersonIdentifier.
	 * @param string $subType `bsn-pseudonym`, `kvk`, `rsin` or `eidas-person-identifier`.
	 * @param string $provider `digid`, `eherkenning` or `eidas`.
	 * @param string $audience The consuming app the envelope is minted for.
	 * @param string $organisation The tenant the login happened in.
	 * @param string $trust `low`, `substantial` or `high`.
	 */
	public function __construct(
		private readonly string $subject,
		private readonly string $subType,
		private readonly string $provider,
		private readonly string $audience,
		private readonly string $organisation,
		private readonly string $trust,
	) {

	}//end __construct()

	/**
	 * The subject.
	 *
	 * @return string The subject.
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

	/**
	 * What kind of subject it is.
	 *
	 * @return string The subject type.
	 */
	public function getSubType(): string {
		return $this->subType;

	}//end getSubType()

	/**
	 * Which government provider verified it.
	 *
	 * @return string The provider.
	 */
	public function getProvider(): string {
		return $this->provider;

	}//end getProvider()

	/**
	 * The consuming app this envelope is for.
	 *
	 * @return string The audience.
	 */
	public function getAudience(): string {
		return $this->audience;

	}//end getAudience()

	/**
	 * The tenant the login happened in.
	 *
	 * @return string The organisation.
	 */
	public function getOrganisation(): string {
		return $this->organisation;

	}//end getOrganisation()

	/**
	 * The trust level, frozen at authentication.
	 *
	 * @return string The trust level.
	 */
	public function getTrust(): string {
		return $this->trust;

	}//end getTrust()

	/**
	 * The envelope's claims, without the time-bound ones.
	 *
	 * `jti`, `iat` and `exp` are added when it is signed, because they belong
	 * to one minting rather than to the subject.
	 *
	 * @return array<string,string> The claims.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function toClaims(): array {
		return [
			'sub' => $this->subject,
			'subType' => $this->subType,
			'provider' => $this->provider,
			'audience' => $this->audience,
			'organisation' => $this->organisation,
			'trust' => $this->trust,
			'use' => self::USE_CLAIM,
			'iss' => self::ISSUER,
		];

	}//end toClaims()

	/**
	 * Rebuild an envelope from a verified claim set.
	 *
	 * @param array<string,mixed> $claims The verified claims.
	 *
	 * @return self The envelope.
	 */
	public static function fromClaims(array $claims): self {
		return new self(
			(string)($claims['sub'] ?? ''),
			(string)($claims['subType'] ?? ''),
			(string)($claims['provider'] ?? ''),
			(string)($claims['audience'] ?? ''),
			(string)($claims['organisation'] ?? ''),
			(string)($claims['trust'] ?? ''),
		);

	}//end fromClaims()

}//end class
