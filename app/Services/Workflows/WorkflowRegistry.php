<?php

namespace App\Services\Workflows;

/**
 * Which workflows exist, and what each one attaches.
 *
 * Naming a workflow the registry does not know must never be able to break a
 * screen — the same rule scenes, room objects and animations already follow —
 * so an unknown name and a null one both resolve to "no workflow", which is the
 * plain loop this app has always had.
 *
 * The whole array is read and matched with `array_key_exists` rather than asked
 * for by dot path. `config('workflows.registry.'.$name)` walks into an entry
 * whenever the name contains a dot: a loop naming `gym.label` would be handed
 * back a string, which is truthy and so never triggers a fallback, and the
 * caller would then read ->record off it. That is the same shape as the
 * prototype-chain trap `scenes.ts` records, arriving by a different route.
 *
 * Config is read on every call rather than cached on the instance, so a
 * workflow registered after this service was resolved is still found.
 */
final class WorkflowRegistry
{
    /**
     * Every workflow, keyed by the value stored in `intentions.workflow`.
     *
     * @return array<string, WorkflowDefinition>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $entries */
        $entries = config('workflows.registry', []);

        $definitions = [];

        foreach ($entries as $name => $entry) {
            $definitions[(string) $name] = $this->definition((string) $name, $entry);
        }

        return $definitions;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        /** @var array<string, mixed> $entries */
        $entries = config('workflows.registry', []);

        return array_key_exists($name, $entries);
    }

    /**
     * The workflow a loop names, or null for "no workflow" — which is what both
     * a null name and an unrecognised one mean.
     */
    public function for(?string $name): ?WorkflowDefinition
    {
        if (! $this->has($name)) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $entries */
        $entries = config('workflows.registry', []);

        return $this->definition((string) $name, $entries[$name]);
    }

    /** @param  array<string, mixed>  $entry */
    private function definition(string $name, array $entry): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: $name,
            label: (string) ($entry['label'] ?? $name),
            config: isset($entry['config']) ? (string) $entry['config'] : null,
            record: isset($entry['record']) ? (string) $entry['record'] : null,
        );
    }
}
