<?php

/**
 * The page a recipient lands on after following an unsubscribe link.
 *
 * It needs no login and creates no account, because the person following it
 * usually has neither. It says exactly what was stopped and what was not.
 *
 * @var array $_ The template variables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

$l = ($_['l10n'] ?? null);
$stopped = (bool)($_['stopped'] ?? false);
$message = (string)($_['message'] ?? '');
?>
<div class="guest-box">
	<h2><?php p($stopped === true ? 'Updates gestopt' : 'Deze link werkt niet meer'); ?></h2>
	<p><?php p($message); ?></p>
</div>
