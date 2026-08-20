<?php

/**
 * Stub for OCA\Forms\Events\FormSubmittedEvent.
 *
 * See AbstractFormEvent.php in this directory for why this stub exists.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Forms\Events;

use OCA\Forms\Db\Form;
use OCA\Forms\Db\Submission;

/**
 * Minimal stub for OCA\Forms\Events\FormSubmittedEvent.
 */
class FormSubmittedEvent extends AbstractFormEvent {

	/**
	 * Constructor.
	 *
	 * @param Form $form The submitted-to form.
	 * @param Submission $submission The submission.
	 */
	public function __construct(
		Form $form,
		private Submission $submission,
	) {
		parent::__construct($form);

	}//end __construct()

	/**
	 * Webhook-serializable representation of this event.
	 *
	 * @return array
	 */
	public function getWebhookSerializable(): array {
		return [
			'form' => $this->form->read(),
			'submission' => $this->submission->read(),
		];

	}//end getWebhookSerializable()
}//end class
