<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Events - factory function
 *
 * @package Italix\Events
 */

declare(strict_types=1);

namespace Italix\Events;

if (!function_exists(__NAMESPACE__ . '\events')) {

    function events(?callable $on_failure = null): Events
    {
        return new Events($on_failure);
    }
}
