<?php

/**
 * The page a person sees when they logged in below the assurance floor.
 *
 * Unlike the generic error page it says why: the level they used, the level
 * this service needs and how to get it. Naming the level the person just
 * chose tells them nothing they did not already know. Its one action goes
 * back to the consumer, which may resume from `error=assurance_below_floor`.
 *
 * @var array $_ The template variables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
 */

?>
<div class="guest-box">
	<h2><?php p($_['title']); ?></h2>
	<p><?php p($_['message']); ?></p>
	<p><?php p($_['hint']); ?></p>
	<p>
		<a class="button primary" href="<?php p($_['actionUrl']); ?>"><?php p($_['action']); ?></a>
	</p>
</div>
