<?php

/**
 * The page a recipient lands on after following an unsubscribe link.
 *
 * It needs no login and creates no account, because the person following it
 * usually has neither. Opening it changes nothing: it says what will stop and
 * asks to confirm with a button, because mail scanners open links before a
 * person does. After the button it says exactly what was stopped and what
 * was not.
 *
 * @var array $_ The template variables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/** @var \OCP\IL10N $l */
$l = $_['l10n'];
$state = (string)($_['state'] ?? (($_['stopped'] ?? false) === true ? 'stopped' : 'invalid'));
$message = (string)($_['message'] ?? '');
$action = (string)($_['action'] ?? '');
$headings = [
	'confirm' => $l->t('Stop these messages?'),
	'stopped' => $l->t('Updates stopped'),
	'expired' => $l->t('This link has expired'),
	'invalid' => $l->t('This link no longer works'),
];
?>
<div class="guest-box">
	<h2><?php p($headings[$state] ?? $headings['invalid']); ?></h2>
	<p><?php p($message); ?></p>
	<?php if ($state === 'confirm' && $action !== '') { ?>
	<form method="post" action="<?php p($action); ?>">
		<button type="submit" name="choice" value="this" class="primary"><?php p($l->t('Stop these messages')); ?></button>
		<?php if (($_['offerAll'] ?? false) === true) { ?>
		<button type="submit" name="choice" value="all"><?php p($l->t('Stop everything that is not statutory')); ?></button>
		<?php } ?>
	</form>
	<?php } ?>
</div>
