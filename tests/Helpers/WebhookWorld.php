<?php

/**
 * The world of a signed public webhook on the consumer model.
 *
 * Builds on {@see DsoConnectionWorld}: the same fake OpenRegister, which
 * honours RBAC and records which account made every write, the same real
 * {@see \OCA\Integriq\Service\Dso\DsoConnection} and rights check. On top of
 * that it adds a consumer per {@see WebhookProfile}, the real
 * {@see WebhookGate}, a signer for the openconnector scheme and a request
 * whose raw body reaches the controller through `php://input`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\NullLogger;

/**
 * Consumers, gate, signer and request for webhook tests.
 */
trait WebhookWorld {
	use DsoConnectionWorld;

	/**
	 * The alerts raised, as `channel/reason`.
	 *
	 * @var list<string>
	 */
	protected array $webhookAlerts = [];

	/**
	 * A consumer of the webhook, holding the world secret and the account.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $userId  The account it acts as, or ''.
	 * @param string         $uuid    The consumer's uuid.
	 *
	 * @return void
	 */
	protected function addWebhookConsumer(WebhookProfile $profile, string $userId, string $uuid = 'consumer-webhook'): void {
		$this->worldConsumers[$uuid] = [
			'name' => $profile->label,
			'authorizationType' => $profile->authorizationType,
			'authorizationConfiguration' => ['scheme' => 'openconnector', 'secret' => $this->worldSecret],
			'userId' => $userId,
		];

	}//end addWebhookConsumer()

	/**
	 * The openconnector signature of a body under the world secret.
	 *
	 * @param string      $body   The raw body.
	 * @param string|null $secret Another secret, to forge a wrong signature.
	 *
	 * @return string The header value.
	 */
	protected function signWebhook(string $body, ?string $secret = null): string {
		$timestamp = time();

		return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, ($secret ?? $this->worldSecret));

	}//end signWebhook()

	/**
	 * The real gate over the world, recording its alerts.
	 *
	 * @param ORObjectService $objectService The world's ObjectService.
	 *
	 * @return WebhookGate The gate.
	 */
	protected function buildWorldGate(ORObjectService $objectService): WebhookGate {
		$this->webhookAlerts = [];
		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->webhookAlerts[] = $channel . '/' . $reason;
				return true;
			}
		);

		return new WebhookGate(
			webhooks: $this->buildWorldWebhookConnection(objectService: $objectService),
			alerts: $alerts,
			l: $this->webhookL10n(),
			logger: new NullLogger()
		);

	}//end buildWorldGate()

	/**
	 * A request carrying the signature header and the JSON params, with the raw body on php://input.
	 *
	 * @param string               $body      The raw body.
	 * @param string               $signature The X-OpenConnector-Signature value.
	 * @param array<string, mixed> $params    What Nextcloud decodes from a JSON body.
	 *
	 * @return IRequest The request.
	 */
	protected function webhookRequest(string $body, string $signature, array $params = []): IRequest {
		PhpInputStream::serve(body: $body);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (strcasecmp($name, 'X-OpenConnector-Signature') === 0 ? $signature : '')
		);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return $request;

	}//end webhookRequest()

	/**
	 * An IL10N that formats like Nextcloud's.
	 *
	 * @return IL10N
	 */
	protected function webhookL10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return $l;

	}//end webhookL10n()

	/**
	 * The writes to one schema, as `action by uid`.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<string>
	 */
	protected function writesTo(string $schema): array {
		$writes = [];
		foreach ($this->worldWrites as $write) {
			if ($write['schema'] === $schema) {
				$writes[] = $write['action'] . ' by ' . ($write['uid'] ?? 'nobody');
			}
		}

		return $writes;

	}//end writesTo()
}//end trait
