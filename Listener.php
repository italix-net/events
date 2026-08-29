<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Events - Listener
 *
 * @package Italix\Events
 */

declare(strict_types=1);

namespace Italix\Events;

/**
 * One registered listener, with enough about itself to be printed.
 *
 * `ix events:list` is not a nicety here: a registry is the mechanism by which a
 * codebase becomes untraceable, and the only defence is that the whole of it
 * can be printed. A listener that cannot describe itself is a line missing from
 * that output.
 */
final class Listener
{
    private string $event_c;

    /** @var callable */
    private $handler;

    private int $priority_n;
    private string $described_c;

    public function __construct(string $event_c, callable $handler, int $priority_n = 0, string $described_c = '')
    {
        $this->event_c     = $event_c;
        $this->handler     = $handler;
        $this->priority_n  = $priority_n;
        $this->described_c = $described_c !== '' ? $described_c : self::describe_handler($handler);
    }

    public function event_code(): string
    {
        return $this->event_c;
    }

    public function priority_n(): int
    {
        return $this->priority_n;
    }

    /**
     * Where this listener is defined, as well as it can be worked out.
     */
    public function describe(): string
    {
        return $this->described_c;
    }

    /**
     * @param mixed $payload
     */
    public function __invoke($payload): void
    {
        ($this->handler)($payload);
    }

    /**
     * @param callable $handler
     */
    private static function describe_handler($handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $target = is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0];

            return $target . '::' . (string) $handler[1];
        }

        if ($handler instanceof \Closure) {
            // A closure has no name, so the file and line are the only honest
            // answer — and they are what someone reading `events:list` needs.
            try {
                $reflection = new \ReflectionFunction($handler);

                return 'closure ' . basename((string) $reflection->getFileName())
                     . ':' . $reflection->getStartLine();
            } catch (\ReflectionException $e) {
                return 'closure';
            }
        }

        return is_object($handler) ? get_class($handler) : 'callable';
    }
}
