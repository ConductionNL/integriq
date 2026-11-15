<?php

/**
 * Integriq AsyncApiDocumentService.
 *
 * Builds the AsyncAPI 3.0 document of an API product's event channels.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/events-async-api-products/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The document is generated from the product's channels on request, never
 * stored, so it cannot drift from what is served (design D4). Each channel
 * becomes an AsyncAPI channel, each CloudEvent type a message with the
 * CloudEvents 1.0 envelope as payload, and each channel gets a push and a
 * pull operation.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
 */
class AsyncApiDocumentService {

	/**
	 * The AsyncAPI version the document follows.
	 *
	 * @var string
	 */
	public const ASYNCAPI_VERSION = '3.0.0';

	/**
	 * The content type of every message.
	 *
	 * @var string
	 */
	public const CONTENT_TYPE = 'application/cloudevents+json';

	/**
	 * The product version a document describes: the highest active version, else the highest version.
	 *
	 * @param array<int,ObjectEntity> $versions The api_product rows sharing one productSlug.
	 *
	 * @return ObjectEntity|null The row, or null when there is none.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
	 */
	public function latestVersion(array $versions): ?ObjectEntity {
		$latest = null;
		foreach ($versions as $row) {
			if ($row instanceof ObjectEntity === false) {
				continue;
			}

			if ($latest === null || $this->ranksAbove(candidate: $row, current: $latest) === true) {
				$latest = $row;
			}
		}

		return $latest;
	}//end latestVersion()

	/**
	 * The AsyncAPI 3.0 document of a product version's channels.
	 *
	 * @param ObjectEntity $product The api_product row.
	 * @param string       $baseUrl The absolute URL of the product's routes, e.g. `https://host/index.php/apps/integriq/api/products/woo-publications`.
	 *
	 * @return array<string,mixed> The document.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
	 */
	public function build(ObjectEntity $product, string $baseUrl): array {
		$data = $product->getObject();
		$parts = parse_url($baseUrl);
		$host = (string)($parts['host'] ?? '');
		if (isset($parts['port']) === true) {
			$host .= ':' . $parts['port'];
		}

		$info = [
			'title' => (string)($data['name'] ?? ($data['productSlug'] ?? '')),
			'version' => (string)($data['version'] ?? '1.0.0'),
		];
		if (is_string($data['description'] ?? null) === true && $data['description'] !== '') {
			$info['description'] = $data['description'];
		}

		$document = [
			'asyncapi' => self::ASYNCAPI_VERSION,
			'info' => $info,
			'defaultContentType' => self::CONTENT_TYPE,
			'servers' => [
				'integriq' => [
					'host' => $host,
					'protocol' => (string)($parts['scheme'] ?? 'https'),
					'pathname' => rtrim((string)($parts['path'] ?? ''), '/'),
					'description' => 'Pull a channel with GET channels/{channel}/events; register a push sink with '
						.'POST channels/{channel}/subscriptions. Both need an active subscription to this product.',
				],
			],
			'channels' => [],
			'operations' => [],
			'components' => ['messages' => []],
		];

		foreach ($this->channelsOf(data: $data) as $channel) {
			$this->addChannel(document: $document, channel: $channel);
		}

		if ($document['channels'] === []) {
			$document['channels'] = new \stdClass();
			$document['operations'] = new \stdClass();
			$document['components']['messages'] = new \stdClass();
		}

		return $document;
	}//end build()

	/**
	 * Add one product channel, its messages and its push and pull operations.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $channel  The product channel.
	 *
	 * @return void
	 */
	private function addChannel(array &$document, array $channel): void {
		$slug = $channel['slug'];
		$messages = [];
		foreach ($channel['types'] as $type) {
			$document['components']['messages'][$type] = self::message(type: $type);
			$messages[$type] = ['$ref' => '#/components/messages/' . $type];
		}

		$document['channels'][$slug] = array_filter(
			[
				'address' => 'channels/' . $slug . '/events',
				'title' => $channel['title'],
				'description' => ($channel['description'] ?? null),
				'messages' => $messages,
			],
			static fn ($value): bool => $value !== null && $value !== ''
		);

		$refs = [];
		foreach (array_keys($messages) as $type) {
			$refs[] = ['$ref' => '#/channels/' . $slug . '/messages/' . $type];
		}

		$document['operations'][$slug . '.pull'] = [
			'action' => 'send',
			'channel' => ['$ref' => '#/channels/' . $slug],
			'summary' => 'Events served to a consumer that pulls GET channels/' . $slug . '/events.',
			'messages' => $refs,
		];
		$document['operations'][$slug . '.push'] = [
			'action' => 'send',
			'channel' => ['$ref' => '#/channels/' . $slug],
			'summary' => 'Events pushed, signed, to the sink a consumer registered with POST channels/' . $slug . '/subscriptions.',
			'messages' => $refs,
		];
	}//end addChannel()

	/**
	 * The well-formed channels of a product: a slug, a title and at least one type.
	 *
	 * @param array<string,mixed> $data The product's data.
	 *
	 * @return array<int,array<string,mixed>> The channels, first of each slug kept.
	 */
	private function channelsOf(array $data): array {
		$channels = [];
		foreach ((array)($data['channels'] ?? []) as $channel) {
			if (is_array($channel) === false || is_string($channel['slug'] ?? null) === false || $channel['slug'] === '') {
				continue;
			}

			$types = array_values(array_filter((array)($channel['types'] ?? []), static fn ($type): bool => is_string($type) === true && $type !== ''));
			if ($types === [] || isset($channels[$channel['slug']]) === true) {
				continue;
			}

			$channels[$channel['slug']] = [
				'slug' => $channel['slug'],
				'title' => (string)($channel['title'] ?? $channel['slug']),
				'description' => (string)($channel['description'] ?? ''),
				'types' => array_values(array_unique($types)),
			];
		}

		return array_values($channels);
	}//end channelsOf()

	/**
	 * A CloudEvents 1.0 message of one type.
	 *
	 * @param string $type The CloudEvent type.
	 *
	 * @return array<string,mixed> The message.
	 */
	private static function message(string $type): array {
		return [
			'name' => $type,
			'title' => $type,
			'contentType' => self::CONTENT_TYPE,
			'payload' => [
				'type' => 'object',
				'required' => ['specversion', 'id', 'source', 'type'],
				'properties' => [
					'specversion' => ['type' => 'string', 'const' => '1.0'],
					'id' => ['type' => 'string'],
					'source' => ['type' => 'string'],
					'type' => ['type' => 'string', 'const' => $type],
					'subject' => ['type' => 'string'],
					'time' => ['type' => 'string', 'format' => 'date-time'],
					'datacontenttype' => ['type' => 'string'],
					'data' => new \stdClass(),
				],
			],
		];
	}//end message()

	/**
	 * Whether a product row ranks above another: active before not active, then the higher version.
	 *
	 * @param ObjectEntity $candidate The candidate.
	 * @param ObjectEntity $current   The current best.
	 *
	 * @return bool True when the candidate ranks above.
	 */
	private function ranksAbove(ObjectEntity $candidate, ObjectEntity $current): bool {
		$candidateActive = (($candidate->getObject()['status'] ?? 'active') === 'active');
		$currentActive = (($current->getObject()['status'] ?? 'active') === 'active');
		if ($candidateActive !== $currentActive) {
			return $candidateActive;
		}

		return version_compare((string)($candidate->getObject()['version'] ?? '0'), (string)($current->getObject()['version'] ?? '0'), '>');
	}//end ranksAbove()
}//end class
