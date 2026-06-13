<?php

namespace App\Http\Resources;

use App\Models\Intention;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical JSON shape of a loop, shared by the API controller and the
 * Inertia web props so both surfaces render the same structure. The active
 * strategy is embedded when it has been eager-loaded.
 *
 * @mixin Intention
 */
class IntentionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'cue' => $this->cue,
            'craving' => $this->craving,
            'response' => $this->response,
            'reward' => $this->reward,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'strategy' => $this->whenLoaded('activeStrategy', fn () => $this->activeStrategy === null ? null : [
                'intervention_point' => $this->activeStrategy->intervention_point,
                'approach' => $this->activeStrategy->approach,
                'rationale' => $this->activeStrategy->rationale,
                'version' => $this->activeStrategy->version,
            ]),
            // The loggable action a card posts an outcome against (null when the
            // loop has no open action). Only embedded when eager-loaded.
            'active_action' => $this->whenLoaded('activeAction', fn () => $this->activeAction === null ? null : [
                'id' => $this->activeAction->id,
                'title' => $this->activeAction->title,
                'description' => $this->activeAction->description,
                'status' => $this->activeAction->status,
                'scheduled_for' => $this->activeAction->scheduled_for,
                'recurrence' => $this->activeAction->recurrence,
                'schedule_kind' => $this->activeAction->metadata['schedule_kind'] ?? null,
                'anchor' => $this->activeAction->metadata['anchor'] ?? null,
            ]),
        ];
    }
}
