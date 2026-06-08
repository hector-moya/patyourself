<?php

namespace App\Services\Coach\Strategy;

use App\Models\Strategy;

/**
 * The behavioural chain a habit loop runs through:
 * cue -> craving -> response -> reward.
 *
 * Restrategizing on failure moves the intervention point "up" (earlier) or
 * "down" (later) this chain; this helper names that move so the strategy
 * timeline can render which way the coaching shifted.
 */
final class BehavioralChain
{
    /** @var list<string> Ordered points, earliest first. */
    public const ORDER = [
        Strategy::POINT_CUE,
        Strategy::POINT_CRAVING,
        Strategy::POINT_RESPONSE,
        Strategy::POINT_REWARD,
    ];

    /**
     * The direction of a move between two points:
     * "earlier" (up the chain), "later" (down), "same", or "unknown".
     */
    public static function direction(string $from, string $to): string
    {
        $f = array_search($from, self::ORDER, true);
        $t = array_search($to, self::ORDER, true);

        if ($f === false || $t === false) {
            return 'unknown';
        }

        return match ($t <=> $f) {
            0 => 'same',
            1 => 'later',
            default => 'earlier',
        };
    }
}
