<?php

/**
 * Integriq LtiPlatformDetailsService: what a tool's administrator enters at the vendor.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;

/**
 * The six platform values for one `lti_tool` registration.
 *
 * Every URL is generated from a route name of this app, so a value shown
 * here is one this instance answers on. The issuer is computed the way
 * {@see LtiPlatformLoginService::platformIssuer()} computes it, which the
 * test pins, so the detail view and the signed launch cannot drift apart.
 *
 * The registration is read without the approval gate: an administrator
 * needs these values to register at the vendor before approving the tool.
 * The endpoint that calls this is admin-only, and nothing here is secret.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
 */
class LtiPlatformDetailsService {

	/**
	 * Constructor.
	 *
	 * @param LtiRegistrationResolverService $resolver        Finds the tool's deployments.
	 * @param OrObjectService                $orObjectService Reads the tool registration.
	 * @param IURLGenerator                  $urlGenerator    Builds the absolute URLs.
	 */
	public function __construct(
		private readonly LtiRegistrationResolverService $resolver,
		private readonly OrObjectService $orObjectService,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * The six values for a tool, or null when the tool does not exist.
	 *
	 * @param string $toolUuid The `lti_tool` registration uuid.
	 *
	 * @return array{issuer: string, clientId: string, deploymentIds: list<string>, authorizationUrl: string, tokenUrl: string, keySetUrl: string}|null
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
	 */
	public function forTool(string $toolUuid): ?array {
		if ($toolUuid === '') {
			return null;
		}

		try {
			$tool = $this->orObjectService->find(
				id: $toolUuid,
				register: 'integriq',
				schema: 'lti_tool',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		if ($tool === null) {
			return null;
		}

		$deploymentIds = [];
		foreach ($this->resolver->findDeploymentsForTool(toolUuid: $toolUuid) as $deployment) {
			$deploymentId = ($deployment->getObject()['deploymentId'] ?? null);
			if (is_string($deploymentId) === true && $deploymentId !== '') {
				$deploymentIds[] = $deploymentId;
			}
		}

		return [
			'issuer' => rtrim($this->urlGenerator->getAbsoluteURL('/'), '/'),
			'clientId' => (string)($tool->getObject()['clientId'] ?? ''),
			'deploymentIds' => $deploymentIds,
			'authorizationUrl' => $this->urlGenerator->linkToRouteAbsolute('integriq.ltiPlatform.authorize'),
			'tokenUrl' => $this->urlGenerator->linkToRouteAbsolute('integriq.lti.token'),
			'keySetUrl' => $this->urlGenerator->linkToRouteAbsolute(
				'integriq.lti.jwks',
				['registrationType' => 'lti_tool', 'registrationUuid' => $toolUuid]
			),
		];

	}//end forTool()
}//end class
