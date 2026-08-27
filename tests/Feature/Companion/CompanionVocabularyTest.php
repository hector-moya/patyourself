<?php

namespace Tests\Feature\Companion;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blob is not a score, and this is the guard that keeps it that way.
 *
 * Scanning the source rather than the rendered output on purpose: the words
 * that would undo this feature are the ones a well-meaning later edit adds to a
 * label, a tooltip or a comment, long before anyone thinks to test for them.
 *
 * The feature's own tests are excluded — they have to name the banned words in
 * order to assert their absence.
 */
class CompanionVocabularyTest extends TestCase
{
    /**
     * Every file the feature owns.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            $root.'/config/companion.php',
            $root.'/app/Services/Companion/CompanionResolver.php',
            $root.'/app/Services/Companion/CompanionState.php',
            $root.'/app/Services/Companion/CompanionAnnouncement.php',
            $root.'/app/Http/Controllers/CompanionController.php',
            $root.'/resources/js/patyourself/companion.tsx',
            $root.'/resources/js/pages/companion.tsx',
        ];
    }

    /**
     * `%` is excluded here rather than listed: a percentage is a completion
     * score, and the ladder copy is already checked for one in
     * CompanionLadderTest. Source files legitimately use `%` in other ways.
     *
     * @return list<array{string}>
     */
    public static function bannedVocabularyProvider(): array
    {
        return [
            ['streak'],
            ['congratulation'],
            ['well done'],
            ['completion rate'],
            ['percent'],
            ['points'],
            ['level up'],
        ];
    }

    #[DataProvider('bannedVocabularyProvider')]
    public function test_the_feature_never_keeps_score(string $banned): void
    {
        foreach ($this->sourceFiles() as $path) {
            $this->assertFileExists($path);
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                (string) file_get_contents($path),
                basename($path).' says "'.$banned.'"',
            );
        }
    }
}
