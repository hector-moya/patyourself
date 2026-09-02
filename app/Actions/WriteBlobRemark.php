<?php

namespace App\Actions;

use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;

/**
 * Records one of Blob's remarks. The only place a remark is written.
 *
 * The body is stored verbatim, for the same reason a note's is: it is somebody
 * else's words, and tidying them is an edit nobody asked for. Append-only —
 * there is no counterpart to this class for updating or removing one.
 */
final readonly class WriteBlobRemark
{
    public function handle(User $user, string $body, ?Intention $loop = null): CompanionRemark
    {
        return $user->companionRemarks()->create([
            'intention_id' => $loop?->id,
            'body' => $body,
        ]);
    }
}
