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
            'review_at' => $this->review_at,
            'verdict' => $this->verdict,
            'verdict_note' => $this->verdict_note,
            'day_of_experiment' => $this->resource->dayOfExperiment(),
            'planned_days' => $this->resource->plannedDays(),
            'is_under_review' => $this->resource->isUnderReview(),
            'parent_strategy_id' => $this->parent_strategy_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
