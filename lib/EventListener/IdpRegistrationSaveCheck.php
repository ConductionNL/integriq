<?php

/**
 * Integriq IdpRegistrationSaveCheck.
 *
 * Judges an identity provider registration before OpenRegister saves it.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The save rules a registration's own fields must meet.
 *
 * A provider that is only reached over OIDC (Yivi, through an OIDC bridge) may
 * not be saved with the SAML kind: no SAML adapter can serve it, so the
 * registration would look configured and never log anyone in.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
class IdpRegistrationSaveCheck {

	/**
	 * The providers that are only reached as an OIDC Relying Party.
	 *
	 * @var list<string>
	 */
	public const OIDC_ONLY_PROVIDERS = ['yivi'];

	/**
	 * The register and schema of a registration, as IdpRegistrationService names them.
	 *
	 * @var array{register: string, schema: string}
	 */
	private const REGISTRATION = ['register' => 'integriq', 'schema' => 'idpRegistration'];

	/**
	 * The OIDC Relying Party kind, as IdpRegistration::KIND_OIDC names it.
	 *
	 * @var string
	 */
	private const KIND_OIDC = 'oidc-rp';

	/**
	 * The refusal code for an OIDC-only provider saved with another kind.
	 *
	 * @var string
	 */
	public const CODE_NEEDS_OIDC = 'idp_registration_needs_oidc';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper    $schemaMapper   Resolves the saved object's schema slug.
	 * @param RegisterMapper  $registerMapper Resolves the saved object's register slug.
	 * @param IL10N           $l10n           Translates the refusal.
	 * @param LoggerInterface $logger         Diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The errors that stop the save of this object, or null when it may be saved.
	 *
	 * Only an idpRegistration in the integriq register is judged; any other
	 * object passes.
	 *
	 * @param array<string, mixed> $data     The object's data.
	 * @param string|null          $register The object's register id.
	 * @param string|null          $schema   The object's schema id.
	 *
	 * @return array{code: string, message: string, status: int}|null The errors.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function refusal(array $data, ?string $register, ?string $schema): ?array {
		if ($this->isRegistration(register: $register, schema: $schema) === false) {
			return null;
		}

		$provider = trim((string)($data['provider'] ?? ''));
		$kind = trim((string)($data['kind'] ?? ''));
		if (in_array(needle: $provider, haystack: self::OIDC_ONLY_PROVIDERS, strict: true) === false || $kind === self::KIND_OIDC) {
			return null;
		}

		return [
			'code'    => self::CODE_NEEDS_OIDC,
			'message' => $this->l10n->t(
				'%s is reached through an OIDC bridge. Save this registration with the protocol OIDC Relying Party.',
				[ucfirst(string: $provider)]
			),
			'status'  => 400,
		];

	}//end refusal()

	/**
	 * Whether the object is an idpRegistration in the integriq register.
	 *
	 * @param string|null $register The object's register id.
	 * @param string|null $schema   The object's schema id.
	 *
	 * @return bool
	 */
	private function isRegistration(?string $register, ?string $schema): bool {
		try {
			$schemaSlug = $this->schemaMapper->find($schema)->getSlug();
			$registerSlug = $this->registerMapper->find($register)->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] idp registration check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $schemaSlug === self::REGISTRATION['schema'] && $registerSlug === self::REGISTRATION['register'];

	}//end isRegistration()
}//end class
