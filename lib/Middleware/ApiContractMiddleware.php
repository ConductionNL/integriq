<?php

/**
 * Integriq API contract middleware.
 *
 * Applies the NL API Design Rules that hold for every response of the app's
 * JSON API: the `API-Version` header (/core/version-header), RFC 9457 problem
 * details for error responses (/core/error-handling/problem-details) and, on an
 * unversioned path, the announcement of its `/api/v1/` successor
 * (/core/uri-version, /core/deprecation-schedule).
 *
 * @category  Middleware
 * @package   OCA\Integriq\Middleware
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/api-contract/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Middleware;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Middleware\Support\ApiPathAliases;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Stamps `API-Version` on API responses, marks an unversioned path deprecated
 * in favour of its `/api/v1/` alias, and turns JSON error bodies into problem
 * details. Paths that speak a protocol defined elsewhere
 * ({@see StandardProtocolPaths}) are not deprecated.
 *
 * An error body keeps every member it already had, so existing callers that
 * read `error` or `message` see no change. A body that already uses `type`,
 * `title` or `status` for something else is left as it is, because rewriting
 * those members would break its callers.
 *
 * @category Middleware
 * @package  OCA\Integriq\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/api-contract/spec.md
 */
class ApiContractMiddleware extends Middleware {

	/**
	 * The path fragment that marks a request as part of the JSON API.
	 *
	 * @var string
	 */
	private const API_PATH_MARKER = '/api/';

	/**
	 * API paths whose error format an external standard fixes: SCIM 2.0
	 * (RFC 7644) and the OAuth 2.0 token and credential endpoints of
	 * OpenID4VCI and LTI (RFC 6749). Their errors keep that format.
	 *
	 * @var list<string>
	 */
	private const FOREIGN_ERROR_FORMATS = [
		'#/api/(v1/)?scim/#',
		'#/api/(v1/)?eudi/(token|credential)$#',
		'#/api/(v1/)?lti/token$#',
	];

	/**
	 * The media type of an RFC 9457 problem details document.
	 *
	 * @var string
	 */
	public const PROBLEM_CONTENT_TYPE = 'application/problem+json; charset=utf-8';

	/**
	 * The HTTP reason phrase used as `title` for each error status.
	 *
	 * @var array<int,string>
	 */
	private const TITLES = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		409 => 'Conflict',
		410 => 'Gone',
		412 => 'Precondition Failed',
		413 => 'Content Too Large',
		415 => 'Unsupported Media Type',
		422 => 'Unprocessable Content',
		423 => 'Locked',
		424 => 'Failed Dependency',
		428 => 'Precondition Required',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest    $request    The incoming request.
	 * @param IAppManager $appManager Resolves the installed app version.
	 *
	 * @psalm-suppress PossiblyUnusedMethod Instantiated via Nextcloud dependency injection.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Add the version header and, for a JSON error, the problem details shape.
	 *
	 * @param mixed    $controller The controller that ran.
	 * @param string   $methodName The method that ran.
	 * @param Response $response   The controller's response.
	 *
	 * @return Response The same response, decorated.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The Middleware contract fixes this signature.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		return $this->decorate(response: $response);
	}//end afterController()

	/**
	 * Decorate a response when the request is part of the JSON API.
	 *
	 * @param Response $response The controller's response.
	 *
	 * @return Response The same response, decorated.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function decorate(Response $response): Response {
		if ($this->isApiRequest() === false) {
			return $response;
		}

		$response->addHeader(name: 'API-Version', value: $this->appManager->getAppVersion(appId: AppIdentity::APP_ID));
		$this->announceSuccessor(response: $response);
		if ($response instanceof JSONResponse) {
			$this->decorateError(response: $response);
		}

		return $response;
	}//end decorate()

	/**
	 * Whether the request path lies under the app's `/api/`.
	 *
	 * @return bool True for an API request.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function isApiRequest(): bool {
		$path = $this->request->getPathInfo();

		return is_string($path) === true && str_contains($path, self::API_PATH_MARKER) === true;
	}//end isApiRequest()

	/**
	 * Whether the request path belongs to a protocol with its own error format.
	 *
	 * @return bool True when errors must keep the protocol's format.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function hasForeignErrorFormat(): bool {
		$path = (string) $this->request->getPathInfo();
		foreach (self::FOREIGN_ERROR_FORMATS as $pattern) {
			if (preg_match($pattern, $path) === 1) {
				return true;
			}
		}

		return false;
	}//end hasForeignErrorFormat()

	/**
	 * Add the RFC 9745 `Deprecation` header and a `Link` to the successor on an unversioned path.
	 *
	 * The path keeps answering exactly as before; only the headers are added.
	 *
	 * @param Response $response The response.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ApiPathAliases is a pure lookup that routes.php shares, outside any container.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function announceSuccessor(Response $response): void {
		$path      = (string) $this->request->getPathInfo();
		$successor = ApiPathAliases::successorOf(path: $path);
		if ($successor === null) {
			return;
		}

		$response->addHeader(name: 'Deprecation', value: ApiPathAliases::DEPRECATED_SINCE);
		$response->addHeader(
			name: 'Link',
			value: '<'.$this->scriptPrefix(pathInfo: $path).$successor.'>; rel="successor-version"'
		);
	}//end announceSuccessor()

	/**
	 * The front controller that precedes the path info in the request URI, such as `/index.php`.
	 *
	 * @param string $pathInfo The request path info.
	 *
	 * @return string The prefix, or '' when the URI carries none.
	 */
	private function scriptPrefix(string $pathInfo): string {
		$uri      = $this->request->getRequestUri();
		$position = strpos($uri, $pathInfo);
		if ($pathInfo === '' || $position === false) {
			return '';
		}

		return substr($uri, 0, $position);
	}//end scriptPrefix()

	/**
	 * Serve a JSON error response as problem details when its body allows it.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function decorateError(JSONResponse $response): void {
		if ($response->getStatus() < 400 || $this->hasForeignErrorFormat() === true) {
			return;
		}

		$problem = $this->problemFor(data: $response->getData(), status: $response->getStatus());
		if ($problem === []) {
			return;
		}

		$response->setData(data: $problem);
		$response->addHeader(name: 'Content-Type', value: self::PROBLEM_CONTENT_TYPE);
	}//end decorateError()

	/**
	 * The problem details form of an error body, or an empty array when it cannot take that form.
	 *
	 * @param mixed $data   The error body.
	 * @param int   $status The response status.
	 *
	 * @return array<array-key,mixed> The body with the problem members first, or an empty array.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function problemFor(mixed $data, int $status): array {
		if (is_array($data) === false || $this->isListBody(data: $data) === true) {
			return [];
		}

		$problem = [
			'type'   => 'about:blank',
			'title'  => $this->titleOf(status: $status),
			'status' => $status,
		];
		if ($this->conflicts(problem: $problem, data: $data) === true) {
			return [];
		}

		$detail = $this->detailOf(data: $data);
		if ($detail !== null) {
			$problem['detail'] = $detail;
		}

		return array_merge($problem, $data);
	}//end problemFor()

	/**
	 * The HTTP reason phrase of an error status.
	 *
	 * @param int $status The response status.
	 *
	 * @return string The reason phrase, or `Error` for a status without one here.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function titleOf(int $status): string {
		return (self::TITLES[$status] ?? 'Error');
	}//end titleOf()

	/**
	 * Whether an error body is a non-empty JSON list, which has no members to keep.
	 *
	 * @param array<array-key,mixed> $data The error body.
	 *
	 * @return bool True for a list.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function isListBody(array $data): bool {
		return $data !== [] && array_is_list($data) === true;
	}//end isListBody()

	/**
	 * Whether the body already uses a problem member with another value.
	 *
	 * @param array<string,int|string> $problem The problem members.
	 * @param array<array-key,mixed>   $data    The error body.
	 *
	 * @return bool True when rewriting the body would change one of its members.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function conflicts(array $problem, array $data): bool {
		foreach (array_keys($problem) as $member) {
			if (array_key_exists($member, $data) === true && $data[$member] !== $problem[$member]) {
				return true;
			}
		}

		return false;
	}//end conflicts()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty string.
	 *
	 * @psalm-assert-if-true non-empty-string $value
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function isText(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isText()

	/**
	 * The human-readable explanation already present in an error body, if any.
	 *
	 * @param array<array-key,mixed> $data The error body.
	 *
	 * @return string|null The `detail`, `message` or `error` text, or null.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	private function detailOf(array $data): ?string {
		foreach (['detail', 'message', 'error'] as $member) {
			$text = ($data[$member] ?? null);
			if ($this->isText(value: $text) === true) {
				return $text;
			}
		}

		return null;
	}//end detailOf()
}//end class
