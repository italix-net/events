<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Events — dispatch order, isolation, and the refusals
 *
 * The assertions worth having are about the constraints, not the mechanism.
 * Dispatching a listener is four lines and hard to get wrong; what is easy to
 * get wrong is a broken listener rolling back a completed action, a wildcard
 * making the registry unprintable, or a listener quietly cancelling something.
 *
 * Run: php src/Libs/Italix/Events/tests/EventsTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Events\Events;
use Italix\Events\EventsException;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Events');

$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (EventsException $e) {
        return [true, $e->getMessage()];
    }
};

final class SavedRecord
{
    public int $record_id;

    public function __construct(int $record_id) { $this->record_id = $record_id; }
}

final class ObjectListener
{
    public array $seen = [];

    public function __invoke($payload): void { $this->seen[] = $payload; }
}

// -----------------------------------------------------------------------------
section('listeners run, in a defined order');

$order  = [];
$events = new Events();

$events->on('record.saved', static function () use (&$order): void { $order[] = 'first'; });
$events->on('record.saved', static function () use (&$order): void { $order[] = 'second'; });

$events->dispatch('record.saved');

test('both listeners ran', count($order) === 2);
test('…in registration order', $order === ['first', 'second'], json_encode($order));

$order  = [];
$events = new Events();
$events->on('e', static function () use (&$order): void { $order[] = 'normal'; });
$events->on('e', static function () use (&$order): void { $order[] = 'urgent'; }, 10);
$events->on('e', static function () use (&$order): void { $order[] = 'late'; }, -5);
$events->on('e', static function () use (&$order): void { $order[] = 'normal2'; });

$events->dispatch('e');

test('higher priority runs first', $order[0] === 'urgent', json_encode($order));
test('…then registration order within a priority', $order === ['urgent', 'normal', 'normal2', 'late'],
    json_encode($order));
test('the printed order matches the dispatch order',
    array_map(static function ($d) { return $d; }, $events->all()['e'])
    === array_map(static function ($l) { return $l->describe(); }, $events->listeners_for('e')));

// -----------------------------------------------------------------------------
section('the payload arrives untouched');

$seen   = null;
$events = new Events();
$events->on('record.saved', static function (SavedRecord $e) use (&$seen): void { $seen = $e; });

$record = new SavedRecord(13);
$events->dispatch('record.saved', $record);

test('the listener got the object', $seen === $record);
test('…with its data', $seen->record_id === 13);

$object_listener = new ObjectListener();
$events          = new Events();
$events->on('x', $object_listener);
$events->dispatch('x', 'payload');
test('an invokable object works as a listener', $object_listener->seen === ['payload']);

$events = new Events();
$events->dispatch('nobody.listening');
test('dispatching an event with no listeners is not an error', true);
test('has() reports it', !$events->has('nobody.listening'));

// -----------------------------------------------------------------------------
section('a broken listener does not take the others with it');

$ran    = [];
$events = new Events();

$events->on('record.saved', static function () use (&$ran): void { $ran[] = 'before'; });
$events->on('record.saved', static function (): void { throw new RuntimeException('mail server down'); });
$events->on('record.saved', static function () use (&$ran): void { $ran[] = 'after'; });

$failures = $events->dispatch('record.saved', new SavedRecord(1));

test('the listeners after the failure still ran', $ran === ['before', 'after'], json_encode($ran));
test('the failure is returned, not swallowed', count($failures) === 1);
test('…naming the event', $failures[0]['event_c'] === 'record.saved');
test('…and the error', strpos($failures[0]['error'], 'mail server down') !== false);
test('…and where the listener is', strpos($failures[0]['listener'], 'EventsTest.php:') !== false,
    $failures[0]['listener']);
test('failures accumulate on the dispatcher', count($events->failures()) === 1);

$reported = [];
$events   = new Events(static function (string $event_c, string $listener_c, Throwable $e) use (&$reported): void {
    $reported[] = $event_c . ' / ' . $e->getMessage();
});
$events->on('x', static function (): void { throw new LogicException('boom'); });
$events->dispatch('x');

test('an on_failure hook is called', $reported === ['x / boom'], json_encode($reported));

// -----------------------------------------------------------------------------
section('the registry can be printed, which is the whole defence');

$events = new Events();
$events->on('record.saved', 'strlen');
$events->on('record.saved', [new ObjectListener(), '__invoke']);
$events->on('account.registered', static function (): void {});

$all = $events->all();

test('every event is listed', array_keys($all) === ['account.registered', 'record.saved'], json_encode(array_keys($all)));
test('a named function describes itself', in_array('strlen', $all['record.saved'], true));
test('a method describes itself', in_array(ObjectListener::class . '::__invoke', $all['record.saved'], true));
test('a closure gives file and line', strpos($all['account.registered'][0], 'EventsTest.php:') !== false,
    $all['account.registered'][0]);
test('the total is counted', $events->count() === 3);

$events->on('x', static function (): void {}, 0, 'the nightly digest');
test('a listener can be described explicitly', $events->all()['x'] === ['the nightly digest']);

// -----------------------------------------------------------------------------
section('what the registry refuses');

[$threw, $message] = $throws(static function (): void { (new Events())->on('', 'strlen'); });
test('an unnamed event is refused', $threw);
test('…because events:list groups by it', strpos($message, 'events:list') !== false, $message);

[$threw, $message] = $throws(static function (): void { (new Events())->on('deal.*', 'strlen'); });
test('a wildcard is refused', $threw);
test('…because it would match events nobody wrote down',
    strpos($message, 'nobody can find') !== false, $message);

// -----------------------------------------------------------------------------
section('a listener cannot cancel what already happened');

$reflection = new ReflectionClass(Events::class);

test('dispatch() returns an array of failures, not a boolean',
    (string) $reflection->getMethod('dispatch')->getReturnType() === 'array',
    'a boolean return would be the beginning of "a listener said no"');

$methods = array_map(
    static function (ReflectionMethod $m): string { return $m->getName(); },
    $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
);

foreach (['stop', 'stop_propagation', 'cancel', 'veto', 'until'] as $forbidden) {
    test("there is no {$forbidden}()", !in_array($forbidden, $methods, true),
        'an event says something occurred; a listener that could cancel it is making a decision, '
        . 'and decisions belong in a policy');
}

// A listener returning false must change nothing.
$ran    = [];
$events = new Events();
$events->on('e', static function () use (&$ran) { $ran[] = 'a'; return false; });
$events->on('e', static function () use (&$ran) { $ran[] = 'b'; });
$events->dispatch('e');

test('returning false does not stop the next listener', $ran === ['a', 'b'], json_encode($ran));

// -----------------------------------------------------------------------------
section('failures reach a PSR-3 logger');

if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
    echo "  (psr/log absent — the logger assertions are skipped)\n";
} else {
    $logger = new class extends \Psr\Log\AbstractLogger {
        /** @var array<int, array{0: string, 1: string, 2: array}> */
        public array $lines = [];

        public function log($level, $message, array $context = []): void
        {
            $this->lines[] = [(string) $level, (string) $message, $context];
        }
    };

    $boom = new RuntimeException('listener exploded');

    $events = (new Events())->log_failures_to($logger);
    $events->on('order.placed', static function () use ($boom): void {
        throw $boom;
    }, 0, 'send_receipt');
    $events->on('order.placed', static function (): void {
        // still runs
    }, 0, 'update_totals');

    $failures = $events->dispatch('order.placed');

    test('the failure was logged', count($logger->lines) === 1, count($logger->lines) . ' line(s)');
    test('…at error level', ($logger->lines[0][0] ?? '') === 'error', $logger->lines[0][0] ?? 'none');
    test('…naming the event and the listener',
        ($logger->lines[0][2]['event'] ?? '') === 'order.placed'
        && ($logger->lines[0][2]['listener'] ?? '') === 'send_receipt',
        json_encode($logger->lines[0][2] ?? []));
    test('…and carrying the exception itself, not a string of it',
        ($logger->lines[0][2]['exception'] ?? null) === $boom);

    test('logging does not replace the returned failures', count($failures) === 1);

    // A logger is a network call in disguise. If it can stop the dispatch, an
    // unreachable log turns one recorded problem into an unrecorded outage.
    $ran = [];

    $hostile = new class extends \Psr\Log\AbstractLogger {
        public function log($level, $message, array $context = []): void
        {
            throw new RuntimeException('the log is down');
        }
    };

    $events = (new Events())->log_failures_to($hostile);
    $events->on('e', static function (): void {
        throw new RuntimeException('first');
    });
    $events->on('e', static function () use (&$ran): void {
        $ran[] = 'second';
    });

    $threw = false;

    try {
        $failures = $events->dispatch('e');
    } catch (Throwable $e) {
        $threw = true;
    }

    test('A LOGGER THAT THROWS DOES NOT STOP THE DISPATCH', !$threw && $ran === ['second'],
        $threw ? 'dispatch() propagated the logger\'s exception' : json_encode($ran));

    // Both channels, because an application may want a failure to page somebody
    // as well as be written down.
    $called = [];

    $events = (new Events(static function (string $event_c) use (&$called): void {
        $called[] = 'callable:' . $event_c;
    }))->log_failures_to($logger);

    $events->on('both', static function (): void {
        throw new RuntimeException('x');
    });
    $events->dispatch('both');

    test('the callable and the logger both fire', $called === ['callable:both'] && count($logger->lines) === 2,
        json_encode([$called, count($logger->lines)]));

    $threw = false;

    try {
        (new Events())->log_failures_to(new stdClass());
    } catch (EventsException $e) {
        $threw = strpos($e->getMessage(), 'PSR-3') !== false;
    }

    test('something that is not a logger is refused, with a message that names the fix', $threw);
}

// -----------------------------------------------------------------------------
section('failures reach a PSR-3 logger');

if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
    echo "  (psr/log absent — the logger assertions are skipped)\n";
} else {
    $logger = new class extends \Psr\Log\AbstractLogger {
        /** @var array<int, array{0: string, 1: string, 2: array}> */
        public array $lines = [];

        public function log($level, $message, array $context = []): void
        {
            $this->lines[] = [(string) $level, (string) $message, $context];
        }
    };

    $boom = new RuntimeException('listener exploded');

    $events = (new Events())->log_failures_to($logger);
    $events->on('order.placed', static function () use ($boom): void {
        throw $boom;
    }, 0, 'send_receipt');
    $events->on('order.placed', static function (): void {
        // still runs
    }, 0, 'update_totals');

    $failures = $events->dispatch('order.placed');

    test('the failure was logged', count($logger->lines) === 1, count($logger->lines) . ' line(s)');
    test('…at error level', ($logger->lines[0][0] ?? '') === 'error', $logger->lines[0][0] ?? 'none');
    test('…naming the event and the listener',
        ($logger->lines[0][2]['event'] ?? '') === 'order.placed'
        && ($logger->lines[0][2]['listener'] ?? '') === 'send_receipt',
        json_encode($logger->lines[0][2] ?? []));
    test('…and carrying the exception itself, not a string of it',
        ($logger->lines[0][2]['exception'] ?? null) === $boom);

    test('logging does not replace the returned failures', count($failures) === 1);

    // A logger is a network call in disguise. If it can stop the dispatch, an
    // unreachable log turns one recorded problem into an unrecorded outage.
    $ran = [];

    $hostile = new class extends \Psr\Log\AbstractLogger {
        public function log($level, $message, array $context = []): void
        {
            throw new RuntimeException('the log is down');
        }
    };

    $events = (new Events())->log_failures_to($hostile);
    $events->on('e', static function (): void {
        throw new RuntimeException('first');
    });
    $events->on('e', static function () use (&$ran): void {
        $ran[] = 'second';
    });

    $threw = false;

    try {
        $failures = $events->dispatch('e');
    } catch (Throwable $e) {
        $threw = true;
    }

    test('A LOGGER THAT THROWS DOES NOT STOP THE DISPATCH', !$threw && $ran === ['second'],
        $threw ? 'dispatch() propagated the logger\'s exception' : json_encode($ran));

    // Both channels, because an application may want a failure to page somebody
    // as well as be written down.
    $called = [];

    $events = (new Events(static function (string $event_c) use (&$called): void {
        $called[] = 'callable:' . $event_c;
    }))->log_failures_to($logger);

    $events->on('both', static function (): void {
        throw new RuntimeException('x');
    });
    $events->dispatch('both');

    test('the callable and the logger both fire', $called === ['callable:both'] && count($logger->lines) === 2,
        json_encode([$called, count($logger->lines)]));

    $threw = false;

    try {
        (new Events())->log_failures_to(new stdClass());
    } catch (EventsException $e) {
        $threw = strpos($e->getMessage(), 'PSR-3') !== false;
    }

    test('something that is not a logger is refused, with a message that names the fix', $threw);
}

exit(summary());
