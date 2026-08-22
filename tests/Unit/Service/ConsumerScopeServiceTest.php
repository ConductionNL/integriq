<?php

/**
 * Security unit tests for consumer source-scope enforcement.
 *
 * Proves REQ-CON-SCOPE-001: a caller outside a configured `ips`/`domains`
 * allowlist is rejected (fail-closed); a consumer with no allowlist keeps
 * working (no regression); an empty-but-present allowlist does NOT allow-all;
 * and `domains` is matched on forward-confirmed reverse DNS rather than on any
 * caller-controlled header.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\Scope\IpMatcher;
use OCA\Integriq\Service\Scope\ReverseDnsResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Consumer source-scope enforcement (REQ-CON-SCOPE-001).
 */
class ConsumerScopeServiceTest extends TestCase {

	/**
	 * Build the service under test with a stubbed DNS seam.
	 *
	 * Uses the REAL IpMatcher — the address arithmetic is exactly what these
	 * tests are asserting — and stubs only the DNS round-trip, via a
	 * ReverseDnsResolver subclass overriding its lookup seams. That keeps the
	 * forward-confirmation logic itself under test rather than mocked away.
	 *
	 * @param array $ptr Map of ip => PTR hostname.
	 * @param array $forward Map of hostname => list of addresses it forward-resolves to.
	 *
	 * @return ConsumerScopeService The service under test.
	 */
	private function makeService(array $ptr = [], array $forward = []): ConsumerScopeService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$ipMatcher = new IpMatcher();

		$resolver = new class($cacheFactory, $ipMatcher, $ptr, $forward) extends ReverseDnsResolver {

			/**
			 * @param ICacheFactory $cacheFactory Cache factory.
			 * @param IpMatcher $ipMatcher Real matcher.
			 * @param array $ptr Stubbed reverse-DNS map.
			 * @param array $forward Stubbed forward-DNS map.
			 */
			public function __construct(
				ICacheFactory $cacheFactory,
				IpMatcher $ipMatcher,
				private array $ptr,
				private array $forward,
			) {
				parent::__construct($cacheFactory, $ipMatcher);
			}

			/**
			 * @param string $address The client IP.
			 *
			 * @return string|null The stubbed PTR hostname.
			 */
			protected function reverseLookup(string $address): ?string {
				return ($this->ptr[$address] ?? null);
			}

			/**
			 * @param string $hostname The hostname.
			 *
			 * @return array The stubbed forward addresses.
			 */
			protected function forwardLookup(string $hostname): array {
				return ($this->forward[$hostname] ?? []);
			}
		};

		return new ConsumerScopeService($ipMatcher, $resolver, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * Build a consumer entity with the given payload.
	 *
	 * @param array $object The consumer object payload.
	 *
	 * @return ObjectEntity The hydrated consumer entity.
	 */
	private function consumer(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		$entity->setUuid('consumer-1');
		return $entity;
	}//end consumer()

	/**
	 * Build a request reporting the given client IP.
	 *
	 * @param string $ip The IP IRequest::getRemoteAddress() returns.
	 *
	 * @return IRequest The mocked request.
	 */
	private function request(string $ip): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn($ip);
		return $request;
	}//end request()

	/**
	 * BAD PATH: a caller outside a configured IP allowlist is rejected.
	 *
	 * @return void
	 */
	public function testIpOutsideConfiguredAllowlistIsRejected(): void {
		$service = $this->makeService();
		$consumer = $this->consumer(['name' => 'partner-a', 'ips' => ['203.0.113.4', '10.0.0.0/8']]);

		$this->assertFalse(
			$service->isAllowed($consumer, $this->request('198.51.100.9')),
			'A source IP outside the configured allowlist MUST be rejected (403).'
		);
	}//end testIpOutsideConfiguredAllowlistIsRejected()

	/**
	 * NO REGRESSION: a consumer with no allowlist configured is unrestricted.
	 *
	 * @return void
	 */
	public function testConsumerWithoutAllowlistIsUnrestricted(): void {
		$service = $this->makeService();
		$consumer = $this->consumer(['name' => 'legacy-partner']);

		$this->assertTrue(
			$service->isAllowed($consumer, $this->request('198.51.100.9')),
			'A consumer that predates this control (no ips/domains) MUST keep working.'
		);
	}//end testConsumerWithoutAllowlistIsUnrestricted()

	/**
	 * BAD PATH: an empty-but-present allowlist must NOT allow everything.
	 *
	 * @return void
	 */
	public function testEmptyButPresentAllowlistDoesNotAllowAll(): void {
		$service = $this->makeService();

		$this->assertFalse(
			$service->isAllowed($this->consumer(['name' => 'p', 'ips' => []]), $this->request('203.0.113.4')),
			'An empty ips list matches nothing and MUST NOT allow-all.'
		);

		$this->assertFalse(
			$service->isAllowed($this->consumer(['name' => 'p', 'domains' => []]), $this->request('203.0.113.4')),
			'An empty domains list matches nothing and MUST NOT allow-all.'
		);

		$this->assertFalse(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'ips' => [], 'domains' => []]),
				$this->request('203.0.113.4')
			),
			'Two empty lists MUST NOT allow-all.'
		);
	}//end testEmptyButPresentAllowlistDoesNotAllowAll()

	/**
	 * BAD PATH: a caller whose confirmed hostname is outside `domains` is rejected.
	 *
	 * @return void
	 */
	public function testDomainOutsideConfiguredAllowlistIsRejected(): void {
		$service = $this->makeService(
			ptr: ['198.51.100.9' => 'host.evil.example'],
			forward: ['host.evil.example' => ['198.51.100.9']]
		);

		$consumer = $this->consumer(['name' => 'partner-a', 'domains' => ['*.partner.example']]);

		$this->assertFalse(
			$service->isAllowed($consumer, $this->request('198.51.100.9')),
			'A confirmed hostname outside the configured domains MUST be rejected (403).'
		);
	}//end testDomainOutsideConfiguredAllowlistIsRejected()

	/**
	 * A listed exact IP and a listed CIDR range are allowed.
	 *
	 * @return void
	 */
	public function testListedIpAndCidrAreAllowed(): void {
		$service = $this->makeService();
		$consumer = $this->consumer(['name' => 'p', 'ips' => ['203.0.113.4', '10.0.0.0/8', '2001:db8::/32']]);

		$this->assertTrue($service->isAllowed($consumer, $this->request('203.0.113.4')), 'Exact IPv4 match.');
		$this->assertTrue($service->isAllowed($consumer, $this->request('10.1.2.3')), 'Inside the IPv4 CIDR.');
		$this->assertTrue($service->isAllowed($consumer, $this->request('2001:db8::5')), 'Inside the IPv6 CIDR.');
		$this->assertFalse($service->isAllowed($consumer, $this->request('11.1.2.3')), 'Outside the IPv4 CIDR.');
		$this->assertFalse($service->isAllowed($consumer, $this->request('2001:db9::5')), 'Outside the IPv6 CIDR.');
	}//end testListedIpAndCidrAreAllowed()

	/**
	 * An IPv4 caller MUST NOT match an IPv6 range (and vice versa).
	 *
	 * @return void
	 */
	public function testIpFamiliesDoNotCrossMatch(): void {
		$service = $this->makeService();

		$this->assertFalse(
			$service->isAllowed($this->consumer(['name' => 'p', 'ips' => ['::/0']]), $this->request('203.0.113.4')),
			'An IPv4 caller MUST NOT match an IPv6 range.'
		);
		$this->assertFalse(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'ips' => ['0.0.0.0/0']]),
				$this->request('2001:db8::5')
			),
			'An IPv6 caller MUST NOT match an IPv4 range.'
		);
	}//end testIpFamiliesDoNotCrossMatch()

	/**
	 * A forward-confirmed hostname inside `domains` is allowed (exact + wildcard + apex).
	 *
	 * @return void
	 */
	public function testForwardConfirmedHostnameIsAllowed(): void {
		$service = $this->makeService(
			ptr: ['203.0.113.4' => 'api.partner.example', '203.0.113.5' => 'partner.example'],
			forward: ['api.partner.example' => ['203.0.113.4'], 'partner.example' => ['203.0.113.5']]
		);

		$this->assertTrue(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'domains' => ['api.partner.example']]),
				$this->request('203.0.113.4')
			),
			'Exact hostname match.'
		);

		$this->assertTrue(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'domains' => ['*.partner.example']]),
				$this->request('203.0.113.4')
			),
			'Suffix wildcard match.'
		);

		$this->assertTrue(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'domains' => ['*.partner.example']]),
				$this->request('203.0.113.5')
			),
			'A suffix wildcard also matches the bare apex.'
		);
	}//end testForwardConfirmedHostnameIsAllowed()

	/**
	 * BAD PATH: a PTR that does not forward-confirm MUST NOT satisfy `domains`.
	 *
	 * A hostile network can set its own PTR to any name. Only forward-confirmed
	 * reverse DNS binds a hostname to an IP.
	 *
	 * @return void
	 */
	public function testUnconfirmedReverseDnsIsRejected(): void {
		$service = $this->makeService(
			ptr: ['198.51.100.9' => 'api.partner.example'],
			forward: ['api.partner.example' => ['203.0.113.4']]
		);

		$this->assertFalse(
			$service->isAllowed(
				$this->consumer(['name' => 'p', 'domains' => ['api.partner.example']]),
				$this->request('198.51.100.9')
			),
			'A PTR claiming a listed hostname that does not forward-resolve back to the caller MUST be rejected.'
		);
	}//end testUnconfirmedReverseDnsIsRejected()

	/**
	 * `ips` and `domains` combine as a union — a match in either allows.
	 *
	 * @return void
	 */
	public function testIpsAndDomainsCombineAsUnion(): void {
		$service = $this->makeService(
			ptr: ['203.0.113.9' => 'api.partner.example'],
			forward: ['api.partner.example' => ['203.0.113.9']]
		);

		$consumer = $this->consumer(
			['name' => 'p', 'ips' => ['10.0.0.1'], 'domains' => ['api.partner.example']]
		);

		$this->assertTrue(
			$service->isAllowed($consumer, $this->request('10.0.0.1')),
			'Matching the ips list alone allows.'
		);
		$this->assertTrue(
			$service->isAllowed($consumer, $this->request('203.0.113.9')),
			'Matching the domains list alone allows.'
		);
		$this->assertFalse(
			$service->isAllowed($consumer, $this->request('198.51.100.1')),
			'Matching neither list rejects.'
		);
	}//end testIpsAndDomainsCombineAsUnion()

	/**
	 * BAD PATH: an unknown client IP fails closed when an allowlist is configured.
	 *
	 * @return void
	 */
	public function testUnknownClientIpFailsClosed(): void {
		$service = $this->makeService();

		$this->assertFalse(
			$service->isAllowed($this->consumer(['name' => 'p', 'ips' => ['203.0.113.4']]), $this->request('')),
			'An allowlist with an underivable client IP MUST fail closed.'
		);

		$this->assertTrue(
			$service->isAllowed($this->consumer(['name' => 'p']), $this->request('')),
			'No allowlist + underivable IP stays unrestricted (no regression).'
		);
	}//end testUnknownClientIpFailsClosed()

	/**
	 * The allowlist is derived from getRemoteAddress(), never from a caller header.
	 *
	 * A spoofed `X-Forwarded-For` naming an allowed IP MUST NOT grant access:
	 * IRequest::getRemoteAddress() is the only source, and Nextcloud core only
	 * honours forwarded headers from a configured trusted proxy.
	 *
	 * @return void
	 */
	public function testForwardedHeaderCannotSpoofTheAllowlist(): void {
		$service = $this->makeService();
		$consumer = $this->consumer(['name' => 'p', 'ips' => ['203.0.113.4']]);

		$request = $this->createMock(IRequest::class);
		// The untrusted caller's real address.
		$request->method('getRemoteAddress')->willReturn('198.51.100.9');
		// A forged header claiming to be the allowed source.
		$request->method('getHeader')->willReturnMap(
			[
				['X-Forwarded-For', '203.0.113.4'],
				['CF-Connecting-IP', '203.0.113.4'],
			]
		);

		$this->assertFalse(
			$service->isAllowed($consumer, $request),
			'A forged forwarded header MUST NOT satisfy the IP allowlist.'
		);
	}//end testForwardedHeaderCannotSpoofTheAllowlist()

	/**
	 * Non-string / blank allowlist entries are ignored rather than allowing all.
	 *
	 * @return void
	 */
	public function testMalformedAllowlistEntriesAreIgnoredNotAllowAll(): void {
		$service = $this->makeService();
		$consumer = $this->consumer(['name' => 'p', 'ips' => ['', '   ', 'not-an-ip', '10.0.0.0/999', 1234]]);

		$this->assertFalse(
			$service->isAllowed($consumer, $this->request('203.0.113.4')),
			'Malformed entries MUST NOT be treated as a wildcard.'
		);
	}//end testMalformedAllowlistEntriesAreIgnoredNotAllowAll()
}//end class
