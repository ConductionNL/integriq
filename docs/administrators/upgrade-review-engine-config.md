# Review engine configuration after upgrading

Integriq stores its engine configuration (endpoints, mappings, jobs,
synchronizations, contracts, flows, event subscriptions, ZGW notification
subscriptions, environments, LTI and EUDI rows) as OpenRegister objects. Before
this release those schemas carried no authorization block, so **every signed-in
account could create and change them** through OpenRegister's generic objects
API. The release makes create, update and delete administrator-only
(`lib/Settings/register.d/99-engine-config-lockdown.json`).

The lockdown does not touch what already exists. An object a non-administrator
created before the upgrade:

- keeps working, because the engine reads its own configuration in system
  context: a planted endpoint keeps answering, a planted subscription keeps
  delivering;
- stays editable by the account that created it, because OpenRegister lets an
  object's owner change it.

Check once, right after the upgrade, which engine objects an ordinary account
owns.

## 1. List the objects owned by non-administrators

Run this as an administrator. It prints every engine object whose owner is not
in the `admin` group (seeded objects belong to `__system__` and are skipped).

```bash
NC=https://nextcloud.example.org          # your instance
AUTH='admin:<app-password>'               # an administrator app password
ADMINS=$(occ group:list --output=json | jq -c '.admin')   # or list them by hand

for schema in endpoint mapping job synchronization synchronization_contract flow \
    event_subscription notificaties_abonnement environment lti_deployment \
    lti_identity_link eudi_credential_offer eudi_issuance_session eudi_status_list; do
  curl -s -u "$AUTH" -H 'OCS-APIRequest: true' \
    "$NC/index.php/apps/openregister/api/objects/integriq/$schema?_limit=1000" \
  | jq -r --arg s "$schema" --argjson admins "$ADMINS" '
      .results[]?
      | select(.["@self"].owner as $o | ($o != "__system__") and (($admins | index($o)) == null))
      | "\($s)\t\(.id)\t\(.["@self"].owner)\t\(.name // .title // "")"'
done
```

`occ` stands for `php occ` as the web server user (for example
`sudo -u www-data php occ`). Each line is `schema`, object id, owner, name.

## 2. Decide per object

- **You recognise it**: leave it. To take it out of the owner's hands, open it in
  Integriq as an administrator and save it, or ask the owner who it serves.
- **You do not recognise it**: disable or delete it in Integriq. Pay most
  attention to `endpoint` (it can serve anonymous traffic with a source's stored
  credentials), `event_subscription` and `notificaties_abonnement` (they deliver
  data to a URL of their creator's choice) and `job` (it runs an action).

## 3. Optional: end sessions opened through the old login

Before this release `POST /api/user/login` signed in accounts with two-factor
authentication on their password alone. Sessions opened that way are not ended
by the upgrade. To end them for an account that uses two-factor authentication:

```bash
occ user:auth-tokens:list <uid>
occ user:auth-tokens:delete <uid> <token-id>
```

The account signs in again through Nextcloud's own login, with its second factor.
