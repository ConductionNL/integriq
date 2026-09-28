# Proposal: source-requested-event

kind: capability. Cites **ADR-041** (cross-app commands via typed events) and **ADR-094**
(outbound HTTP is integriq's job). Coupled to the dossiq change `webhook-steps-through-integriq`,
which ships the requesting half. Train order: this PR merges first. Dossiq's dispatch is guarded
by `class_exists()` and refuses the step with a logged reason until this lands, so no ordering
breaks either way.

## Summary

dossiq#3180 removed dossiq's own webhook steps (`dossiq.webhook`, `dossiq.action.callWebhook`)
because outbound calls belong to integriq. Its upgrade could not map them: every integriq step
calls a configured Source with a relative endpoint, and a webhook step holds a plain URL. So the
steps stayed in place and failed at run time.

This change gives a sibling app a way to turn a plain base URL into a Source:

1. **`OCA\Integriq\Event\SourceRequestedEvent`**: the typed command "find or create the Source for
   this base URL", carrying the requesting app, the purpose, an optional timeout and the acting
   user, with a synchronous result slot (`isHandled`, `getSourceId`, `getSourceSlug`,
   `wasCreated`, `getRefusal`).
2. **`SourceRequestedListener`**: derives a slug from scheme, host and port
   (`url-https-hooks-example-org-8443`), returns the Source with that slug when it exists, and
   otherwise creates an enabled Source with that location, no credentials, and a description
   naming who asked and why. It refuses anything that is not a bare http(s) base URL, and any host
   Nextcloud's remote-host rule refuses.
3. **`{{ @item }}`** in the flow template: the whole item record, so a `openconnector.source-call`
   body can post the item itself, as the retired webhook steps posted the whole case.

## Why a find-or-create, when SourceCallNode refuses one

`SourceCallNode::resolveSource()` never creates a Source from a flow document, and that stays so:
a flow author still cannot name a URL. This event is dispatched by the requesting app's own code
(dossiq's upgrade step, its automatic-action migration, and its runner for case-type
declarations), for URLs an administrator already configured in that app. The created Source is
then visible, editable and switchable in integriq like any other, and every call through it gets
integriq's call log, rate limit and circuit breaker, which the old direct calls had none of.

## Out of scope

Credentials. A webhook that needs a secret is configured on its Source by an administrator.
