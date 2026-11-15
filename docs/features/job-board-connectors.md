# Job board connectors

Integriq carries the outside half of humaniq's multiposting: humaniq's
*Vacature plaatsen* flow sends one vacancy to each job board it lists, and
integriq holds the board templates, the mappings and the feed. Every board
ships dormant; an administrator connects it.

## How a vacancy reaches a board

The flow step names its source and mapping per item, for example
`jobboard-{{ json.channel }}`, so one step sends each vacancy through the
board it is meant for. A board without a configured source turns into an error
item naming that source while the other boards go ahead (with `onError:
continue`). A plain reference behaves as before.

| Board | How | Credential |
|---|---|---|
| LinkedIn | push through LinkedIn's job posting API (simpleJobPostings) | OAuth client, its secret by broker reference (`jobboard-linkedin-client-secret`) |
| Indeed | Indeed pulls the vacancy feed | none; the feed URL is registered with Indeed |
| werk.nl | not shipped yet | UWV publishes no employer posting API or format to build against |

## Connecting LinkedIn

LinkedIn only accepts postings through its Job Posting API from applications
accepted into its Talent Solutions partner programme. Apply there first, then:

1. Store the OAuth client secret in the credential broker under
   `jobboard-linkedin-client-secret`.
2. Fill the source `jobboard-linkedin` with the client id and enable it.
3. In the mapping `jobboard-linkedin`, fill the organisation URN
   (`integrationContext`) and the apply URL of your careers site.

LinkedIn answers with a posting task id, which the humaniq flow keeps as the
posting's reference.

## Connecting Indeed: the vacancy feed

Indeed reads a feed instead of taking a push. Integriq serves it at

```
/index.php/apps/integriq/api/endpoint/jobfeeds/indeed.xml
```

It lists humaniq's vacancies whose status is published (`gepubliceerd`) and
whose channels include `indeed`, each as one `<job>` of Indeed's XML job feed
under a `<source>` root. The feed is anonymous, because it is a public list of
public vacancies, throttled to 60 reads a minute per client, and carries no
applicant data: only the fields of the `jobboard-indeed` mapping leave the
register. The `.xml` path makes it answer as XML without an `Accept` header,
which is what a crawler sends.

To go live:

1. In the mapping `jobboard-indeed`, fill `company`, `sourcename` and `url`
   (the vacancy page, for example
   `https://werken.example.nl/vacatures/{{ vacancyId }}`).
2. In the mapping `jobboard-indeed-feed`, fill `publisher` and
   `publisherurl`.
3. Register the feed URL with Indeed under its terms for employer feeds.

## Related

- [Endpoints](endpoints.md)
- [Mappings](mappings.md)
- [Flow nodes](flow-nodes.md)
