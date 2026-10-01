<?php

/**
 * Sends one ZGW request for the case-system mapping through CallService.
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use Psr\Container\ContainerInterface;

/**
 * The transport under the ZGW mapping.
 *
 * Every request goes through CallService on an ordinary source the
 * administrator named, so its auth (jwt-zgw), call log and rate limit apply.
 * CallService is resolved when a request is sent, not when this class is
 * built, because CallService itself holds the case-system operations.
 *
 * An absolute address (a case or document url a caller passes back) is only
 * sent when it lies under the named source's location: a caller cannot point
 * the source's credentials at another host.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */
class CallServiceCaseSystemTransport {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves CallService on first use.
	 * @param ConnectionStore    $store     Reads the named sources.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ConnectionStore $store,
	) {
	}//end __construct()

	/**
	 * Send one request on a named source.
	 *
	 * @param string              $sourceId The uuid of the source to send on.
	 * @param string              $method   The HTTP method.
	 * @param string              $address  A path on the source, or an absolute url under its location.
	 * @param array<string,mixed> $options  Request options (json, query, headers).
	 *
	 * @return array{status:int,data:mixed,raw:string} The status, the decoded body and the raw body.
	 *
	 * @throws CaseSystemRefusal When the source is missing or the address lies outside it.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public function send(string $sourceId, string $method, string $address, array $options = []): array {
		$source = $this->store->findSource(uuid: $sourceId);
		if ($source === null) {
			throw new CaseSystemRefusal(status: 409, message: 'The case system names source ' . $sourceId . ', which does not exist.');
		}

		$location = (string)($source->getObject()['location'] ?? '');
		$callService = $this->container->get(CallService::class);
		$log = $callService->call(
			source: $source,
			endpoint: self::endpointOf(address: $address, location: $location),
			method: $method,
			config: $options
		)->getObject();

		return self::answerOf(log: $log);
	}//end send()

	/**
	 * The endpoint on the source for an address.
	 *
	 * @param string $address  A path, or an absolute url.
	 * @param string $location The source's location.
	 *
	 * @return string The path to append to the location.
	 *
	 * @throws CaseSystemRefusal When an absolute url lies outside the location.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public static function endpointOf(string $address, string $location): string {
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) !== 1) {
			return $address;
		}

		$base = rtrim($location, '/');
		if ($base !== '' && ($address === $base || str_starts_with($address, $base . '/') === true)) {
			return substr($address, strlen($base));
		}

		throw new CaseSystemRefusal(
			status: 422,
			message: 'The address ' . $address . ' is not on the API this case system is set up for.'
		);
	}//end endpointOf()

	/**
	 * Read a call log into a status, decoded body and raw body.
	 *
	 * A call log that CallService wrote before any request (a disabled
	 * source, for one) carries no response; its status message becomes the
	 * detail, so the caller reads why.
	 *
	 * @param array<string,mixed> $log The call log object.
	 *
	 * @return array{status:int,data:mixed,raw:string}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
	 */
	public static function answerOf(array $log): array {
		$response = ($log['response'] ?? null);
		if (is_array($response) === false) {
			return [
				'status' => (int)($log['statusCode'] ?? 502),
				'data' => ['detail' => (string)($log['statusMessage'] ?? '')],
				'raw' => '',
			];
		}

		$raw = (string)($response['body'] ?? '');
		if (($response['encoding'] ?? 'UTF-8') === 'base64') {
			$raw = (string)base64_decode($raw, true);
		}

		return [
			'status' => (int)($response['statusCode'] ?? ($log['statusCode'] ?? 502)),
			'data' => json_decode($raw, true),
			'raw' => $raw,
		];
	}//end answerOf()
}//end class
