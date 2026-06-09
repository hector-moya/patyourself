<?php

namespace App\Http\Resources;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical JSON shape of a logged outcome, shared by the API controller
 * and (resolved) Inertia props.
 *
 * @mixin ActionLog
 */
class ActionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action_id' => $this->action_id,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'logged_at' => $this->logged_at,
            'created_at' => $this->created_at,
        ];
    }
}
