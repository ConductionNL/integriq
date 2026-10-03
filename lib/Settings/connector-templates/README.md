# Connector template library

The Store lists every template here as a card. The register import never
creates a source from them: a source exists only after an administrator
chooses Instantiate on the card (connectors-catalogue-expansion, design D1).
That is the difference with `../register.d/`, whose seeded sources land on
every install.

One JSON file per template, one folder per set:

- `backoffice/`: municipal back-office systems, checked by a person. See its README.
- `saas/`: common business software. Generated ones come from the pinned
  APIs.guru snapshot and `saas/allow-list.json`; a curated one is written by hand.

A template is a `source` payload plus an `x-template` block:

| Key | Meaning |
|---|---|
| `slug` | The source slug Instantiate creates, and the card's key (`template:<slug>`) |
| `vendor`, `system` | Who makes it and what it is called |
| `standard` | The interface integriq reaches it over |
| `verifiedAgainst` | The published interface description it was checked against |
| `tier` | `curated` (checked by a person) or `generated` (from the snapshot) |
| `snapshotDate` | Generated only: the snapshot the template came from |
| `category` | The Store category |

A template never carries a credential. The credential goes into the
credential broker after Instantiate and the source names it by
`credentialRef`. `npm run check:connector-templates` enforces the shape.

To regenerate the SaaS set: `php scripts/generate-connector-templates.php refresh`
(network, re-pins the snapshot), then `php scripts/generate-connector-templates.php generate`.
