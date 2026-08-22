<?php

/**
 * Stub for OCA\Forms\Events\AbstractFormEvent.
 *
 * See tests/stubs/OCA/Forms/Db/Form.php for why this stub exists. Mirrors
 * `lib/Events/AbstractFormEvent.php` (public `nextcloud/forms` source,
 * `main` branch, fetched during nextcloud-event-hub's implementation) —
 * including `getWebhookSerializable()`, which the real class defines on
 * BOTH of its conditional class bodies (the NC30+ `IWebhookCompatibleEvent`
 * branch and the pre-NC30 plain-`Event` branch), so this stub always
 * defines it too.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Forms\Events;

use OCA\Forms\Db\Form;
use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\Forms\Events\AbstractFormEvent.
 */
abstract class AbstractFormEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Form $form The affected form.
	 */
	public function __construct(
		protected Form $form,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the affected form.
	 *
	 * @return Form
	 */
	public function getForm(): Form {
		return $this->form;
	}//end getForm()

	/**
	 * Webhook-serializable representation of this event.
	 *
	 * @return array
	 */
	public function getWebhookSerializable(): array {
		return ['form' => $this->form->read()];
	}//end getWebhookSerializable()
}//end class
