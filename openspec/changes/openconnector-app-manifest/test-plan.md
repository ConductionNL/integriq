# Test Plan: openconnector-app-manifest

## Test Cases

### TC-1: Manifest file exists at canonical path
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestfilemusteexistcanonicalpath`
- **type**: regression
- **preconditions**: Fresh checkout of the openconnector repo on the D1 feature branch
- **steps**: List the contents of `openconnector/src/`
- **expected result**: `manifest.json` is present alongside `main.js` and `App.vue`
- **test command**: `ls openconnector/src/manifest.json` exits 0; `node -e "JSON.parse(require('fs').readFileSync('src/manifest.json','utf8'))"` exits 0

---

### TC-2: Manifest validates against canonical schema (zero errors)
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustvalidateagainstthecanonicalschemawithout-errors`
- **type**: regression
- **preconditions**: `npm install` completed in the openconnector directory
- **steps**: Run `npm run check:manifest`
- **expected result**: Script exits 0; no error output
- **test command**: `npm run check:manifest`

---

### TC-3: $schema field is present and correct
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclarevalid-schemareference`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse `src/manifest.json`; read the `$schema` key
- **expected result**: Value equals `"https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest.schema.json"`
- **test command**: `node -e "const m=require('./src/manifest.json'); console.assert(m['\$schema'].includes('app-manifest.schema.json'), 'wrong $schema')"`

---

### TC-4: version field is semver 1.0.0
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestversionmustfollowsemver`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; read `version`
- **expected result**: Value is `"1.0.0"` and matches `/^\d+\.\d+\.\d+$/`
- **test command**: `node -e "const m=require('./src/manifest.json'); console.assert(/^\d+\.\d+\.\d+$/.test(m.version), 'bad version: '+m.version)"`

---

### TC-5: dependencies contains openregister
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclare-openregister-asaruntimedependency`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; read `dependencies` array
- **expected result**: Array is `["openregister"]`
- **test command**: `node -e "const m=require('./src/manifest.json'); console.assert(m.dependencies.includes('openregister'), 'missing openregister dep')"`

---

### TC-6: All 13 menu entries are present
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareamenuentryforyeveryprimarynavigationitem`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; collect `menu[].id`
- **expected result**: Set of ids contains Dashboard, Sources, Endpoints, Consumers,
  Webhooks, Mappings, Jobs, CloudEvents, Synchronizations, Rules, Import, Documentation,
  Settings (13 entries)
- **test command**: `node -e "const m=require('./src/manifest.json'); const ids=m.menu.map(x=>x.id); ['Dashboard','Sources','Endpoints','Consumers','Webhooks','Mappings','Jobs','CloudEvents','Synchronizations','Rules','Import','Documentation','Settings'].forEach(id=>console.assert(ids.includes(id),'missing menu: '+id))"`

---

### TC-7: Settings section entries carry section field
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareamenuentryforyeveryprimarynavigationitem`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; filter menu entries by id in [Import, Documentation, Settings]
- **expected result**: Each has `"section": "settings"`
- **test command**: `node -e "const m=require('./src/manifest.json'); ['Import','Documentation','Settings'].forEach(id=>{const e=m.menu.find(x=>x.id===id); console.assert(e&&e.section==='settings','missing section for '+id)})"`

---

### TC-8: Documentation entry uses href not route
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareamenuentryforyeveryprimarynavigationitem`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; find menu entry with id Documentation
- **expected result**: Has `href` field; does NOT have `route` field
- **test command**: `node -e "const m=require('./src/manifest.json'); const d=m.menu.find(x=>x.id==='Documentation'); console.assert(d.href&&!d.route,'Documentation must have href, not route')"`

---

### TC-9: All 23 routes have corresponding page entries
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareapageentryforeveryroute`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; collect `pages[].id`
- **expected result**: Set of ids contains all 23 expected page ids (Dashboard,
  Sources, SourceLogs, Endpoints, EndpointDetail, EndpointLogs, Consumers,
  ConsumerDetail, Webhooks, Jobs, JobLogs, Mappings, MappingDetail, Rules, RuleDetail,
  Synchronizations, SynchronizationContracts, SynchronizationLogs, CloudEvents,
  CloudEventDetail, CloudEventLogs, Import, Settings)
- **test command**: `node -e "const m=require('./src/manifest.json'); const ids=m.pages.map(x=>x.id); ['Dashboard','Sources','SourceLogs','Endpoints','EndpointDetail','EndpointLogs','Consumers','ConsumerDetail','Webhooks','Jobs','JobLogs','Mappings','MappingDetail','Rules','RuleDetail','Synchronizations','SynchronizationContracts','SynchronizationLogs','CloudEvents','CloudEventDetail','CloudEventLogs','Import','Settings'].forEach(id=>console.assert(ids.includes(id),'missing page: '+id))"`

---

### TC-10: No duplicate page ids
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareapageentryforeveryroute`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Parse manifest; check for duplicate ids in `pages[]`
- **expected result**: All ids are unique
- **test command**: `node -e "const m=require('./src/manifest.json'); const ids=m.pages.map(x=>x.id); const set=new Set(ids); console.assert(set.size===ids.length,'duplicate page ids found')"`

---

### TC-11: Dashboard page has type dashboard and route /
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustdeclareapageentryforeveryroute`
- **type**: regression
- **preconditions**: `src/manifest.json` exists
- **steps**: Find page with id Dashboard; check type and route
- **expected result**: `type === "dashboard"`, `route === "/"`
- **test command**: `node -e "const m=require('./src/manifest.json'); const p=m.pages.find(x=>x.id==='Dashboard'); console.assert(p.type==='dashboard'&&p.route===\`/\`,'Dashboard page wrong')"`

---

### TC-12: main.js contains manifest import and useAppManifest call
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-mainjsmustimportandregisterthemani`
- **type**: regression
- **preconditions**: `src/main.js` exists
- **steps**: Read `src/main.js` as text; scan for import and call
- **expected result**: Contains `import bundledManifest from './manifest.json'` and
  `useAppManifest('openconnector', bundledManifest)`
- **test command**: `grep -q "import bundledManifest from './manifest.json'" src/main.js && grep -q "useAppManifest('openconnector'" src/main.js && echo OK`

---

### TC-13: package.json has check:manifest script
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-packagejsonmustincludeacheckmanifest-script`
- **type**: regression
- **preconditions**: `package.json` exists
- **steps**: Parse `package.json`; inspect `scripts["check:manifest"]`
- **expected result**: Key is present and non-empty
- **test command**: `node -e "const p=require('./package.json'); console.assert(p.scripts&&p.scripts['check:manifest'],'check:manifest script missing')"`

---

### TC-14: Invalid page type fails check:manifest (negative test)
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustvalidateagainstthecanonicalschemawithout-errors`
- **type**: regression
- **preconditions**: A temp copy of `src/manifest.json` with `pages[0].type = "wizard"`
- **steps**: Run `npm run check:manifest` against the modified file
- **expected result**: Script exits non-zero; validation error mentions `type`
- **test command**: Manual negative test in CI; not run on the real manifest

---

## Coverage Summary

| Requirement | Covered By |
|-------------|------------|
| Manifest file MUST exist at canonical path | TC-1 |
| Manifest MUST declare a valid schema reference | TC-3 |
| Manifest version MUST follow semver | TC-4 |
| Manifest MUST declare openregister as a dependency | TC-5 |
| Manifest MUST declare menu entries for all primary nav items | TC-6, TC-7, TC-8 |
| Manifest MUST declare a page entry for every route | TC-9, TC-10, TC-11 |
| Manifest MUST validate against canonical schema | TC-2, TC-14 |
| main.js MUST import and register the manifest composable | TC-12 |
| package.json MUST include a check:manifest script | TC-13 |

All 8 spec requirements have at least one test case.

## Out of Scope

- **Runtime UI rendering** — D1 does not wire `CnAppRoot`; no browser tests are run
  against the live app in D1. D2's test plan covers navigation rendering, page routing,
  and dependency-missing screen.
- **Backend `/api/manifest` endpoint** — not implemented in D1; no API tests.
- **Persona tests** — no new user-facing UI in D1; persona-based flows belong in D2.
- **Performance** — manifest is a ~5 KB static file; performance testing is not
  warranted for D1.
- **i18n translation completeness** — D1 declares i18n key strings; translation file
  completeness is a D2 concern.
