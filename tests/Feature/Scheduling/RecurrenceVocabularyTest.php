<?php

namespace Tests\Feature\Scheduling;

use App\Http\Requests\Api\RescheduleActionRequest as ApiRescheduleActionRequest;
use App\Http\Requests\RescheduleActionRequest;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\StoreExperimentRequest;
use App\Services\Authoring\AuthoredAction;
use App\Services\Scheduling\Recurrence;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The check that would have caught the drift this consolidation removed.
 *
 * The recurrence vocabulary used to be written out by hand at every validation
 * surface, so adding a cadence meant editing each one and nothing noticed a
 * miss: the web form would offer a token the JSON API refused, with the suite
 * green. These assertions fail the day a surface stops agreeing with the enum,
 * including for the cadence nobody has thought of yet.
 */
class RecurrenceVocabularyTest extends TestCase
{
    /**
     * The field each request validates a recurrence under. StoreExperimentRequest
     * prefixes its action fields because it posts them alongside a strategy's.
     *
     * @return array<class-string, string>
     */
    private function surfaces(): array
    {
        return [
            RescheduleActionRequest::class => 'recurrence',
            StoreActionRequest::class => 'recurrence',
            ApiRescheduleActionRequest::class => 'recurrence',
            StoreExperimentRequest::class => 'action_recurrence',
        ];
    }

    public function test_every_surface_accepts_every_token_in_the_vocabulary(): void
    {
        foreach ($this->surfaces() as $request => $field) {
            foreach (Recurrence::tokens() as $token) {
                // Validator::make() only runs the rules array handed to it, so
                // RescheduleActionRequest::withValidator()'s "pass at least one
                // field to change" check (registered separately, via the
                // FormRequest lifecycle) never fires here. That is what lets a
                // bare recurrence be validated in isolation.
                $validator = Validator::make(
                    [$field => $token],
                    (new $request)->rules(),
                );

                $this->assertFalse(
                    $validator->errors()->has($field),
                    "{$request} refuses the token '{$token}'.",
                );
            }
        }
    }

    public function test_every_surface_still_refuses_a_token_outside_the_vocabulary(): void
    {
        foreach ($this->surfaces() as $request => $field) {
            $validator = Validator::make(
                [$field => 'fortnight'],
                (new $request)->rules(),
            );

            $this->assertTrue(
                $validator->errors()->has($field),
                "{$request} accepts 'fortnight', which is not a recurrence.",
            );
        }
    }

    public function test_the_authoring_layer_accepts_the_two_new_cadences(): void
    {
        foreach (['fortnightly', 'monthly'] as $token) {
            $authored = AuthoredAction::fromStructured([
                'title' => 'Deep clean',
                'schedule' => ['kind' => 'clock', 'time' => '09:00', 'recurrence' => $token],
            ]);

            $this->assertSame($token, $authored->recurrence);
        }
    }
}
