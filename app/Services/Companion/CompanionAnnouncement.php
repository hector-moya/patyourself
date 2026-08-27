<?php

namespace App\Services\Companion;

use App\Models\User;

/**
 * Decides whether a write is worth telling the user about, and in what words.
 *
 * The rule is the whole point: a companion line goes out ONLY when the write
 * moved Blob up a stage. A line after every logged breakfast is wallpaper
 * within a week, and wallpaper is worse than silence — it teaches the user to
 * skip whatever the app says.
 *
 * The words are the app's, written in config and relayed verbatim. The coach
 * never composes the praise: keeping the voice on this side is what stops Blob
 * turning into a model improvising encouragement at someone.
 */
final readonly class CompanionAnnouncement
{
    public function __construct(private CompanionResolver $resolver) {}

    /**
     * Where Blob stood before the write. Call this first; the caller holds the
     * number across its own write and hands it back to {@see since()}.
     */
    public function stageFor(User $user): int
    {
        return $this->resolver->forUser($user)->stageIndex();
    }

    /**
     * The payload to merge into a tool response — or an empty array, which
     * merges to nothing, when the write left Blob where it was.
     *
     * @return array{companion?: array{unlocked: string, kind: string, message: string, url: string}}
     */
    public function since(User $user, int $stageBefore): array
    {
        $state = $this->resolver->forUser($user);

        if ($state->stageIndex() <= $stageBefore) {
            return [];
        }

        $unlock = $state->latestUnlock();

        return ['companion' => [
            'unlocked' => $unlock['name'],
            'kind' => $unlock['kind'],
            'message' => $unlock['message'],
            'url' => route('companion'),
        ]];
    }
}
