<?php

/**
 * Golden tests for LtiPlatformLoginService::initiateLogin.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiCustomParameterReader;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiPlatformHint;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins every refusal and the allowed login initiation, with the exact hint
 * context and form fields.
 */
class LtiPlatformLoginGoldenTest extends TestCase {

	/**
	 * Hint contexts issued.
	 *
	 * @var array<int, array>
	 */
	private array $hints = [];

	/**
	 * A login over the given deployment and tool.
	 *
	 * @param array|null|false $deployment The deployment data, null for none, false for an invalid id.
	 * @param array|null       $tool       The tool data, null for not approved.
	 * @param string|null      $status     The tool's status when not approved.
	 * @param string|null      $sessionUid The signed-in user.
	 *
	 * @return LtiPlatformLoginService The service.
	 */
	private function service(array|null|false $deployment, ?array $tool, ?string $status=null, ?string $sessionUid='u-1'): LtiPlatformLoginService {
		$this->hints = [];
		$resolver    = $this->createMock(LtiRegistrationResolverService::class);
		if ($deployment === false) {
			$resolver->method('findDeploymentByUuid')->willThrowException(new LtiValidationException('bad uuid'));
		} else {
			$entity = null;
			if ($deployment !== null) {
				$entity = new ObjectEntity();
				$entity->setObject($deployment);
			}

			$resolver->method('findDeploymentByUuid')->with('dep-1')->willReturn($entity);
		}

		$toolEntity = null;
		if ($tool !== null) {
			$toolEntity = new ObjectEntity();
			$toolEntity->setObject($tool);
		}

		$resolver->method('findRegistrationByUuid')->with('lti_tool', 'tool-1')->willReturn($toolEntity);
		$resolver->method('findRegistrationStatus')->with('lti_tool', 'tool-1')->willReturn($status);

		$hint = $this->createMock(LtiPlatformHint::class);
		$hint->method('issue')->willReturnCallback(
			function (array $context): string {
				$this->hints[] = $context;
				return 'HINT';
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$session->method('getUser')->willReturn($user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->with('/')->willReturn('https://nc.example/');

		return new LtiPlatformLoginService(
			$resolver,
			$this->createMock(LtiLaunchService::class),
			$hint,
			$session,
			$urls,
			$this->createMock(LtiCustomParameterReader::class)
		);

	}//end service()

	/**
	 * The event a sibling app raises.
	 *
	 * @param string $messageType The message type.
	 * @param string $role        The role.
	 *
	 * @return LtiLaunchRequestedEvent The event.
	 */
	private static function event(string $messageType='LtiResourceLinkRequest', string $role='Learner'): LtiLaunchRequestedEvent {
		return new LtiLaunchRequestedEvent('learniq', 'pl-1', 'dep-1', 'u-1', $messageType, $role, 'c-1', 'Biologie', 'https://nc.example/back');

	}//end event()

	/**
	 * The result an event carries after the login.
	 *
	 * @param LtiPlatformLoginService $service The service.
	 * @param LtiLaunchRequestedEvent $event   The event.
	 *
	 * @return array The refusal and login initiation.
	 */
	private static function outcome(LtiPlatformLoginService $service, LtiLaunchRequestedEvent $event): array {
		$service->initiateLogin($event);
		return ['refusal' => $event->getRefusal(), 'login' => $event->getLoginInitiation()];

	}//end outcome()

	/**
	 * Every refusal; no hint is issued for any of them.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$deployment = ['deploymentId' => 'D-42', 'ltiToolId' => 'tool-1'];
		$tool       = ['oidcLoginUrl' => 'https://tool.example/login', 'clientId' => 'client', 'launchUrl' => 'https://tool.example/launch'];
		$cases      = [
			[$this->service($deployment, $tool, null, null), self::event(), 'user-mismatch', 'The launch is not for the signed-in user'],
			[$this->service($deployment, $tool, null, 'u-2'), self::event(), 'user-mismatch', 'The launch is not for the signed-in user'],
			[$this->service($deployment, $tool), self::event('LtiDeepLinkingRequest'), 'message-type-unsupported', 'Only LtiResourceLinkRequest launches are served; got LtiDeepLinkingRequest'],
			[$this->service($deployment, $tool), self::event('LtiResourceLinkRequest', 'Admin'), 'role-unknown', 'The role must be Learner or Instructor; got Admin'],
			[$this->service(false, $tool), self::event(), 'deployment-invalid', 'bad uuid'],
			[$this->service(null, $tool), self::event(), 'deployment-unknown', 'No LTI tool deployment dep-1 exists'],
			[$this->service(['deploymentId' => 'D-42'], $tool), self::event(), 'deployment-unknown', 'No LTI tool deployment dep-1 exists'],
			[$this->service($deployment, null), self::event(), 'tool-unknown', 'The deployment names a tool that is not registered'],
			[$this->service($deployment, null, 'suspended'), self::event(), 'tool-not-approved', 'The tool registration is suspended, not approved'],
		];
		foreach (['oidcLoginUrl', 'clientId', 'launchUrl'] as $missing) {
			$cases[] = [$this->service($deployment, [$missing => ''] + $tool), self::event(), 'tool-incomplete', 'The tool registration has no OIDC login URL, client id or launch URL'];
		}

		foreach ($cases as $index => [$service, $event, $code, $reason]) {
			$this->assertSame(['refusal' => ['code' => $code, 'reason' => $reason], 'login' => null], self::outcome($service, $event), 'case '.$index);
			$this->assertSame([], $this->hints, 'case '.$index);
		}

	}//end testRefusals()

	/**
	 * The allowed launch: the hint context and the login initiation form.
	 *
	 * @return void
	 */
	public function testLoginInitiation(): void {
		$service = $this->service(
			['deploymentId' => 'D-42', 'ltiToolId' => 'tool-1'],
			['oidcLoginUrl' => 'https://tool.example/login', 'clientId' => 'client', 'launchUrl' => 'https://tool.example/launch']
		);
		$this->assertSame(
			[
				'refusal' => null,
				'login' => [
					'formActionUrl' => 'https://tool.example/login',
					'method' => 'POST',
					'fields' => [
						'iss' => 'https://nc.example',
						'login_hint' => 'HINT',
						'lti_message_hint' => 'HINT',
						'target_link_uri' => 'https://tool.example/launch',
						'client_id' => 'client',
						'lti_deployment_id' => 'D-42',
					],
				],
			],
			self::outcome($service, self::event('LtiResourceLinkRequest', 'Instructor'))
		);
		$this->assertSame(
			[
				[
					'userId' => 'u-1',
					'placementId' => 'pl-1',
					'deploymentUuid' => 'dep-1',
					'messageType' => 'LtiResourceLinkRequest',
					'role' => 'Instructor',
					'contextId' => 'c-1',
					'contextTitle' => 'Biologie',
					'returnUrl' => 'https://nc.example/back',
				],
			],
			$this->hints
		);

	}//end testLoginInitiation()
}//end class
