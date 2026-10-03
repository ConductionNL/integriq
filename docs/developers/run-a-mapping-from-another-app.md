# Run a mapping from another app

Another app can run an integriq mapping by its slug and read the result in the same request. You dispatch one typed event; integriq answers on the event itself. There is no HTTP call and no integriq class to resolve from the container.

## The event

`OCA\Integriq\Event\MappingExecutionRequestedEvent`

```php
use OCA\Integriq\Event\MappingExecutionRequestedEvent;

$event = new MappingExecutionRequestedEvent(
    mappingSlug: 'woo-index-publication',
    input: $publication,
    sourceApp: 'opencatalogi',
    correlationId: $sitemapRunId,
);
$this->eventDispatcher->dispatchTyped($event);

if ($event->isHandled() === true) {
    $fields = $event->getOutput();
} else {
    $refusal = $event->getRefusal(); // ['code' => ..., 'reason' => ...] or null
}
```

The dispatch is synchronous. When integriq is not installed, nothing answers: `isHandled()` is false and `getRefusal()` is null. Treat that as "no mapping ran".

## Refusals

| Code | When |
|------|------|
| `not-found` | No mapping has this slug. |
| `not-allowed` | The mapping does not list your app id in `callableBy`. |
| `failed` | The mapping ran and threw. The reason carries the message. |

## Who may run a mapping

A mapping lists the app ids allowed to run it in `callableBy`. An empty list lets no app in, and that is the default for every mapping. An administrator sets the list on the mapping's detail page under **Run by other apps**. Changing it needs the same right as changing the mapping's rules.

A mapping can call other mappings and read files, so it is not opened to every app by default.

## The Woo-index mapping

Integriq seeds `woo-index-publication`, callable by `opencatalogi`. It maps a publication onto the Woo-index fields:

| Field | Rule |
|-------|------|
| `publisher` | `{{ tooiIdentifier }}` |
| `officieleTitel` | `{{ title\|default(name) }}` |
| `informatiecategorie` | `{{ tooiCategorieUri\|default(category) }}` |
| `soortHandeling` | `{{ soortHandeling }}` |

A missing value stays empty, so the gap shows in the sitemap check. The seed creates the mapping once. An upgrade does not overwrite an administrator's edit.
