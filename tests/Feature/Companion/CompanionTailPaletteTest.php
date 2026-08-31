<?php

namespace Tests\Feature\Companion;

use Tests\TestCase;

/**
 * The tail recolours items by handing the renderer a colour NAME, and the
 * renderer decides what that name draws as. If a name here is not a key in
 * blob-renderer.tsx's `PALETTE`, `Worn()` silently falls back to the item's
 * own default colour — the rung's message announces a recolour and the
 * drawing quietly undoes it. And if a name here happens to equal an item's
 * own default colour, the rung announces a change that was never visible in
 * the first place.
 *
 * Reads the TS source as text rather than executing it — the same approach
 * CompanionVocabularyTest uses — so this stays a plain PHP test with no JS
 * runtime involved.
 */
class CompanionTailPaletteTest extends TestCase
{
    private function rendererSource(): string
    {
        $path = dirname(__DIR__, 3).'/resources/js/patyourself/blob-renderer.tsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every colour the renderer knows how to draw.
     *
     * @return array<string, string> colour name => hex value
     */
    private function palette(): array
    {
        $block = $this->between(
            $this->rendererSource(),
            'const PALETTE: Record<string, string> = {',
            '};',
        );

        preg_match_all('/(\w+):\s*\'#([0-9A-Fa-f]{3,8})\'/', $block, $matches, PREG_SET_ORDER);

        $palette = [];
        foreach ($matches as $match) {
            $palette[$match[1]] = '#'.$match[2];
        }

        return $palette;
    }

    /**
     * Which PALETTE key each item type falls back to when the ladder names no
     * variant — read from the ITEMS dictionary's own `colour: PALETTE.xxx`
     * lines, so this cannot drift from the renderer it is guarding. A type
     * whose default is not a PALETTE reference (glasses defaults to the ink
     * colour, not an accessory colour) is simply absent from the result.
     *
     * @return array<string, string> item type => palette key
     */
    private function itemDefaultVariants(): array
    {
        $itemsBlock = $this->between(
            $this->rendererSource(),
            'const ITEMS: Record<string, ItemSpec> = {',
            "\n};\n\ninterface AbilitySpec",
        );

        $defaults = [];

        foreach (config('companion.item_types', []) as $type) {
            $pattern = '/\b'.preg_quote($type, '/').':\s*\{[\s\S]*?colour:\s*PALETTE\.(\w+)/';

            if (preg_match($pattern, $itemsBlock, $match) === 1) {
                $defaults[$type] = $match[1];
            }
        }

        return $defaults;
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $startPos = strpos($haystack, $start);
        $this->assertNotFalse($startPos, "could not find \"{$start}\" in blob-renderer.tsx");
        $startPos += strlen($start);

        $endPos = strpos($haystack, $end, $startPos);
        $this->assertNotFalse($endPos, "could not find \"{$end}\" after \"{$start}\" in blob-renderer.tsx");

        return substr($haystack, $startPos, $endPos - $startPos);
    }

    public function test_every_tail_variant_is_a_colour_the_renderer_knows(): void
    {
        $palette = $this->palette();
        $variants = config('companion.tail.variants');

        $this->assertNotEmpty($palette, 'could not read PALETTE out of blob-renderer.tsx');
        $this->assertNotEmpty($variants);

        foreach ($variants as $variant) {
            $this->assertArrayHasKey(
                $variant,
                $palette,
                "tail variant \"{$variant}\" is not a key in blob-renderer.tsx's PALETTE — Worn() would silently fall back to the item's own default colour instead of applying this recolour",
            );
        }
    }

    public function test_no_tail_variant_matches_an_items_own_default_colour(): void
    {
        $defaults = $this->itemDefaultVariants();
        $variants = config('companion.tail.variants');

        $this->assertNotEmpty($defaults, 'could not read any item default colour out of blob-renderer.tsx');

        foreach ($variants as $variant) {
            foreach ($defaults as $type => $defaultVariant) {
                $this->assertNotSame(
                    $defaultVariant,
                    $variant,
                    "tail variant \"{$variant}\" equals {$type}'s own default colour, so that rung would announce a recolour that was already the case",
                );
            }
        }
    }
}
