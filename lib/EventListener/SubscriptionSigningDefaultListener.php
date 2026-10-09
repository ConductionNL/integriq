<?php

/**
 * Integriq SubscriptionSigningDefaultListener.
 *
 * Makes a signature the default on a new push subscription, on OpenRegister's
 * own create and update path, so the default holds whichever page or app
 * saves the subscription.
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
 * @spec openspec/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies SubscriptionSigningPolicy to every event_subscription save.
 *
 * The Webhooks page saves through OpenRegister's generic object API, not
 * through EventsController, so a default that lived in the controller would
 * never run for the subscriptions people actually make. OpenRegister's
 * ObjectCreatingEvent and ObjectUpdatingEvent are stoppable and merge
 * setModifiedData() into the object before it is written: a refusal becomes
 * a HookStoppedException and a default lands in the stored row.
 *
 * On create: a push subscription without `protocolSettings.unsigned` gains a
 * generated secret; `unsigned` without a reason is refused; `unsigned` with a
 * reason is stamped with who and when. On update: `unsigned` without a reason
 * is refused and a secret is never generated (existing subscriptions keep
 * their state). Both write `signingPosture` and `unsignedReason`, the
 * readable mirror of `protocolSettings`, which is writeOnly and so invisible
 * to every list.
 *
 * @spec openspec/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
 *
 * @template-implements IEventListener<Event>
 */
class SubscriptionSigningDefaultListener implements IEventListener {

	/**
	 * The register the subscription schema lives in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'integriq';

	/**
	 * The subscription schema.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'event_subscription';

	/**
	 * Constructor.
	 *
	 * @param SubscriptionSigningPolicy $policy Decides the signing posture.
	 * @param RegisterMapper $registerMapper Resolves the object's register slug.
	 * @param SchemaMapper $schemaMapper Resolves the object's schema slug.
	 * @param IUserSession $userSession Names who set a subscription unsigned.
	 * @param IL10N $l10n Words the refusal.
	 * @param LoggerInterface $logger Names a lookup that failed.
	 */
	public function __construct(
		private readonly SubscriptionSigningPolicy $policy,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Apply the signing default, or refuse an unsigned save without a reason.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$isCreate = $event instanceof ObjectCreatingEvent;
		$old = null;
		$entity = null;
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$old = $event->getOldObject();
		}

		if ($this->isSubscription(object: $entity) === false) {
			return;
		}

		$data = (array)$entity->getObject();
		if ((string)($data['style'] ?? '') !== SubscriptionSigningPolicy::STYLE_PUSH) {
			return;
		}

		if ($this->policy->refuse(subscription: $data) !== null) {
			$event->setErrors(
				[
					'code' => 'unsigned_without_reason',
					'message' => $this->l10n->t('Turning off signing needs a reason. Say why this receiver gets unsigned deliveries.'),
					'status' => 400,
				]
			);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData($this->modifiedData(data: $data, old: $old, isCreate: $isCreate));

	}//end handle()

	/**
	 * The fields this save writes: settings (when they change) and the readable posture.
	 *
	 * @param array<string,mixed> $data The object being saved.
	 * @param ObjectEntity|null $old The stored object on an update.
	 * @param bool $isCreate Whether this is a create.
	 *
	 * @return array<string,mixed> The data to merge into the object.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-an-unsigned-subscription-and-an-unsigned-attempt-are-marked-req-sow-003
	 */
	private function modifiedData(array $data, ?ObjectEntity $old, bool $isCreate): array {
		$user = $this->currentUid();
		$existing = [];
		if ($old !== null) {
			$existing = (array)$old->getObject();
		}

		// An edit that does not send protocolSettings leaves them as they are;
		// the posture is then read from what is stored.
		$modified = [];
		$settings = (array)($existing['protocolSettings'] ?? []);
		if ($isCreate === true) {
			$settings = $this->policy->settingsForNew(subscription: $data, user: $user);
			$modified['protocolSettings'] = $settings;
		}

		if ($isCreate === false && array_key_exists('protocolSettings', $data) === true) {
			$settings = $this->policy->settingsForExisting(existing: $existing, incoming: $data, user: $user);
			$modified['protocolSettings'] = $settings;
		}

		$read = $this->policy->forReading(
			subscription: ['style' => SubscriptionSigningPolicy::STYLE_PUSH, 'protocolSettings' => $settings]
		);
		$modified['signingPosture'] = $read['signingPosture'];
		$modified['unsignedReason'] = (string)($read['unsignedReason'] ?? '');

		return $modified;

	}//end modifiedData()

	/**
	 * Whether the object is an integriq event_subscription.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isSubscription(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] signing default: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === self::REGISTER_SLUG && $schemaSlug === self::SCHEMA_SLUG;

	}//end isSubscription()

	/**
	 * The acting user's uid, or an empty string for a system save.
	 *
	 * @return string
	 */
	private function currentUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end currentUid()
}//end class
