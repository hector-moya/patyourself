<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Eloquent\Model;

/**
 * The record a fake workflow attaches to an Occurrence: what the occasion
 * actually contained. The gym module's performed sets will sit exactly here.
 *
 * Keyed to the occurrence rather than to the log on purpose. A record is
 * written *during* an occasion, long before anyone presses a verdict, so there
 * is no log to hang it on yet — and creating one early to hold it would pay the
 * user for starting rather than for recording.
 *
 * Exists only in the test suite.
 */
class SpecFakeRecord extends Model
{
    protected $table = 'spec_fake_records';

    protected $guarded = [];
}
