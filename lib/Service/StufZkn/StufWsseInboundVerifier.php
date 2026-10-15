<?php

/**
 * Integriq StUF WS-Security Inbound Verifier.
 *
 * Checks the UsernameToken of an inbound StUF envelope against a source's
 * credentials: user name and password, and, when the token carries a `Nonce`
 * and a `Created`, that it is fresh and has not been seen before. The public
 * StUF routes (provider requests and ZDS confirmations) authenticate with a
 * plaintext password, so without this a captured request replays for as long
 * as the password stays.
 *
 * The protection is transitional: a token without `Nonce` and `Created` is
 * accepted unless the source sets `authentication.wsse.requireNonce`, and an
 * attacker can strip both elements from a captured request. Sources should set
 * `requireNonce`; PasswordDigest is the follow-up.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DateTimeImmutable;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Verifies an inbound UsernameToken, with a replay window.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */
class StufWsseInboundVerifier {

	/**
	 * Seconds a token's `Created` may differ from now, either way.
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 300;

	/**
	 * Cache namespace of the nonces seen.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'integriq-stuf-nonce';

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory Holds the nonces seen inside the window.
	 * @param LoggerInterface $logger       The logger.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the envelope's UsernameToken matches the source's and is not a replay.
	 *
	 * Without a `Nonce` and a `Created` the token is accepted on its credentials
	 * alone, unless the source sets `authentication.wsse.requireNonce`. With
	 * them, `Created` must lie within WINDOW_SECONDS of now and the nonce must not
	 * have been used by this source and user inside the window. A token with
	 * only one of the two is refused.
	 *
	 * @param SimpleXMLElement                $root          The envelope.
	 * @param array{username:string,password:string} $token  The source's decrypted token.
	 * @param array<string,mixed>             $configuration The source's `configuration`.
	 * @param string                          $sourceId      The source id.
	 *
	 * @return bool True when the token is valid and fresh.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	public function verify(SimpleXMLElement $root, array $token, array $configuration, string $sourceId): bool {
		if ($token['username'] === '' || $token['password'] === '') {
			return false;
		}

		$username = $this->text(root: $root, name: 'Username');
		$password = $this->text(root: $root, name: 'Password');
		if (hash_equals($token['username'], $username) === false || hash_equals($token['password'], $password) === false) {
			return false;
		}

		$nonce   = $this->text(root: $root, name: 'Nonce');
		$created = $this->text(root: $root, name: 'Created');
		if ($nonce === '' && $created === '') {
			return (($configuration['authentication']['wsse']['requireNonce'] ?? false) !== true);
		}

		if ($nonce === '' || $created === '' || $this->isFresh(created: $created) === false) {
			return false;
		}

		$required = (($configuration['authentication']['wsse']['requireNonce'] ?? false) === true);

		return $this->firstUse(sourceId: $sourceId, username: $username, nonce: $nonce, required: $required);
	}//end verify()

	/**
	 * Whether a `Created` lies inside the window around now.
	 *
	 * @param string $created The `wsu:Created` text.
	 *
	 * @return bool True when it parses and is fresh.
	 */
	private function isFresh(string $created): bool {
		try {
			$at = (new DateTimeImmutable($created))->getTimestamp();
		} catch (Throwable $exception) {
			return false;
		}

		return (abs(time() - $at) <= self::WINDOW_SECONDS);
	}//end isFresh()

	/**
	 * Record a nonce and say whether this is its first use inside the window.
	 *
	 * With no shared cache to remember it in, a repeat cannot be told from a first
	 * use, so the nonce is not trusted: the token is accepted only when the source
	 * does not require one, and the gap is logged.
	 *
	 * @param string $sourceId The source id.
	 * @param string $username The token's user name.
	 * @param string $nonce    The token's nonce.
	 * @param bool   $required Whether the source requires a nonce.
	 *
	 * @return bool True when the nonce is new.
	 */
	private function firstUse(string $sourceId, string $username, string $nonce, bool $required): bool {
		$key   = hash('sha256', $sourceId . '|' . $username . '|' . $nonce);
		$ttl   = (2 * self::WINDOW_SECONDS);
		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);

		if ($cache instanceof IMemcache) {
			return ($cache->add($key, 1, $ttl) === true);
		}

		if ($this->cacheFactory->isAvailable() === false || $cache instanceof ICache === false) {
			$this->logger->warning('StUF inbound nonce not checked for source {id}: no distributed cache is available', ['id' => $sourceId]);
			return ($required === false);
		}

		if ($cache->hasKey($key) === true) {
			return false;
		}

		$cache->set($key, 1, $ttl);

		return true;
	}//end firstUse()

	/**
	 * The trimmed text of a UsernameToken child.
	 *
	 * @param SimpleXMLElement $root The envelope.
	 * @param string           $name The child's local name.
	 *
	 * @return string The text, '' when absent.
	 */
	private function text(SimpleXMLElement $root, string $name): string {
		$nodes = $root->xpath('//*[local-name()="Security"]/*[local-name()="UsernameToken"]/*[local-name()="' . $name . '"]');
		if (is_array($nodes) === false || isset($nodes[0]) === false) {
			return '';
		}

		return trim((string)$nodes[0]);
	}//end text()
}//end class
