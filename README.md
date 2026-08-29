# Italix Events

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Named events with declared listeners. Zero dependencies.

```bash
php src/Libs/Italix/Events/tests/EventsTest.php
```

---

## Read this first

**Events are how a codebase becomes untraceable.** Control flow leaves the function you are reading
and reappears somewhere a `grep` for the method name will not find, and six months later nobody can
answer what saving a record actually does. That is not a hypothetical risk; it is the normal
outcome.

Three constraints push against it, and they are the reason this is worth shipping at all.

**Declared, not discovered.** Listeners are registered in configuration, like routes and console
commands. No directory scan, no attribute, no naming convention that makes a class a listener. The
complete set is a list somebody wrote.

**Printable.** `ix events:list` prints every event, every listener and the file and line each one is
defined at, in dispatch order. If the registry could not be printed, the first objection would be
unanswerable.

**No cancellation.** There is no `stop()`, no `stop_propagation()`, no `until()`. An event says
*this occurred*; a listener that could cancel it would be making a decision, and decisions belong in
a policy where they can be found. Without this rule, `record.saving` becomes a hook that silently
prevents saves. A listener returning `false` changes nothing, and the suite asserts the absence of
every cancelling method by name.

---

## Using it

```php
// conf.php — the whole registry, in one place
Events::class => static fn(): Events => (new Events())
    ->on('record.status_changed', [NotifyParties::class, 'handle'])
    ->on('record.status_changed', [WriteAuditRow::class, 'handle'], 10),
```

```php
$this->events->dispatch('record.status_changed', new RecordStatusChanged($id, 'CONFIRMED'));
```

Higher priority runs first; equal priorities keep registration order, so the printed list and the
dispatch order are the same thing.

---

## A listener that throws does not take the dispatcher with it

Reactions are secondary by definition — the thing already happened. A throwing listener is recorded
and the rest still run, because one broken notification must not roll back a completed transaction.

`dispatch()` **returns** the failures rather than swallowing them:

```php
$failures = $events->dispatch('record.saved', $event);

foreach ($failures as $failure) {
    // ['event_c' => …, 'listener' => 'closure conf.php:212', 'error' => 'RuntimeException: …']
}
```

An `on_failure` callback passed to the constructor is called for each one, which is where a log line
belongs.

---

## Failures, to a PSR-3 logger

```php
$events = events()->log_failures_to($container->get(LoggerInterface::class));
```

The `$on_failure` callable was already the seam, but every application that wanted its failures
recorded wrote the same three lines wrapping the same logger. Both channels fire — an application may
want a failure to page somebody as well as be written down.

`psr/log` stays a suggestion: the argument is typed `object` and checked at run time, because this
package requires nothing today and a logging interface is not worth being the first. A logger that
throws is caught and ignored; losing the remaining listeners because the *log* was unreachable would
turn a recorded problem into an unrecorded outage.

---

## This is not PSR-14

PSR-14 dispatches by **the type of an event object** — `dispatch(object $event): object` — with
listeners supplied by a separate `ListenerProviderInterface`. This dispatches by a string name, with
the listeners held here.

The difference is not cosmetic. Under PSR-14 an event's name is a class, so `events:list` could not
print a catalogue without loading every one of them, and two events of the same shape need two
classes. Here a listener is registered against a string somebody chose, which is what makes the list
readable and the wildcard ban enforceable.

An adapter is possible and is not shipped. A partial one would be worse than none: consumers type
against `EventDispatcherInterface` and would meet the difference at run time. If PSR-14 is what you
need, use a PSR-14 dispatcher.

---

## Deliberately not

- **No wildcards.** `on('order.*')` throws. A listener matching events nobody wrote down is a
  listener nobody can find.
- **No auto-discovery** from class names or attributes.
- **No cancellation** — see above.
