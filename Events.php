<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Events - Events
 *
 * @package Italix\Events
 */

declare(strict_types=1);

namespace Italix\Events;

use Throwable;

/**
 * A registry of named events and the listeners that react to them.
 *
 *     $events->on('record.status_changed', static function (RecordStatusChanged $e) use ($queue): void {
 *         $queue->push(new SendVerificationMail($e->record_id));
 *     });
 *
 *     $events->dispatch('record.status_changed', new RecordStatusChanged($record_id, 'CONFIRMED'));
 *
 * ## Read this before using it
 *
 * Events are how a codebase becomes untraceable. Control flow leaves the
 * function you are reading and reappears somewhere a `grep` for the method name
 * will not find, and six months later nobody can answer what saving a record
 * actually does. That is not a hypothetical risk; it is the normal outcome.
 *
 * Three deliberate constraints push against it, and they are the reason this is
 * worth shipping at all:
 *
 * **Declared, not discovered.** Listeners are registered in configuration, like
 * routes and console commands. There is no scan of a directory, no attribute,
 * no naming convention that makes a class a listener. The complete set is a
 * list somebody wrote.
 *
 * **Printable.** `ix events:list` prints every event, every listener and the
 * file and line each one is defined at, in dispatch order. If the registry
 * cannot be printed, the objection above is unanswerable.
 *
 * **No cancellation.** A listener cannot stop propagation and cannot veto the
 * thing that happened. An event says *this has occurred*; a listener that could
 * cancel it would be making a decision, and decisions belong in a policy where
 * they can be found. Without this rule, `record.saving` becomes a hook that
 * silently prevents saves.
 *
 * ## A listener that throws does not take the dispatcher with it
 *
 * ## This is not PSR-14, and does not pretend to be
 *
 * PSR-14 dispatches **by the type of an event object** — `dispatch(object
 * $event): object` — with listeners supplied by a separate
 * `ListenerProviderInterface`. This dispatches by a string name, with the
 * listeners held here.
 *
 * The difference is not cosmetic. Under PSR-14 the name of an event is a class,
 * so `events:list` could not print a catalogue without loading every one of
 * them, and two events with the same shape need two classes. Under this design
 * a listener is registered against a string somebody chose, which is what makes
 * the list readable and the wildcard ban enforceable.
 *
 * An adapter is possible and is not shipped, because a partial one would be
 * worse than none: consumers type against `EventDispatcherInterface` and would
 * discover the difference at run time. If PSR-14 is what you need, use a PSR-14
 * dispatcher.
 *
 * `psr/log`, by contrast, *is* supported — see {@see self::log_failures_to()}.
 * The distinction is whether the standard describes the same thing this does.
 *
 * Reactions are secondary by definition — the thing already happened. So a
 * throwing listener is recorded and the rest still run, because one broken
 * notification must not roll back a completed transaction. `dispatch()` returns the
 * failures rather than swallowing them, and an application that wants them
 * fatal can check.
 */
final class Events
{
    /** @var array<string, Listener[]> */
    private array $listeners = [];

    /** @var array<int, array{event_c: string, listener: string, error: string}> */
    private array $failures = [];

    /** @var callable|null fn(string $event_c, string $listener_c, Throwable $e): void */
    private $on_failure;

    /** @var object|null a PSR-3 logger, if one was given */
    private $logger;

    /**
     * @param callable|null $on_failure called for every listener that throws
     */
    public function __construct(?callable $on_failure = null)
    {
        $this->on_failure = $on_failure;
    }

    /**
     * Send every listener failure to a PSR-3 logger as well.
     *
     *     events()->log_failures_to($container->get(LoggerInterface::class));
     *
     * `$on_failure` was already the seam, but it is a closure: every
     * application that wanted its failures recorded wrote the same three lines
     * wrapping the same logger. `Psr\Log\LoggerInterface` is the one interface
     * every framework, every library and every hosted log service already
     * speaks, so accepting it directly removes the adapter rather than
     * documenting it.
     *
     * Typed as `object` and checked at run time so that `psr/log` stays a
     * suggestion: this package requires nothing today, and a logging interface
     * is not worth being the first.
     *
     * The two are not exclusive. `$on_failure` is called as well, because an
     * application may want a failure to page somebody rather than only be
     * written down.
     *
     * A logger that throws is caught and ignored: a listener already failed,
     * and losing the rest of the listeners because the *log* was unreachable
     * would turn a recorded problem into an unrecorded outage.
     */
    public function log_failures_to(object $logger): self
    {
        if (!method_exists($logger, 'error')) {
            throw new EventsException(
                'log_failures_to() expects a PSR-3 logger — an object with an error() method. '
                . 'Install psr/log, or pass a callable to the constructor instead.'
            );
        }

        $this->logger = $logger;

        return $this;
    }

    /**
     * Register a listener.
     *
     * @param int $priority_n higher runs first; equal priorities keep
     *                        registration order, so a list read top to bottom
     *                        is the order things happen in
     */
    public function on(string $event_c, callable $handler, int $priority_n = 0, string $described_c = ''): self
    {
        if ($event_c === '') {
            throw new EventsException('An event needs a name: it is what `events:list` groups by.');
        }

        if (strpos($event_c, '*') !== false) {
            throw new EventsException(
                "Wildcards are not supported (\"{$event_c}\"). A listener that matches events nobody "
                . 'wrote down is a listener nobody can find.'
            );
        }

        $this->listeners[$event_c][] = new Listener($event_c, $handler, $priority_n, $described_c);

        return $this;
    }

    /**
     * Notify every listener for $event_c, in dispatch order.
     *
     * @param  mixed $payload usually an object describing what happened
     * @return array<int, array{event_c: string, listener: string, error: string}> failures, empty when all succeeded
     */
    public function dispatch(string $event_c, $payload = null): array
    {
        $failures = [];

        foreach ($this->listeners_for($event_c) as $listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                $failure = [
                    'event_c'  => $event_c,
                    'listener' => $listener->describe(),
                    'error'    => get_class($e) . ': ' . $e->getMessage(),
                ];

                $failures[]       = $failure;
                $this->failures[] = $failure;

                if ($this->logger !== null) {
                    try {
                        $this->logger->error(
                            'Event listener failed: {listener} on {event}',
                            [
                                'event'     => $event_c,
                                'listener'  => $listener->describe(),
                                'exception' => $e,
                            ]
                        );
                    } catch (Throwable $ignored) {
                        // A listener already failed. Losing the remaining
                        // listeners because the log was unreachable would turn
                        // a recorded problem into an unrecorded outage.
                    }
                }

                if ($this->on_failure !== null) {
                    ($this->on_failure)($event_c, $listener->describe(), $e);
                }

                // Deliberately continuing: the thing already happened, and one
                // broken reaction must not prevent the others.
            }
        }

        return $failures;
    }

    /**
     * The listeners for an event, in the order they will be called.
     *
     * @return Listener[]
     */
    public function listeners_for(string $event_c): array
    {
        $listeners = $this->listeners[$event_c] ?? [];

        // A stable sort: equal priorities keep registration order, so the
        // printed list and the dispatch order are the same thing.
        $indexed = [];

        foreach ($listeners as $i => $listener) {
            $indexed[] = [$listener->priority_n(), $i, $listener];
        }

        usort($indexed, static function (array $a, array $b): int {
            return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
        });

        return array_map(static function (array $row): Listener { return $row[2]; }, $indexed);
    }

    public function has(string $event_c): bool
    {
        return ($this->listeners[$event_c] ?? []) !== [];
    }

    /**
     * Every event and listener, for `ix events:list`.
     *
     * @return array<string, string[]> event name => listener descriptions, in dispatch order
     */
    public function all(): array
    {
        $names = array_keys($this->listeners);
        sort($names);

        $all = [];

        foreach ($names as $event_c) {
            $all[$event_c] = array_map(
                static function (Listener $l): string { return $l->describe(); },
                $this->listeners_for($event_c)
            );
        }

        return $all;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->listeners));
    }

    /**
     * Every failure since construction — for a status command, or a test.
     *
     * @return array<int, array{event_c: string, listener: string, error: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
