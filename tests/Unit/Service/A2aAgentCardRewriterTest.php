<?php

/**
 * Unit tests for A2aAgentCardRewriter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\A2aAgentCardRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every agent address in a card is moved onto the gateway.
 *
 * @covers \OCA\Integriq\Service\A2aAgentCardRewriter
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
 */
class A2aAgentCardRewriterTest extends TestCase {

	/**
	 * The gateway address the tests rewrite onto.
	 *
	 * @var string
	 */
	private const GATEWAY = 'https://nc.example/apps/integriq/api/endpoint/agents/weer';

	/**
	 * The card's own address and its other interfaces point at the gateway;
	 * the provider's site and the documentation stay where they are.
	 *
	 * @return void
	 */
	public function testEveryAgentAddressInTheCardIsTheGateway(): void {
		$card = [
			'name' => 'Weeragent',
			'url' => 'https://agent.internal:8443/a2a/v1',
			'preferredTransport' => 'JSONRPC',
			'additionalInterfaces' => [
				['url' => 'https://agent.internal:8443/a2a/v1', 'transport' => 'JSONRPC'],
				['url' => 'https://agent.internal:8443/a2a/rest?x=1', 'transport' => 'HTTP+JSON'],
			],
			'provider' => ['organization' => 'Gemeente', 'url' => 'https://gemeente.example'],
			'documentationUrl' => 'https://docs.example/weer',
			'iconUrl' => 'https://agent.internal:8443',
			'version' => '1.0.0',
			'capabilities' => ['streaming' => true],
		];

		$rewritten = (new A2aAgentCardRewriter())->rewrite(body: $card, agentAddress: 'https://agent.internal:8443/', gatewayAddress: self::GATEWAY . '/');

		$this->assertSame(self::GATEWAY . '/a2a/v1', $rewritten['url']);
		$this->assertSame(
			[self::GATEWAY . '/a2a/v1', self::GATEWAY . '/a2a/rest?x=1'],
			array_column($rewritten['additionalInterfaces'], 'url')
		);
		$this->assertSame(self::GATEWAY, $rewritten['iconUrl']);
		$this->assertSame('https://gemeente.example', $rewritten['provider']['url']);
		$this->assertSame('https://docs.example/weer', $rewritten['documentationUrl']);
		$this->assertSame(['streaming' => true], $rewritten['capabilities']);
		$this->assertSame('1.0.0', $rewritten['version']);

	}//end testEveryAgentAddressInTheCardIsTheGateway()

	/**
	 * A host that only starts with the agent's address is another host and
	 * keeps its address.
	 *
	 * @return void
	 */
	public function testALookalikeHostIsNotRewritten(): void {
		$rewritten = (new A2aAgentCardRewriter())->rewrite(
			body: ['url' => 'https://agent.internal.evil.example/a2a'],
			agentAddress: 'https://agent.internal',
			gatewayAddress: self::GATEWAY
		);

		$this->assertSame(['url' => 'https://agent.internal.evil.example/a2a'], $rewritten);

	}//end testALookalikeHostIsNotRewritten()

	/**
	 * A card that arrives as JSON text comes back as JSON text, rewritten,
	 * with its slashes unescaped.
	 *
	 * @return void
	 */
	public function testAJsonCardStaysJson(): void {
		$rewritten = (new A2aAgentCardRewriter())->rewrite(
			body: '{"name":"Weeragent","url":"https://agent.internal/a2a"}',
			agentAddress: 'https://agent.internal',
			gatewayAddress: self::GATEWAY
		);

		$this->assertSame('{"name":"Weeragent","url":"' . self::GATEWAY . '/a2a"}', $rewritten);

	}//end testAJsonCardStaysJson()

	/**
	 * A body that is not a card, or an agent without an address, is handed
	 * on unchanged.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotACardIsUnchanged(): void {
		$rewriter = new A2aAgentCardRewriter();

		$this->assertSame('not json', $rewriter->rewrite(body: 'not json', agentAddress: 'https://agent.internal', gatewayAddress: self::GATEWAY));
		$this->assertNull($rewriter->rewrite(body: null, agentAddress: 'https://agent.internal', gatewayAddress: self::GATEWAY));
		$this->assertSame(['url' => 'https://agent.internal'], $rewriter->rewrite(body: ['url' => 'https://agent.internal'], agentAddress: ' ', gatewayAddress: self::GATEWAY));

	}//end testABodyThatIsNotACardIsUnchanged()

	/**
	 * Both card names are card paths, at the root or under a prefix, and the
	 * gateway address is the endpoint address before the well-known part.
	 *
	 * @return void
	 */
	public function testCardPathsAndTheGatewayAddress(): void {
		$rewriter = new A2aAgentCardRewriter();

		$this->assertTrue($rewriter->isCardPath(path: '/agents/weer/.well-known/agent-card.json'));
		$this->assertTrue($rewriter->isCardPath(path: '.well-known/agent.json'));
		$this->assertFalse($rewriter->isCardPath(path: '/agents/weer/a2a/v1'));
		$this->assertFalse($rewriter->isCardPath(path: '/agents/weer/not.well-known/agent-card.json.bak'));

		$this->assertSame(self::GATEWAY, $rewriter->gatewayAddress(endpointBase: 'https://nc.example/apps/integriq/api/endpoint/', path: '/agents/weer/.well-known/agent-card.json'));
		$this->assertSame('https://nc.example/apps/integriq/api/endpoint', $rewriter->gatewayAddress(endpointBase: 'https://nc.example/apps/integriq/api/endpoint', path: '.well-known/agent.json'));

	}//end testCardPathsAndTheGatewayAddress()

}//end class
