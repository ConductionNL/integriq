# Directory and group synchronisation

Users and groups come from your directory and stay in step with it, so a
membership that ends somewhere else ends here too. Nextcloud keeps the accounts.
Integriq keeps the connection, the mapping, the schedule and the record of what
each run changed.

This is not authentication. Nextcloud's own LDAP and SSO backends sign people
in, and they stay in charge of that. What they do not do is keep a Nextcloud
group filled between logins, which is what an app like dossiq needs when its
`roleType.ncGroupId` points at a group and something has to fill it.

## The connection is a source

A directory connection is a source, listed and tested on the Sources screen like
any other. Give it `type: directory` and a configuration:

```json
{
  "mock": false,
  "usersEndpoint": "/Users",
  "pageSize": 200,
  "deletionRatioThreshold": 0.1,
  "mapping": {
    "createMissingGroups": false,
    "rules": [
      { "directoryGroup": "OU=Vergunningen", "group": "behandelaars" },
      { "directoryGroup": "OU=Toezicht", "group": "behandelaars" },
      { "attribute": "department", "equals": "Toezicht", "group": "toezichthouders" }
    ]
  },
  "openWork": { "consumers": ["dossiq"] }
}
```

The directory is read over its SCIM 2.0 `/Users` endpoint, and its credentials
travel the way every other outbound credential does, through a `credentialRef`
resolved by OpenRegister's credential broker. Integriq never holds the secret,
and it never holds a password for a synchronised account either.

Set `"mock": true` and a `fixture` to try a mapping without a directory:

```json
{
  "mock": true,
  "fixture": {
    "complete": true,
    "users": [
      { "userName": "anja", "displayName": "Anja", "groups": ["OU=Vergunningen"] }
    ]
  }
}
```

## The mapping is configuration

Many directory groups can name one Nextcloud group, which is the common case.
A rule matches on a directory group name, or on a directory attribute value.

`createMissingGroups` decides what happens when the mapping names a Nextcloud
group that does not exist. With it on, the group is created. With it off, the
run stops and names the group. There is deliberately no third option: dropping
the membership quietly is what produces a permission nobody can explain.

## Running it

The connection runs hourly on its own, and on demand from the Sources screen:

* **Preview directory run** shows what would change and writes nothing.
* **Run directory sync** applies it.

A run whose removals cross `deletionRatioThreshold` stops before writing and
says which ratio it hit. That is the case a directory outage looks like, where
half the users are missing and every group would empty at once. **Confirm
removals** resumes the run once you have read what it was going to do.

## What each run says

Read the runs on **Directory runs**. Each one carries its start and end, how
many users and groups were read, how many memberships were added, changed and
removed, and every item that failed with its reason. A failed item never stops
the run.

A run that ends someone's last mapped membership also asks the other apps what
that account still holds, and puts the answers on the run. Integriq does not
read another app's data to find out, and it reassigns nothing: reassignment is a
decision with a policy behind it, and the policy belongs to the app that owns
the work. An app that is not installed, or that does not answer, reads
`unknown`. It never reads zero, because an app that never looked and an app that
looked and found nothing are different answers.

## SCIM provisioning

If your identity system pushes changes rather than waiting to be read, point it
at the SCIM 2.0 endpoint:

```
GET    /apps/integriq/api/scim/v2/Users
POST   /apps/integriq/api/scim/v2/Users
GET    /apps/integriq/api/scim/v2/Users/{id}
PUT    /apps/integriq/api/scim/v2/Users/{id}
PATCH  /apps/integriq/api/scim/v2/Users/{id}
DELETE /apps/integriq/api/scim/v2/Users/{id}
GET    /apps/integriq/api/scim/v2/Groups
PATCH  /apps/integriq/api/scim/v2/Groups/{id}
```

Authenticate with a consumer API key as a bearer token. A call without a valid
credential is rejected before any account is read.

A deactivation disables the Nextcloud account. It never deletes it, and neither
does `DELETE` on a user, which is a deprovision. Deleting an account deletes the
trail behind everything it did, which is the finding an auditor writes up rather
than the one they were looking for.

## What this does not do

Integriq does not act as an identity provider for other products. Nextcloud is
already an OAuth provider, and integriq's DigiD and eHerkenning adapter stays
the one component that holds the government identity conversation. Putting a
second issuer next to it would give one fleet two identity stories.
