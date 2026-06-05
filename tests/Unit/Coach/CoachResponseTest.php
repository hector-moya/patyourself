<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use PHPUnit\Framework\TestCase;

class CoachResponseTest extends TestCase
{
    public function test_total_tokens_sums_prompt_and_completion()
    {
        $response = new CoachResponse(content: 'hi', model: 'fake', promptTokens: 12, completionTokens: 30);

        $this->assertSame(42, $response->totalTokens());
    }

    public function test_json_decodes_a_plain_object()
    {
        $response = new CoachResponse(content: '{"title":"Read","type":"build"}', model: 'fake');

        $this->assertSame(['title' => 'Read', 'type' => 'build'], $response->json());
    }

    public function test_json_strips_markdown_code_fences()
    {
        $response = new CoachResponse(content: "```json\n{\"ok\":true}\n```", model: 'fake');

        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_json_extracts_a_block_embedded_in_prose()
    {
        $response = new CoachResponse(
            content: 'Sure, here is your loop: {"cue":"alarm","reward":"energy"} — hope it helps!',
            model: 'fake',
        );

        $this->assertSame(['cue' => 'alarm', 'reward' => 'energy'], $response->json());
    }

    public function test_json_decodes_a_top_level_array()
    {
        $response = new CoachResponse(content: '[1, 2, 3]', model: 'fake');

        $this->assertSame([1, 2, 3], $response->json());
    }

    public function test_json_throws_when_no_json_is_present()
    {
        $this->expectException(CoachException::class);

        (new CoachResponse(content: 'just some prose, no payload', model: 'fake'))->json();
    }
}
