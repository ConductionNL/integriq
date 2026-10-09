# SLO curriculum source

Import the Dutch national curriculum goals from SLO into Learniq as goal trees, instead of typing them in by hand. SLO (nationaal expertisecentrum curriculumontwikkeling) publishes kerndoelen, examenprogramma's and leerdoelenkaarten as open data under CC BY 4.0.

## What ships

- One **source** object, `slo-curriculum`, pointing at `https://opendata.slo.nl/curriculum/api/v1`. It ships **disabled** and holds no credential.
- Two **mapping** objects, `slo-curriculum-framework-mapping` and `slo-curriculum-competency-mapping`. Their keys are the Learniq field names an import fills.
- Six **sets** in the source's `configuration.sets`:

| Set | What you get | One framework per |
|---|---|---|
| `fo-kerndoelen` | The renewed kerndoelen for primary and lower secondary (funderend onderwijs) | SLO set, such as "Kerndoelen burgerschap" |
| `fo-examenprogramma` | The renewed examenprogramma's | SLO set |
| `kerndoelen-2006-po` | The 2006 kerndoelen for primary school | the whole set |
| `kerndoelen-2006-onderbouw-vo` | The 2006 kerndoelen for lower secondary | the whole set |
| `examenprogramma` | The current examenprogramma's, with their `versie` as edition | examenprogramma |
| `leerdoelenkaarten` | SLO's goals and content per subject (vakinhouden and doelen) | subject |

- A **catalogue card** "SLO curriculum (open data)" under Education data.

Until you switch it on, the adapter answers from a recorded copy of real SLO data. Nothing leaves your server.

## Go live

1. Register for a free API key at `https://opendata.slo.nl/curriculum/2021/api/v1/register/`. SLO sends the key by e-mail. Every JSON call needs it.
2. Open the `slo-curriculum` source. Set `username` to the registered e-mail address and `password` to the key. The password is write-only. You can also point `configuration.authentication.credentialRef` at a credential in the OpenRegister credential broker instead.
3. Enable the source.
4. Switch the live transport on: `occ config:app:set integriq slo.curriculum.feature_flag --value=1`.

## What an import produces

One import gives one Learniq framework and its goals, parents before children:

- **Framework**: name, source authority (`slo-kerndoelen`, `slo-eindtermen` or `other`), a link to the SLO set, the edition, the education level, and a description that ends with the SLO credit and licence.
- **Goals**: code, title and description from SLO, the parent goal, the order among siblings, and `applicableYears`.
- **Years** come only from SLO's own structure. A leerdoelenkaart goal tagged "groep 3-4" gets `groep 3` and `groep 4`. Kerndoelen and eindtermen are end-of-phase goals, so they apply to every year of the framework.
- **Subjects**: pass a map from the SLO subject (vakleergebied) to one of your Learniq courses and the top level of the tree is linked to it. Without a map, you link subjects later. The import never creates courses.
- **Ids are stable.** Import the same set again and the same goals are updated, not duplicated. When SLO revises a set, it gets a new id, so the revision arrives as a new framework next to the old one.

The step that writes these records into Learniq follows in a later release. It waits for Learniq's schema to carry `applicableYears` and `subjectId`.

## Attribution

SLO's data is licensed CC BY 4.0. Every imported framework carries this credit in its description: "Bron: SLO, nationaal expertisecentrum curriculumontwikkeling (opendata.slo.nl). Licentie: CC BY 4.0." Keep it when you edit the framework.

## Limits

- MBO kwalificatiedossiers (SBB) are not included: their licence terms are not confirmed.
- The recorded copy holds a small subset: one renewed set, all 2006 kerndoelen, one examenprogramma and one leerdoelenkaart branch. Live imports read everything SLO publishes.

Next: add a set of your own, such as the special education kerndoelen, by copying a profile in `lib/Settings/register.d/slo-curriculum-source.json` and changing its niveau filter.
