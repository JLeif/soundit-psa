<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * design/mockups/_tokens.css is a hand copy of the DESIGN.md front-matter
 * tokens (colors, typography, rounded, spacing). This pins the copy to its
 * source so a token changed in one place and not the other fails CI.
 *
 * Expected custom properties are derived from the front-matter itself, never
 * from a list written here: a token added to DESIGN.md without a CSS twin
 * fails, as does a CSS property under one of the four groups with no source.
 */
class DesignMockupTokensTest extends TestCase
{
    private const GROUPS = ['colors', 'typography', 'rounded', 'spacing'];

    private static function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    public static function frontMatter(string $markdown): array
    {
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', $markdown, $m)) {
            throw new \RuntimeException('DESIGN.md has no YAML front-matter block');
        }
        $parsed = Yaml::parse($m[1]);
        if (! is_array($parsed)) {
            throw new \RuntimeException('DESIGN.md front-matter did not parse to a mapping');
        }

        return $parsed;
    }

    /**
     * Expected --psa-* properties and their values, from the front-matter.
     *
     * @param  array<string, mixed>  $fm
     * @return array<string, string>
     */
    public static function expectedProperties(array $fm): array
    {
        $out = [];
        foreach (self::GROUPS as $group) {
            if (! isset($fm[$group]) || ! is_array($fm[$group]) || $fm[$group] === []) {
                throw new \RuntimeException("DESIGN.md front-matter has no '{$group}' group");
            }
            foreach ($fm[$group] as $name => $value) {
                if (is_array($value)) {
                    foreach ($value as $prop => $leaf) {
                        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', (string) $prop));
                        $out["--psa-{$group}-{$name}-{$kebab}"] = self::scalar($leaf);
                    }
                } else {
                    $out["--psa-{$group}-{$name}"] = self::scalar($value);
                }
            }
        }

        return $out;
    }

    private static function scalar(mixed $v): string
    {
        if (is_float($v)) {
            // 1.2 stays 1.2; never 1.2000000000000002 or 1.0 -> 1
            return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        }

        return (string) $v;
    }

    /**
     * Custom properties declared in the CSS, with comments stripped.
     *
     * @return array<string, string>
     */
    public static function cssProperties(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/(--psa-[a-z0-9-]+)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $prop, $value]) {
            if (array_key_exists($prop, $out)) {
                throw new \RuntimeException("{$prop} is declared twice in _tokens.css");
            }
            $out[$prop] = trim(preg_replace('/\s+/', ' ', $value));
        }

        return $out;
    }

    public function test_every_front_matter_token_is_in_tokens_css_with_the_same_value(): void
    {
        $expected = self::expectedProperties(self::frontMatter((string) file_get_contents(self::repo().'/DESIGN.md')));
        $actual = self::cssProperties((string) file_get_contents(self::repo().'/design/mockups/_tokens.css'));

        // Positive control on the instrument: the source has the families we
        // know it has, so a parser that silently returned nothing cannot pass.
        $this->assertSame('#1a365d', $expected['--psa-colors-navy'] ?? null);
        $this->assertSame('#fed136', $expected['--psa-colors-signal-gold'] ?? null);
        $this->assertSame('800', $expected['--psa-typography-display-font-weight'] ?? null);
        $this->assertGreaterThanOrEqual(40, count($expected));

        foreach ($expected as $prop => $value) {
            $this->assertArrayHasKey($prop, $actual, "{$prop} (from DESIGN.md front-matter) is missing from design/mockups/_tokens.css");
            $this->assertSame($value, $actual[$prop], "{$prop} differs: DESIGN.md says '{$value}', _tokens.css says '{$actual[$prop]}'");
        }

        $orphans = array_diff(array_keys($actual), array_keys($expected));
        $this->assertSame([], array_values($orphans), '_tokens.css declares --psa-* properties with no DESIGN.md front-matter source');
    }

    public function test_tokens_css_makes_no_external_reference(): void
    {
        $css = (string) file_get_contents(self::repo().'/design/mockups/_tokens.css');
        $code = preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertDoesNotMatchRegularExpression('/@import|@font-face|url\s*\(|https?:/i', $code);
    }
}
