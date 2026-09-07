<?php

namespace App\Services\Training;

use RuntimeException;

/**
 * Raised when a routine write cannot proceed against the routine's current
 * state — for now, only a reorder whose `order` does not name exactly the
 * action's current rows, once each.
 */
class RoutineOrderException extends RuntimeException {}
