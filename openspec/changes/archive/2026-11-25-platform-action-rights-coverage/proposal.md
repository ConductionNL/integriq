---
kind: code
depends_on: []
---

# Proposal: platform-action-rights-coverage

## Summary

Integriq's action authorization screen lets an administrator give a Nextcloud
group the right to run, test or export, one action at a time. It only lists
the actions in the seed file, and 34 actions the code enforces are missing from
it, so they stay admin-only with no way to delegate them. Who may create or
edit sources, mappings, synchronizations and the other configuration objects is
not on the screen at all. This change makes the seed complete and keeps it
complete with a test, groups the screen by area, and adds object rights per
configuration schema to the same screen.

## Why

Two rows, neither with a demand row.

`integriq:plt-action-matrix`, "See and set which roles may perform which
integration actions." Integriq rates it `partial` with `built.state` `built`.
Matrix note: "The screen really sets per-group rights, but it only lists actions
present in the stored matrix or lib/actions.seed.json. About 25 actions enforced
in code are not seeded". Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/access-management/roles.md: roles are "a set of
  predefined permissions controlling access to each product or feature", each
  product's permissions are listed and assignable.
- WSO2 API Manager (`wso2`), source read at v4.7.0:
  "carbon-apimgt/components/apimgt/org.wso2.carbon.apimgt.rest.api.admin.v1/src/main/resources/admin-api.yaml:3473
  /system-scopes lists every portal scope with its roles". No evidence URL is
  recorded for this cell.

`integriq:plt-roles`, "Give colleagues different rights in the integration
admin." Integriq rates it `partial` with `built.state` `built`. Matrix note:
"Who may create or edit sources, mappings and other objects is fixed in schema
authorization blocks with no screen". Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/access-management/roles.md: "A role is a set of
  predefined permissions controlling access to each product or feature within
  Anypoint Platform".
- WSO2 API Manager (`wso2`), source read at v4.7.0: "/system-scopes/{scopeName}
  ties portal scopes to roles". No evidence URL is recorded for this cell.
- Frank!Framework (`frank`), source read at v10.2.0:
  "commons/src/main/java/org/frankframework/lifecycle/DynamicRegistration.java:43
  defines the roles IbisWebService, IbisObserver, IbisDataAdmin, IbisAdmin and
  IbisTester". No evidence URL is recorded for this cell.

This change covers `integriq:plt-action-matrix` and `integriq:plt-roles`.

## What integriq already has

- The screen: `lib/Controller/ActionMatrixController.php:73` (`getMatrix()`)
  merges the stored matrix with the seed keys from `:134`
  (`seedActionKeys()`), and `:111` saves; both are admin only.
- Enforcement: `lib/Service/ActionAuthService.php:86` (`requireAction()`)
  lets an administrator through and otherwise intersects the user's groups
  with the action's entry; `:149` (`getAllowedGroups()`) answers `['admin']`
  for an action missing from the stored matrix.
- The seed: `lib/actions.seed.json` with 64 actions.
- The gap, counted at 92f282bc: 34 action names passed to `requireAction()` as
  literals are not in the seed: `bankfeed.connect`, `bankfeed.discover`,
  `basispoort.push`, `cardfeed.enroll`, `dso.handoff`, `dso.list`, `dso.status`,
  `dso.status-post`, `edu-v.push`, `entree-content.push`, `flow.run`,
  `fsc.call`, `fsc.list`, `iwmo-ijw.push`, `kiss.push`, `notificaties.create`,
  `notificaties.delete`, `notificaties.list`, `notificaties.update`,
  `open-formulieren.handoff`, `open-formulieren.status`, `oso.push`,
  `payments.create`, `peppol.lookup`, `rod.push`, `sms.send`, `sms.status`,
  `stuf-zkn.push`, `synchronization.formsBridge.discover`,
  `synchronization.reset-cursor`, `synchronization.tablesBridge.discover`,
  `uwlr.push`, `verzuimloket.push` and `zgw-version.translate`. The matrix
  counted about 25; the adapters added since then account for the rest.
- Object rights: `source`, `rule` and `consumer` carry `authorization` blocks
  with every verb `admin` (`lib/Settings/register.d/99-source-lockdown.json`,
  `99-rule-lockdown.json`, `99-consumer-lockdown.json`). `mapping`,
  `synchronization`, `job`, `endpoint`, `event_subscription` and `api_product`
  carry none and fall back to OpenRegister's default, which the
  `99-source-lockdown.json` comment records as readable by any authenticated
  user.

## What this change builds

1. The 34 missing actions in the seed, admin by default, each with a label and
   an area.
2. A unit test that collects every action name enforced in `lib/` and fails
   when one is missing from the seed.
3. The screen grouped by area, with a readable label per action.
4. Object rights per configuration schema: create, read, update and delete for
   `source`, `mapping`, `synchronization`, `job`, `endpoint`, `rule`,
   `consumer`, `event_subscription` and `api_product`, set on the same screen,
   stored in the same matrix, and applied to the schema's OpenRegister
   authorization block.
5. A repair step that applies the stored object rights again after every
   register import, so an upgrade does not reset them.

## Out of scope

- Roles as named bundles of actions. Nextcloud groups are the unit, as today.
- Rights per individual object, such as one source for one team.
- Deciding what OpenRegister's default authorization should be.
