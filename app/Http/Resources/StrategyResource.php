<?php

namespace App\Http\Resources;

use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version in a loop's strategy timeline. Carries the provenance that makes
 * the history readable — why it changed, where in the behavioural chain it
 * intervenes, which version it superseded — and the experiment framing: its
 * verdict, planned length, and how far into its run it is. Shared by the API
 * history endpoint and (resolved) the loop detail screen's props.
 *
 * @mixin Strategy
 */
class StrategyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'intervention_point' => $this->intervention_point,
            'approach' => $this->approach,
            'rationale' => $this->rationale,
            'change_reason' => $this->change_reason,
            'superseded_reason' => $this->superseded_reason,
            'review_at' => $this->review_at?->toIso8601String(),
            'verdict' => $this->verdict,
            'verdict_note' => $this->verdict_note,
            'day_of_experiment' => $this->resource->dayOfExperiment(),
            'planned_days' => $this->resource->plannedDays(),
            'is_under_review' => $this->resource->isUnderReview(),
            // Present only when the caller counted them. It is what separates a
            // version that failed from one that was never tested, so omitting
            // it is honester than defaulting it to zero.
            'outcomes_recorded' => $this->whenCounted('actionLogs', fn (): int => (int) $this->action_logs_count),
            'parent_strategy_id' => $this->parent_strategy_id,
            'metadata' => $this->metadata,
            // ISO-8601 with an offset, matching LoopProgress. The lab record
            // consumes both read models on one screen, and passing a Carbon
            // through untouched serialises it as Laravel's default
            // `...T09:30:00.000000Z` — the same instant in a second encoding.
            // Normalised here deliberately rather than left to whichever read
            // model the caller happened to reach for.
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
