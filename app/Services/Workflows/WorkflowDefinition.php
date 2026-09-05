<?php

namespace App\Services\Workflows;

/**
 * One workflow's registry entry: its name, how it is written for a person, and
 * what it attaches at each of the two extension sites.
 *
 * `config` is a model keyed to `actions` — what an occasion is meant to
 * contain. `record` is a model keyed to `occurrences` — what it actually
 * contained. Either may be null, which means the workflow attaches nothing
 * there rather than that something is missing.
 */
final readonly class WorkflowDefinition
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $config = null,
        public ?string $record = null,
    ) {}
}
