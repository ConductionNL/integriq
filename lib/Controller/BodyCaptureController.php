<?php

/**
 * Integriq BodyCaptureController.
 *
 * Opens and closes an investigation window on one source. While the window
 * is open, outgoing calls to that source store their request and response
 * bodies; after it ends they do not, with no job or person needed. Opening
 * and closing go through OpenRegister's audited save, so the source's audit
 * trail names the administrator and the reason. Woo row 13.23, decision D5.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Opens and closes a source's investigation window.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
 */
class BodyCaptureController extends Controller {

	/**
	 * The longest reason kept on the source.
	 *
	 * @var int
	 */
	private const MAX_REASON_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal.
	 * @param IGroupManager $groupManager Answers whether the principal is an administrator.
	 * @param OrObjectService $objectService Reads and saves the source.
	 * @param BodyCapturePolicy $policy The window maximum and the clock.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrObjectService $objectService,
		private readonly BodyCapturePolicy $policy,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Open an investigation window on a source for a number of hours, with a reason.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return JSONResponse The window, or the refusal.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function open(string $id): JSONResponse {
		$admin = $this->requireAdmin();
		if ($admin instanceof JSONResponse) {
			return $admin;
		}

		$maxHours = $this->policy->maxHours();
		$hours = $this->hours(value: $this->request->getParam('hours'));
		if ($hours === null || $hours < 1 || $hours > $maxHours) {
			return new JSONResponse(
				['error' => $this->l->t('Choose a number of hours from 1 to %1$s.', [(string)$maxHours]), 'maxHours' => $maxHours],
				Http::STATUS_BAD_REQUEST
			);
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			return new JSONResponse(
				['error' => $this->l->t('Give a reason of at most %1$s characters.', [(string)self::MAX_REASON_LENGTH])],
				Http::STATUS_BAD_REQUEST
			);
		}

		$source = $this->findSource(id: $id);
		if ($source === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$until = $this->policy->now()->modify('+' . $hours . ' hours');
		$data = $source->getObject();
		$data['bodyCaptureUntil'] = $until->format('c');
		$data['bodyCaptureReason'] = $reason;
		$data['bodyCaptureBy'] = $admin;

		return $this->save(source: $source, data: $data);

	}//end open()

	/**
	 * Close a source's open investigation window now.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return JSONResponse The closed window, or the refusal.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function close(string $id): JSONResponse {
		$admin = $this->requireAdmin();
		if ($admin instanceof JSONResponse) {
			return $admin;
		}

		$source = $this->findSource(id: $id);
		if ($source === null) {
			return new JSONResponse(['error' => $this->l->t('Not Found')], Http::STATUS_NOT_FOUND);
		}

		$data = $source->getObject();
		$now = $this->policy->now();
		if ($this->policy->isOpen(sourceData: $data, now: $now) === false) {
			return new JSONResponse(
				['error' => $this->l->t('This source has no open investigation window.')],
				Http::STATUS_CONFLICT
			);
		}

		$data['bodyCaptureUntil'] = $now->format('c');
		$data['bodyCaptureBy'] = $admin;

		return $this->save(source: $source, data: $data);

	}//end close()

	/**
	 * Refuse a caller who is not an instance administrator.
	 *
	 * The route already carries the admin setting; this check keeps the body
	 * honest when the attribute is ever loosened.
	 *
	 * @return string|JSONResponse The administrator's user id, or the refusal.
	 */
	private function requireAdmin(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) !== true) {
			return new JSONResponse(
				['error' => $this->l->t('Only an administrator can open or close an investigation window.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return $user->getUID();

	}//end requireAdmin()

	/**
	 * The requested hours as a whole number.
	 *
	 * @param mixed $value The request parameter.
	 *
	 * @return int|null The hours, or null when it is not a whole number.
	 */
	private function hours(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && preg_match('/^\d{1,6}$/', $value) === 1) {
			return (int)$value;
		}

		return null;

	}//end hours()

	/**
	 * Read the source.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return ObjectEntity|null The source, or null when there is none.
	 */
	private function findSource(string $id): ?ObjectEntity {
		try {
			$source = $this->objectService->find(
				id: $id,
				register: 'integriq',
				schema: 'source',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		return $source;

	}//end findSource()

	/**
	 * Save the source through OpenRegister's audited path and answer the window.
	 *
	 * Not silent: the audit trail entry is how opening and closing are
	 * recorded with the principal and the reason.
	 *
	 * @param ObjectEntity $source The source.
	 * @param array<string,mixed> $data The source with its window fields set.
	 *
	 * @return JSONResponse The window.
	 */
	private function save(ObjectEntity $source, array $data): JSONResponse {
		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'source',
				uuid: $source->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return new JSONResponse(
				['error' => $this->l->t('The source could not be saved. Nothing changed.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			[
				'uuid' => $saved->getUuid(),
				'bodyCaptureUntil' => $data['bodyCaptureUntil'],
				'bodyCaptureReason' => ($data['bodyCaptureReason'] ?? ''),
				'bodyCaptureBy' => $data['bodyCaptureBy'],
			]
		);

	}//end save()
}//end class
