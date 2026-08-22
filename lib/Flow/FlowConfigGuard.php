<?php

/**
 * Integriq Flow Config Guard.
 *
 * The save-time validation both contributed node types share: what a node's
 * configuration may NOT contain, and what an endpoint path may NOT be.
 *
 * Two hard boundaries live here, and they are the reason a governed
 * `source-call` node is worth building at all instead of a `http-request` node
 * that takes a URL:
 *
 *  1. **No target but a Source.** No url, uri, host, scheme, port or location
 *     field is accepted, and an endpoint may not be absolute, scheme-relative
 *     or traversal-bearing. A flow document is editable by every flow author;
 *     a URL field in one is an SSRF primitive with an audit trail.
 *  2. **No secret, ever.** No token, password, API key, bearer or
 *     header-authentication field is accepted. Credentials come solely from the
 *     Source's `authentication.credentialRef`, resolved by OpenRegister's
 *     credential broker — administrator-controlled configuration, not
 *     author-controlled.
 *
 * A third boundary — no owner / run-as field — is enforced here too; see
 * {@see FlowOwner} for why.
 *
 * Rejection is at flow-SAVE time and it is a rejection, never a silent ignore:
 * an author who wrote a token into a step must be told it does not belong
 * there, not left believing it is in use.
 *
 * NOTE ON SCOPE: this class is an addition to the file list in the change's
 * `design.md`, which folded these checks into the two node classes. It is
 * factored out because both nodes need byte-identical rules and a divergence
 * between them would be a security defect, not a style inconsistency.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCP\IL10N;
use UnexpectedValueException;

/**
 * Shared save-time configuration rules for Integriq's flow nodes.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-3-explicit-failure-fail-closed-attribution-validation-and-scope
 */
final class FlowConfigGuard {

	/**
	 * Escape code: the endpoint carries a control character or newline.
	 *
	 * @var string
	 */
	public const ESCAPE_CONTROL_CHARACTER = 'control-character';

	/**
	 * Escape code: the endpoint is scheme-relative (`//host`, `\\host`).
	 *
	 * @var string
	 */
	public const ESCAPE_SCHEME_RELATIVE = 'scheme-relative';

	/**
	 * Escape code: the endpoint is an absolute URL (it carries a scheme).
	 *
	 * @var string
	 */
	public const ESCAPE_ABSOLUTE_URL = 'absolute-url';

	/**
	 * Escape code: the endpoint percent-decodes to a scheme-relative reference.
	 *
	 * @var string
	 */
	public const ESCAPE_DECODED_SCHEME_RELATIVE = 'decoded-scheme-relative';

	/**
	 * Escape code: the endpoint leaves its Source location via `../`.
	 *
	 * @var string
	 */
	public const ESCAPE_PATH_TRAVERSAL = 'path-traversal';

	/**
	 * Config keys that would let a flow document name an outbound target.
	 *
	 * Matched on the key's normalised form (lower-cased, non-alphanumerics
	 * stripped), so `base_url`, `baseUrl` and `BaseURL` are one rule.
	 *
	 * @var array<int, string>
	 */
	private const TARGET_KEYS = [
		'url',
		'uri',
		'host',
		'hostname',
		'scheme',
		'protocol',
		'port',
		'baseurl',
		'baseuri',
		'location',
		'address',
		'origin',
		'proxy',
	];

	/**
	 * Config keys that would let a flow document name a run-as identity.
	 *
	 * @var array<int, string>
	 */
	private const IDENTITY_KEYS = [
		'owner',
		'user',
		'userid',
		'uid',
		'username',
		'runas',
		'actinguser',
		'actinguserid',
		'impersonate',
		'triggeredby',
		'onbehalfof',
	];

	/**
	 * Substrings that mark a config key as credential-bearing.
	 *
	 * Substring rather than exact match: the point is to catch
	 * `githubToken`, `api_key_2`, `basicAuthPassword` and everything else an
	 * author will invent, not to enumerate them.
	 *
	 * @var array<int, string>
	 */
	private const CREDENTIAL_FRAGMENTS = [
		'token',
		'password',
		'passwd',
		'passphrase',
		'secret',
		'apikey',
		'bearer',
		'credential',
		'authorization',
		'authentication',
		'privatekey',
		'clientid',
		'certificate',
	];

	/**
	 * Request header names a node may never set itself.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_HEADERS = [
		'authorization',
		'proxyauthorization',
		'cookie',
		'setcookie',
		'xapikey',
		'apikey',
	];

	/**
	 * Item keys reserved by `FlowItems`, which response content may not claim.
	 *
	 * @var array<int, string>
	 */
	public const RESERVED_ITEM_KEYS = [
		'json',
		'binary',
		'pairedItem',
		'output',
	];

	/**
	 * Reject a configuration carrying a target, identity or credential field.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a forbidden field is present.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertNoForbiddenFields(array $config, IL10N $l10n): void {
		foreach (array_keys($config) as $key) {
			if (is_string($key) === false) {
				continue;
			}

			$normalised = self::normaliseKey(key: $key);

			if (in_array($normalised, self::TARGET_KEYS, true) === true) {
				throw new UnexpectedValueException(
					$l10n->t(
						'The "%1$s" field is not accepted: a flow step names a configured Source and a relative '
						. 'endpoint, never a URL, host, scheme or port.',
						[$key]
					)
				);
			}

			if (in_array($normalised, self::IDENTITY_KEYS, true) === true) {
				throw new UnexpectedValueException(
					$l10n->t(
						'The "%1$s" field is not accepted: a step always runs as the flow run owner, and naming '
						. 'another identity in a flow document would be a privilege escalation.',
						[$key]
					)
				);
			}

			foreach (self::CREDENTIAL_FRAGMENTS as $fragment) {
				if (str_contains($normalised, $fragment) === true) {
					throw new UnexpectedValueException(
						$l10n->t(
							'The "%1$s" field is not accepted: credentials belong on the Source, resolved through '
							. 'the OpenRegister credential broker, never in a flow document.',
							[$key]
						)
					);
				}
			}
		}//end foreach

		self::assertNoAuthHeaders(config: $config, l10n: $l10n);

	}//end assertNoForbiddenFields()

	/**
	 * Reject an authentication header set from node configuration.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an authentication header is present.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private static function assertNoAuthHeaders(array $config, IL10N $l10n): void {
		$headers = ($config['headers'] ?? []);
		if (is_array($headers) === false) {
			return;
		}

		foreach (array_keys($headers) as $name) {
			if (is_string($name) === false) {
				continue;
			}

			if (in_array(self::normaliseKey(key: $name), self::FORBIDDEN_HEADERS, true) === true) {
				throw new UnexpectedValueException(
					$l10n->t(
						'The "%1$s" request header may not be set on a flow step: authentication comes from the '
						. 'Source through the OpenRegister credential broker.',
						[$name]
					)
				);
			}
		}

	}//end assertNoAuthHeaders()

	/**
	 * Reject an output key that would claim a reserved `FlowItems` key.
	 *
	 * @param string $outputKey The authored output key.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the key is reserved.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertOutputKeyAllowed(string $outputKey, IL10N $l10n): void {
		if (trim($outputKey) === '') {
			throw new UnexpectedValueException(
				$l10n->t('The "output" field must name a non-empty item key.')
			);
		}

		$first = explode('.', $outputKey)[0];
		if (in_array($first, self::RESERVED_ITEM_KEYS, true) === true) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "output" key "%1$s" is reserved by the flow item shape and may not carry response content.',
					[$outputKey]
				)
			);
		}

	}//end assertOutputKeyAllowed()

	/**
	 * Reject an endpoint that is not contained by its Source's location.
	 *
	 * Applied TWICE by the calling node: once to the literal authored value at
	 * flow-save time, and again to the RENDERED value at execute time. The
	 * second pass is not belt-and-braces — an endpoint may carry
	 * `{{dotted.path}}` placeholders, so `"/issues/{{issue.ref}}"` is a
	 * perfectly containable literal that renders to `../../evil` on an item
	 * whose data says so. Only the rendered check can see that.
	 *
	 * @param string $endpoint The endpoint value (literal or rendered).
	 * @param IL10N $l10n Translations for the rejection message.
	 * @param bool $rendered Whether this is the execute-time rendered pass.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the endpoint escapes the Source.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertEndpointContained(string $endpoint, IL10N $l10n, bool $rendered = false): void {
		$subject = trim($endpoint);

		if ($subject === '') {
			throw new UnexpectedValueException(
				$l10n->t('The "endpoint" field must name a path relative to the Source.')
			);
		}

		$reason = self::endpointEscapeReason(endpoint: $subject, l10n: $l10n);
		if ($reason === null) {
			return;
		}

		if ($rendered === true) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The rendered endpoint "%1$s" is refused before any request is made: it must stay relative '
					. 'to the Source location (%2$s).',
					[$subject, $reason]
				)
			);
		}

		throw new UnexpectedValueException(
			$l10n->t(
				'The "endpoint" field must be a path relative to the Source, not "%1$s" (%2$s).',
				[$subject, $reason]
			)
		);

	}//end assertEndpointContained()

	/**
	 * Name the reason an endpoint escapes its Source, or null when contained.
	 *
	 * The reason is translated here rather than returned as an English tag,
	 * because it is interpolated into a user-facing validation message and a
	 * half-translated sentence is not a translated one (ADR-007).
	 *
	 * @param string $endpoint The endpoint value.
	 * @param IL10N $l10n Translations for the reason.
	 *
	 * @return string|null The reason, or null when the endpoint is contained.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private static function endpointEscapeReason(string $endpoint, IL10N $l10n): ?string {
		return match (self::endpointEscapeCode(endpoint: $endpoint)) {
			self::ESCAPE_CONTROL_CHARACTER => $l10n->t('it contains a control character'),
			self::ESCAPE_SCHEME_RELATIVE => $l10n->t('it is scheme-relative and would name another host'),
			self::ESCAPE_ABSOLUTE_URL => $l10n->t('it is an absolute URL'),
			self::ESCAPE_DECODED_SCHEME_RELATIVE => $l10n->t('it decodes to a scheme-relative reference'),
			self::ESCAPE_PATH_TRAVERSAL => $l10n->t('it escapes the Source location through path traversal'),
			default => null,
		};

	}//end endpointEscapeReason()

	/**
	 * Name, as a stable machine code, the reason an endpoint escapes its
	 * Source — or null when the endpoint is contained.
	 *
	 * THE RULES LIVE HERE AND ONLY HERE. This is the single containment
	 * predicate for the whole app: {@see endpointEscapeReason()} translates its
	 * verdict for the flow-node validation messages, and
	 * {@see \OCA\Integriq\Service\CallService::renderEndpointPath()} calls
	 * it directly (it has no IL10N and needs a verdict, not a sentence) for the
	 * templated `targetType: api` upstream path (ocon#1069). The class docblock
	 * already states that a divergence between two copies of these rules would
	 * be a security defect rather than a style inconsistency; a shared,
	 * translation-free predicate is how that is prevented.
	 *
	 * @param string $endpoint The endpoint value (literal or rendered).
	 *
	 * @return string|null One of the ESCAPE_* codes, or null when contained.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function endpointEscapeCode(string $endpoint): ?string {
		// A control character or a newline would let an endpoint smuggle a
		// second request line or an extra header past the HTTP client.
		if (preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
			return self::ESCAPE_CONTROL_CHARACTER;
		}

		if (str_starts_with($endpoint, '//') === true || str_starts_with($endpoint, '\\\\') === true) {
			return self::ESCAPE_SCHEME_RELATIVE;
		}

		if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $endpoint) === 1) {
			return self::ESCAPE_ABSOLUTE_URL;
		}

		$decoded = strtolower(rawurldecode($endpoint));
		if ($decoded !== strtolower($endpoint)) {
			// Re-run the host-ish checks over the percent-decoded form so an
			// encoded `%2f%2f` or `%2e%2e%2f` cannot slip through.
			if (str_starts_with($decoded, '//') === true) {
				return self::ESCAPE_DECODED_SCHEME_RELATIVE;
			}
		}

		if ($decoded === '..'
			|| str_starts_with($decoded, '../') === true
			|| str_contains($decoded, '/../') === true
			|| str_ends_with($decoded, '/..') === true
		) {
			return self::ESCAPE_PATH_TRAVERSAL;
		}

		return null;
	}//end endpointEscapeCode()

	/**
	 * Normalise a config key or header name for rule matching.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The lower-cased, alphanumeric-only form.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private static function normaliseKey(string $key): string {
		return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $key));
	}//end normaliseKey()
}//end class
