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
            // Which recording surface this loop uses, or null for the plain
            // screen. Always present, because the client registry has to be
            // able to route on it rather than infer from an absent key.
            'workflow' => $this->workflow,
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
                // The experiment's state, so a list of loops can answer "what am
                // I running" without opening each one. `planned_days` is null for
                // an open-ended experiment, which is a legitimate state and must
                // never be rendered as a countdown.
                'day_of_experiment' => $this->activeStrategy->dayOfExperiment(),
                'planned_days' => $this->activeStrategy->plannedDays(),
                'is_under_review' => $this->activeStrategy->isUnderReview(),
            ]),
            // The loggable action a card posts an outcome against (null only when
            // every action on the loop is archived). Only embedded when eager-loaded.
            'active_action' => $this->whenLoaded('activeAction', fn () => $this->activeAction === null ? null : [
                'id' => $this->activeAction->id,
                'title' => $this->activeAction->title,
                'description' => $this->activeAction->description,
                'next_occurrence_at' => $this->activeAction->nextOccurrenceAt(),
                'recurrence' => $this->activeAction->recurrence,
                'schedule_kind' => $this->activeAction->metadata['schedule_kind'] ?? null,
                'anchor' => $this->activeAction->metadata['anchor'] ?? null,
            ]),
        ];
    }
}
