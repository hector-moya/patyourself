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
            $root.'/config/workflows.php',
            $root.'/app/Services/Companion/CompanionResolver.php',
            $root.'/app/Services/Companion/CompanionState.php',
            $root.'/app/Services/Companion/CompanionAnnouncement.php',
            $root.'/app/Services/Companion/CompanionRemarks.php',
            $root.'/app/Services/Workflows/WorkflowRegistry.php',
            $root.'/app/Services/Workflows/WorkflowDefinition.php',
            $root.'/app/Services/Workflows/MaterialisesOccasion.php',
            $root.'/app/Models/CompanionRemark.php',
            $root.'/app/Models/Exercise.php',
            $root.'/app/Models/ActionExercise.php',
            $root.'/app/Models/PerformedSet.php',
            // database/data/exercises.json is excluded on purpose: it is a
            // committed, imported export of 876 third-party exercises and
            // will contain arbitrary prose no vocabulary rule can constrain.
            $root.'/database/seeders/ExerciseCatalogueSeeder.php',
            $root.'/app/Actions/WriteBlobRemark.php',
            $root.'/app/Mcp/Tools/WriteBlobRemarkTool.php',
            $root.'/app/Http/Controllers/CompanionController.php',
            $root.'/resources/js/hooks/use-sprite-clock.ts',
            $root.'/resources/js/patyourself/companion-animations.ts',
            $root.'/resources/js/patyourself/sprite-layout.ts',
            $root.'/resources/js/patyourself/sprite-items.tsx',
            $root.'/resources/js/patyourself/sprites/README.md',
            $root.'/resources/js/patyourself/companion.tsx',
            $root.'/resources/js/patyourself/blob-renderer.tsx',
            $root.'/resources/js/patyourself/companion-room.tsx',
            $root.'/resources/js/patyourself/scenes.ts',
            $root.'/resources/js/patyourself/scenes/README.md',
            $root.'/resources/js/patyourself/ui/README.md',
            $root.'/resources/js/patyourself/workflows.ts',
            $root.'/resources/js/patyourself/workflow-record.tsx',
            $root.'/resources/js/patyourself/workflow-config.tsx',
            // Not a training file: it holds the outcome labels and the reason
            // copy for every screen in the app (dashboard, catch-up, the gym
            // session), which is exactly the user-facing copy this list
            // exists to scan. It replaced three separate copies of that
            // form, each of which this list would otherwise have had to
            // list on its own.
            $root.'/resources/js/patyourself/verdict-form.tsx',
            $root.'/resources/js/pages/companion.tsx',
            $root.'/app/Actions/Training/AddRoutineExercise.php',
            $root.'/app/Actions/Training/RecordSet.php',
            $root.'/app/Actions/Training/RemoveRoutineExercise.php',
            $root.'/app/Actions/Training/ReorderRoutine.php',
            $root.'/app/Http/Controllers/Training/ExerciseCatalogueController.php',
            $root.'/app/Http/Controllers/Training/ExerciseController.php',
            $root.'/app/Http/Controllers/Training/PerformedSetController.php',
            $root.'/app/Http/Controllers/Training/ProgressionController.php',
            $root.'/app/Http/Controllers/Training/RoutineController.php',
            $root.'/app/Http/Controllers/Training/SessionController.php',
            $root.'/app/Http/Requests/Training/ReorderRoutineRequest.php',
            $root.'/app/Http/Requests/Training/StorePerformedSetRequest.php',
            $root.'/app/Http/Requests/Training/StoreRoutineExerciseRequest.php',
            $root.'/app/Services/Training/ExerciseHistory.php',
            $root.'/app/Services/Training/LastPerformance.php',
            $root.'/app/Services/Training/RoutineOrderException.php',
            $root.'/app/Services/Training/SessionScreen.php',
            $root.'/resources/js/pages/training/exercise.tsx',
            $root.'/resources/js/pages/training/progression.tsx',
            $root.'/resources/js/pages/training/session.tsx',
            $root.'/resources/js/patyourself/training/gym-record.tsx',
            $root.'/resources/js/patyourself/training/set-grid.tsx',
            // The two setup surfaces. The routine editor especially: it is
            // where a person writes the targets every other gym screen quotes
            // back at them, so it is the most tempting place in the module to
            // start scoring what they wrote against what they did.
            $root.'/resources/js/patyourself/training/routine-editor.tsx',
            $root.'/resources/js/pages/loops/show.tsx',
            $root.'/resources/js/patyourself/loops/action-layer.tsx',
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
            // Blob never needs the user. It has no hunger, no loneliness and
            // nothing that decays while nobody is looking, so none of these
            // words has anywhere legitimate to appear.
            ['lonely'],
            ['hungry'],
            ['misses you'],
            ['neglect'],
            ['cooldown'],
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
