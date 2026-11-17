<?php

/**
 * Integriq A2aAgentCardRewriter.
 *
 * An A2A agent publishes an agent card naming its own address. Behind an
 * `a2a` endpoint that address must be the gateway's, or another agent that
 * reads the card calls the agent directly and skips the endpoint's
 * authentication, limits and call log.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Points every agent address in an A2A agent card at the gateway.
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
 */
class A2aAgentCardRewriter {

	/**
	 * The well-known paths an agent card is served at: the current name and
	 * the one A2A used before it.
	 *
	 * @var array<int,string>
	 */
	public const CARD_PATHS = ['.well-known/agent-card.json', '.well-known/agent.json'];

	/**
	 * The endpoint target type that proxies to an A2A agent.
	 *
	 * @var string
	 */
	public const TARGET_TYPE = 'a2a';

	/**
	 * Whether an inbound path asks for the agent card.
	 *
	 * @param string $path The path the endpoint was called on.
	 *
	 * @return bool True for a card path.
	 *
	 * @spec openspec/changes/gateway-a2a-agent-card/tasks.md#task-1-the-a2a-endpoint-target-with-card-rewriting
	 */
	public function isCardPath(string $path): bool {
		return $this->cardPrefix(path: $path) !== null;

	}//end isCardPath()

	/**
	 * The gateway address of the agent: the endpoint's public address for
	 * the path the card was asked on, without the well-known part.
	 *
	 * @param string $endpointBase The public base of every endpoint, e.g. `https://nc/apps/integriq/api/endpoint`.
	 * @param string $path         The path the card was asked on.
	 *
	 * @return string The gateway address, no trailing slash.
	 *
	 * @spec openspec/changes/gateway-a2a-agent-card/tasks.md#task-1-the-a2a-endpoint-target-with-card-rewriting
	 */
	public function gatewayAddress(string $endpointBase, string $path): string {
		$prefix = trim((string)$this->cardPrefix(path: $path), '/');
		$address = rtrim($endpointBase, '/');
		if ($prefix !== '') {
			$address .= '/' . $prefix;
		}

		return $address;

	}//end gatewayAddress()

	/**
	 * Rewrite an agent card so every address at the agent is the gateway's.
	 *
	 * Every string in the card that starts with the agent's own address is
	 * moved onto the gateway address, keeping the part after it, so
	 * `url`, `additionalInterfaces[].url` and any other address at the agent
	 * point at the endpoint. Addresses elsewhere (the provider's site, the
	 * documentation) are left alone. A body that is not a JSON object is
	 * handed on unchanged.
	 *
	 * @param mixed  $body           The card as the agent answered it: a JSON string or a decoded array.
	 * @param string $agentAddress   The agent's own address, the source's location.
	 * @param string $gatewayAddress The endpoint's public address for the agent.
	 *
	 * @return mixed The card, in the form it came in.
	 *
	 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
	 */
	public function rewrite(mixed $body, string $agentAddress, string $gatewayAddress): mixed {
		$agent = rtrim(trim($agentAddress), '/');
		if ($agent === '') {
			return $body;
		}

		if (is_array($body) === true) {
			return $this->rewriteValue(value: $body, agent: $agent, gateway: rtrim($gatewayAddress, '/'));
		}

		if (is_string($body) === false) {
			return $body;
		}

		$card = json_decode($body, true);
		if (is_array($card) === false) {
			return $body;
		}

		$rewritten = $this->rewriteValue(value: $card, agent: $agent, gateway: rtrim($gatewayAddress, '/'));

		return (string)json_encode($rewritten, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

	}//end rewrite()

	/**
	 * Rewrite one value of the card, walking into lists and objects.
	 *
	 * @param mixed  $value   The value.
	 * @param string $agent   The agent's address, no trailing slash.
	 * @param string $gateway The gateway's address, no trailing slash.
	 *
	 * @return mixed The value with agent addresses moved.
	 */
	private function rewriteValue(mixed $value, string $agent, string $gateway): mixed {
		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->rewriteValue(value: $item, agent: $agent, gateway: $gateway);
			}

			return $value;
		}

		if (is_string($value) === false) {
			return $value;
		}

		if ($value === $agent) {
			return $gateway;
		}

		// Only the agent's address itself, or a path under it: a host that
		// merely starts with the same characters is another host.
		foreach (['/', '?', '#'] as $separator) {
			if (str_starts_with($value, $agent . $separator) === true) {
				return $gateway . substr($value, strlen($agent));
			}
		}

		return $value;

	}//end rewriteValue()

	/**
	 * The part of a card path before the well-known part, or null when the
	 * path is not a card path.
	 *
	 * @param string $path The inbound path.
	 *
	 * @return string|null The prefix, possibly empty.
	 */
	private function cardPrefix(string $path): ?string {
		$trimmed = trim($path, '/');
		foreach (self::CARD_PATHS as $cardPath) {
			if ($trimmed === $cardPath) {
				return '';
			}

			if (str_ends_with($trimmed, '/' . $cardPath) === true) {
				return substr($trimmed, 0, (strlen($trimmed) - strlen($cardPath) - 1));
			}
		}

		return null;

	}//end cardPrefix()

}//end class
