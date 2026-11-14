<?php

/**
 * Integriq SMS Status Controller.
 *
 * The public route a CM.com, MessageBird or Twilio source's delivery callbacks
 * arrive on: `POST /api/sms/{provider}/status/{token}`. The token, generated
 * per source when the source is saved, is checked in constant time before the
 * body is read; the vendor's signature is checked after, where the vendor
 * signs. Every refusal is the same undifferentiated 401.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\Integriq\Service\SmsStatusCallbackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Receives vendor SMS delivery callbacks.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsStatusController extends Controller {

	/**
	 * Headers a vendor signs with, handed to the binding.
	 *
	 * @var list<string>
	 */
	private const SIGNATURE_HEADERS = ['X-Twilio-Signature', 'MessageBird-Signature-JWT'];

	/**
	 * Constructor.
	 *
	 * @param string                   $appName   The app id.
	 * @param IRequest                 $request   The request.
	 * @param SmsStatusCallbackService $callbacks Finds the source and applies the callback.
	 * @param LoggerInterface          $logger    Logs a refused callback, never its token or key.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SmsStatusCallbackService $callbacks,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive one delivery callback for a source.
	 *
	 * @param string $provider The provider id the source sends through.
	 * @param string $token    The source's callback token.
	 *
	 * @return JSONResponse `{received: true}`, or 401 for a wrong token, a bad signature or an unreadable callback.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function status(string $provider = '', string $token = ''): JSONResponse {
		$source = $this->callbacks->sourceFor(provider: $provider, token: $token);
		if ($source === null) {
			// Refused before the body is read; the answer never says which part failed.
			return self::refused();
		}

		$headers = [];
		foreach (self::SIGNATURE_HEADERS as $name) {
			$headers[strtolower($name)] = (string)$this->request->getHeader($name);
		}

		try {
			$this->callbacks->receive(
				source: $source,
				callback: new SmsStatusCallback(
					url: $this->requestUrl(),
					rawBody: $this->getRawContent(),
					params: $this->callbackParams(),
					headers: $headers
				)
			);
		} catch (SmsProviderException $exception) {
			$this->logger->warning(
				'[SmsStatusController] status callback refused',
				['provider' => $provider, 'reason' => $exception->getErrorCode()]
			);
			return self::refused();
		}

		return new JSONResponse(['received' => true]);

	}//end status()

	/**
	 * The undifferentiated refusal.
	 *
	 * @return JSONResponse 401.
	 */
	private static function refused(): JSONResponse {
		return new JSONResponse(['error' => 'refused'], Http::STATUS_UNAUTHORIZED);

	}//end refused()

	/**
	 * The callback's parameters, without the route's own.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function callbackParams(): array {
		$params = $this->request->getParams();
		unset($params['provider'], $params['token'], $params['_route']);

		return $params;

	}//end callbackParams()

	/**
	 * The absolute URL the vendor posted to, as a signature covers it.
	 *
	 * @return string The URL.
	 */
	private function requestUrl(): string {
		return $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $this->request->getRequestUri();

	}//end requestUrl()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 */
	protected function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;

	}//end getRawContent()
}//end class
