<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Events - Exception
 *
 * @package Italix\Events
 */

declare(strict_types=1);

namespace Italix\Events;

use RuntimeException;

/**
 * The registry cannot do what was asked: an event with no name, a listener that
 * is not callable, a dispatch of something the registry has never heard of.
 *
 * A *listener* that throws is a different matter and is handled by the
 * dispatcher — see `Events::dispatch()`.
 */
final class EventsException extends RuntimeException
{
}
