# Changelog — italix/events

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.0.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only the method name changed, no behavior:

- `Listener::event_c()` → `event_code()`

## [1.1.0] — 2026-08-17

### Added

- **`log_failures_to(object $logger)`** — listener failures also go to a PSR-3 logger.

  `$on_failure` was already the seam, but it is a closure, and every application that wanted its
  failures recorded wrote the same three lines wrapping the same logger. `Psr\Log\LoggerInterface`
  is the one interface every framework and every hosted log service already speaks.

  Typed as `object` and checked at run time, so `psr/log` stays a suggestion — this package requires
  nothing today and a logging interface is not worth being the first. Both channels fire: an
  application may want a failure to page somebody as well as be written down. A logger that throws
  is caught and ignored, because losing the remaining listeners over an unreachable *log* would turn
  a recorded problem into an unrecorded outage.

### Documentation

- **This is not PSR-14, stated in the class rather than left to be assumed.** PSR-14 dispatches by
  the type of an event object, with a separate `ListenerProviderInterface`; this dispatches by a
  string name with the listeners held here. The difference is not cosmetic — under PSR-14 an event's
  name is a class, so `events:list` could not print a catalogue without loading every one of them,
  and the wildcard ban would have nothing to enforce against. An adapter is possible and is not
  shipped, because a partial one is worse than none: consumers type against the interface and would
  meet the difference at run time.

## [1.0.1] — 2026-08-13

### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [1.0.0] — 2026-08

First release. See `README.md` for usage.

### Added

- **`Events`** — `on()`, `dispatch()`, `listeners_for()`, `has()`, `all()`, `failures()`.
- **`Listener`** — carries its own description: a named function, a `Class::method`, or a closure's
  file and line.

### The constraints are the library

Events are how a codebase becomes untraceable: control flow leaves the function being read and
reappears where a `grep` for the method name will not find it. Three rules push against that, and
they are the reason this is worth shipping at all.

**Declared, not discovered.** Listeners are registered in configuration, like routes and console
commands. No directory scan, no attribute, no naming convention. The complete set is a list somebody
wrote.

**Printable.** `all()` returns every event, every listener and where each is defined, in dispatch
order. If the registry could not be printed, the first objection would be unanswerable.

**No cancellation.** No `stop()`, no `stop_propagation()`, no `until()`. An event says *this
occurred*; a listener that could cancel it would be making a decision, and decisions belong in a
policy where they can be found. A listener returning `false` changes nothing, and there is a test
asserting the absence of every cancelling method by name.

### And a listener that throws does not take the dispatcher with it

Reactions are secondary by definition — the thing already happened. A throwing listener is recorded
and the rest still run, because one broken notification must not roll back a completed deal.
`dispatch()` returns the failures rather than swallowing them.

### Deliberately not

No wildcard listeners — a listener matching events nobody wrote down is a listener nobody can find,
and `on()` refuses a name containing `*`. No auto-discovery from class names.
