<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\FakeCoachService;
use PHPUnit\Framework\TestCase;

class FakeCoachServiceTest extends TestCase
{
    public function test_it_returns_a_default_reply_when_nothing_is_queued()
    {
        $fake = new FakeCoachService;

        $response = $fake->chat(CoachRequest::prompt('hello'));

        $this->assertInstanceOf(CoachResponse::class, $response);
        $this->assertSame('This is a fake coach response.', $response->content);
        $this->assertSame('fake', $response->model);
    }

    public function test_queued_responses_are_returned_in_fifo_order()
    {
        $fake = (new FakeCoachService)->push('first')->push('second');

        $this->assertSame('first', $fake->chat(CoachRequest::prompt('a'))->content);
        $this->assertSame('second', $fake->chat(CoachRequest::prompt('b'))->content);
        // Queue drained — falls back to the default reply.
        $this->assertSame('This is a fake coach response.', $fake->chat(CoachRequest::prompt('c'))->content);
    }

    public function test_push_json_encodes_the_value()
    {
        $fake = (new FakeCoachService)->pushJson(['type' => 'build']);

        $decoded = $fake->chat(CoachRequest::prompt('author a loop'))->json();

        $this->assertSame(['type' => 'build'], $decoded);
    }

    public function test_it_records_every_request_and_exposes_the_last_one()
    {
        $fake = new FakeCoachService;

        $this->assertNull($fake->lastRequest());

        $fake->chat(CoachRequest::prompt('one'));
        $fake->chat(CoachRequest::prompt('two'));

        $this->assertCount(2, $fake->requests);
        $this->assertSame('two', $fake->lastRequest()->messages[0]->content);
    }
}
