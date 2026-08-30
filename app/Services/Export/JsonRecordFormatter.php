<?php

namespace App\Services\Export;

/**
 * The complete machine-readable dump. Pretty-printed because a record you
 * cannot read in a text editor is only half portable, and with unescaped
 * slashes and unicode so the user's own words come out looking like their
 * own words.
 */
final readonly class JsonRecordFormatter
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function render(array $record): string
    {
        return json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
