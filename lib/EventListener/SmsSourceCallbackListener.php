<?php

/**
 * Integriq SmsSourceCallbackListener.
 *
 * On OpenRegister's own create and update path of an integriq source of type
 * `sms`: gives the source a random status-callback token when it has none,
 * keeps the stored token when an edit leaves it out, and encrypts a callback
 * signing key typed in clear, so the key is never stored in clear.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\SmsStatusCallbackService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps an SMS source's callback token and signing key in their stored shape.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 *
 * @template-implements IEventListener<Event>
 */
class SmsSourceCallbackListener implements IEventListener {

	/**
	 * The register the source schema lives in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'integriq';

	/**
	 * The source schema.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'source';

	/**
	 * The configuration key a signing key is typed under, in clear.
	 *
	 * @var string
	 */
	public const PLAIN_SIGNING_KEY = 'webhookSigningKey';

	/**
	 * Random bytes in a callback token.
	 *
	 * @var int
	 */
	private const TOKEN_BYTES = 24;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper  $registerMapper Resolves the object's register slug.
	 * @param SchemaMapper    $schemaMapper   Resolves the object's schema slug.
	 * @param ICrypto         $crypto         Encrypts a typed signing key.
	 * @param LoggerInterface $logger         Names a lookup that failed.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fill in the token and encrypt a typed signing key on an SMS source save.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$old = null;
		$entity = null;
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$old = $event->getOldObject();
		}

		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($entity === null) {
			return;
		}

		$data = (array)$entity->getObject();
		if (($data['type'] ?? null) !== SmsDispatchService::SOURCE_TYPE || $this->isSource(object: $entity) === false) {
			return;
		}

		if ($old !== null && array_key_exists('configuration', $data) === false) {
			// An edit that does not send the configuration leaves it as stored.
			return;
		}

		$stored = [];
		if ($old !== null && is_array($old->getObject()['configuration'] ?? null) === true) {
			$stored = $old->getObject()['configuration'];
		}

		$configuration = ($data['configuration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$event->setModifiedData(['configuration' => $this->settled(configuration: $configuration, stored: $stored)]);

	}//end handle()

	/**
	 * The configuration as it is stored: a token, and the signing key encrypted.
	 *
	 * @param array<string, mixed> $configuration The configuration being saved.
	 * @param array<string, mixed> $stored        The configuration stored before an update; empty on a create.
	 *
	 * @return array<string, mixed> The configuration to store.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function settled(array $configuration, array $stored): array {
		$token = ($configuration[SmsStatusCallbackService::TOKEN_KEY] ?? null);
		if (is_string($token) === false || preg_match('/^[A-Za-z0-9]{32,}$/', $token) !== 1) {
			$token = ($stored[SmsStatusCallbackService::TOKEN_KEY] ?? null);
		}

		if (is_string($token) === false || preg_match('/^[A-Za-z0-9]{32,}$/', $token) !== 1) {
			$token = bin2hex(random_bytes(self::TOKEN_BYTES));
		}

		$configuration[SmsStatusCallbackService::TOKEN_KEY] = $token;

		$plain = ($configuration[self::PLAIN_SIGNING_KEY] ?? null);
		unset($configuration[self::PLAIN_SIGNING_KEY]);
		if (is_string($plain) === true && trim($plain) !== '') {
			$configuration[SmsStatusCallbackService::SIGNING_KEY] = $this->crypto->encrypt(trim($plain));
			return $configuration;
		}

		$encrypted = ($stored[SmsStatusCallbackService::SIGNING_KEY] ?? null);
		if (isset($configuration[SmsStatusCallbackService::SIGNING_KEY]) === false && is_string($encrypted) === true && $encrypted !== '') {
			$configuration[SmsStatusCallbackService::SIGNING_KEY] = $encrypted;
		}

		return $configuration;

	}//end settled()

	/**
	 * Whether the object is an integriq source.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isSource(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] SMS source callback: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === self::REGISTER_SLUG && $schemaSlug === self::SCHEMA_SLUG;

	}//end isSource()
}//end class
