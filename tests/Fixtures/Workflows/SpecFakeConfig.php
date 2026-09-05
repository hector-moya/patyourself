<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuration a fake workflow attaches to an Action: what an occasion is
 * meant to contain. The gym module's exercise template will sit exactly here.
 *
 * Exists only in the test suite. It is how the architecture is exercised while
 * no real module is built — if attaching one of these is awkward, the extension
 * site is wrong, and finding that out before gym is the reason this was written
 * first.
 */
class SpecFakeConfig extends Model
{
    protected $table = 'spec_fake_configs';

    protected $guarded = [];
}
