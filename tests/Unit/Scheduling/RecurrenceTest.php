<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Recurrence;
use PHPUnit\Framework\TestCase;

class RecurrenceTest extends TestCase
{
    public function test_maps_known_tokens(): void
    {
        $this->assertSame(Recurrence::Daily, Recurrence::tryFromToken('daily'));
        $this->assertSame(Recurrence::Weekdays, Recurrence::tryFromToken('weekdays'));
        $this->assertSame(Recurrence::Weekly, Recurrence::tryFromToken('weekly'));
    }

    public function test_once_null_and_unknown_tokens_are_one_off(): void
    {
        $this->assertNull(Recurrence::tryFromToken('once'));
        $this->assertNull(Recurrence::tryFromToken(null));
        $this->assertNull(Recurrence::tryFromToken('fortnight'));
    }

    public function test_the_two_longer_cadences_map_from_their_tokens(): void
    {
        $this->assertSame(Recurrence::Fortnightly, Recurrence::tryFromToken('fortnightly'));
        $this->assertSame(Recurrence::Monthly, Recurrence::tryFromToken('monthly'));
    }

    /**
     * `once` is not a case — it maps to a null recurrence, which is what a
     * one-off is — but it is part of the vocabulary a person chooses from, so
     * it belongs in the list the validation surfaces derive from.
     */
    public function test_the_vocabulary_is_once_plus_every_case(): void
    {
        $tokens = Recurrence::tokens();

        $this->assertContains('once', $tokens);

        foreach (Recurrence::cases() as $case) {
            $this->assertContains($case->value, $tokens, "{$case->value} is missing from the vocabulary.");
        }

        $this->assertCount(count(Recurrence::cases()) + 1, $tokens);
    }

    /**
     * The vocabulary is what every request class validates against, so a
     * duplicate would show up as a repeated option in three select controls.
     */
    public function test_the_vocabulary_has_no_duplicates(): void
    {
        $tokens = Recurrence::tokens();

        $this->assertSame(array_values(array_unique($tokens)), $tokens);
    }

    /**
     * The order is what three select controls render in, and `once` leads on
     * purpose — shortest commitment first. `RecurrenceVocabularyTest` holds the
     * client's mirrored list to this same order, so it is asserted here once
     * rather than described in each place that depends on it.
     */
    public function test_the_vocabulary_reads_shortest_commitment_first(): void
    {
        $this->assertSame(
            ['once', 'daily', 'weekdays', 'weekly', 'fortnightly', 'monthly'],
            Recurrence::tokens(),
        );
    }
}
