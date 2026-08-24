<?php

namespace App\Mcp\Tools;

use App\Actions\AuthorIntention;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-loop')]
#[Description('Create a new habit loop from a cue -> craving -> response -> reward chain the user has agreed to. The loop is created PAUSED so the user reviews and activates it in the app. Ask the user about their real cue, craving, response and reward before calling this — do not invent them.')]
class CreateLoopTool extends Tool
{
    /** Provenance stamped onto the loop's metadata, distinguishing MCP-authored loops from IntentionAuthor ones. */
    public const AUTHORED_BY = 'mcp-client';

    public const PROMPT_VERSION = 'mcp@1';

    private const KINDS = ['clock', 'anchored'];

    private const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];

    /**
     * The client authors the structure; this only validates and persists, through
     * the same AuthorIntention writer the in-app coach uses. Passing an authored
     * DTO means AuthorIntention never reaches its LLM branch, so this tool makes
     * no model call and costs nothing against the coach budget.
     */
    public function handle(Request $request, AuthorIntention $author): Response
    {
        $kind = data_get($request->get('action'), 'kind');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'string', Rule::in([Intention::TYPE_BUILD, Intention::TYPE_BREAK])],
            'cue' => ['required', 'string', 'max:1000'],
            'craving' => ['required', 'string', 'max:1000'],
            'response' => ['required', 'string', 'max:1000'],
            'reward' => ['required', 'string', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'strategy' => ['required', 'array'],
            'strategy.intervention_point' => ['required', 'string', Rule::in([
                Strategy::POINT_CUE,
                Strategy::POINT_CRAVING,
                Strategy::POINT_RESPONSE,
                Strategy::POINT_REWARD,
            ])],
            'strategy.approach' => ['required', 'string', 'max:1000'],
            'strategy.rationale' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'array'],
            'action.title' => ['required_with:action', 'string', 'max:255'],
            'action.description' => ['nullable', 'string', 'max:1000'],
            'action.kind' => ['required_with:action', 'string', Rule::in(self::KINDS)],
            'action.time' => [Rule::requiredIf(fn (): bool => $kind === 'clock'), 'nullable', 'date_format:H:i'],
            'action.recurrence' => [Rule::requiredIf(fn (): bool => $kind === 'clock'), 'nullable', 'string', Rule::in(self::RECURRENCES)],
            'action.anchor' => [Rule::requiredIf(fn (): bool => $kind === 'anchored'), 'nullable', 'string', 'max:255'],
        ]);

        // Validation above covers the same ground, but fromStructured is the DTO's
        // own guard and throws rather than returning errors. Convert it to a tool
        // error so a structural mismatch reads as "fix your arguments", not a 500.
        try {
            $authored = AuthoredIntention::fromStructured(
                $this->toAuthoredPayload($validated),
                self::AUTHORED_BY,
                self::PROMPT_VERSION,
            );
        } catch (CoachException) {
            return Response::error('That loop is missing required structure. Provide title, type, cue, craving, response, reward and a strategy.');
        }

        $intention = $author->handle(
            $request->user(),
            $validated['title'],
            [],
            $authored,
            Intention::STATUS_PAUSED,
        );

        return Response::json([
            'loop_id' => $intention->id,
            'title' => $intention->title,
            'status' => $intention->status,
            'next_step' => 'Created paused. Tell the user to open PatYourSelf and activate it.',
        ]);
    }

    /**
     * AuthoredIntention::fromStructured (via AuthoredAction::fromStructured) reads
     * an action's schedule fields nested under `schedule` — the shape the
     * authoring agents' own JSON schema uses (see Strategist/IntentionAuthor).
     * This tool's public arguments keep kind/time/recurrence/anchor flat for a
     * simpler MCP contract, so bridge the two shapes here.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toAuthoredPayload(array $validated): array
    {
        if (! isset($validated['action'])) {
            return $validated;
        }

        $validated['action'] = [
            'title' => $validated['action']['title'],
            'description' => $validated['action']['description'] ?? null,
            'schedule' => [
                'kind' => $validated['action']['kind'],
                'time' => $validated['action']['time'] ?? null,
                'recurrence' => $validated['action']['recurrence'] ?? null,
                'anchor' => $validated['action']['anchor'] ?? null,
            ],
        ];

        return $validated;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Short name for the loop, in the user\'s own words.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional longer framing of the habit.'),
            'type' => $schema->string()
                ->enum([Intention::TYPE_BUILD, Intention::TYPE_BREAK])
                ->description('build to form a habit, break to stop one.')
                ->required(),
            'cue' => $schema->string()
                ->description('What triggers the behaviour. The user\'s real cue, not an invented one.')
                ->required(),
            'craving' => $schema->string()
                ->description('The underlying want the cue provokes.')
                ->required(),
            'response' => $schema->string()
                ->description('The behaviour itself.')
                ->required(),
            'reward' => $schema->string()
                ->description('What the user gets from it.')
                ->required(),
            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional free-form tags.'),
            'strategy' => $schema->object([
                'intervention_point' => $schema->string()
                    ->enum([
                        Strategy::POINT_CUE,
                        Strategy::POINT_CRAVING,
                        Strategy::POINT_RESPONSE,
                        Strategy::POINT_REWARD,
                    ])
                    ->description('Which point of the chain this first strategy acts on.')
                    ->required(),
                'approach' => $schema->string()
                    ->description('The concrete intervention.')
                    ->required(),
                'rationale' => $schema->string()
                    ->description('Why this point and this approach.'),
            ])->description('The first strategy version.')->required(),
            'action' => $schema->object([
                'title' => $schema->string()->description('The concrete to-do.')->required(),
                'description' => $schema->string(),
                'kind' => $schema->string()
                    ->enum(self::KINDS)
                    ->description('clock for a scheduled time, anchored to hang off another routine.')
                    ->required(),
                'time' => $schema->string()->description('HH:MM local time. Required when kind is clock.'),
                'recurrence' => $schema->string()
                    ->enum(self::RECURRENCES)
                    ->description('Required when kind is clock.'),
                'anchor' => $schema->string()->description('The routine to hang off. Required when kind is anchored.'),
            ])->description('Optional first action. Omit it and the user schedules one in the app.'),
        ];
    }
}
