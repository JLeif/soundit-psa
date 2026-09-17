<?php

namespace Tests\Unit;

use App\Helpers\MarkdownRenderer;
use Tests\TestCase;

/**
 * MarkdownRenderer — the one shared markdown-to-HTML seam.
 *
 * Charlie edited an email-originated ticket description on 2026-09-17 and the
 * line breaks he typed vanished on save: strict CommonMark treats a single
 * newline inside a paragraph as a space ("soft break"), so text that arrives
 * one-line-per-line (the Html2Text prefill, anything pasted from a chat or a
 * mail client) renders as run-on paragraphs. Jeeves ruled (card 6aab4346,
 * 2026-09-17 10:57 PT) that a typed line break is a rendered line break, the
 * way GitHub comments, Slack and Teams behave. The renderer is shared, so every
 * markdown surface in the app inherits this by design.
 *
 * Extends the Laravel TestCase (not the bare PHPUnit one) because the sanitizer
 * behind the renderer resolves storage_path() for its cache.
 */
class MarkdownRendererTest extends TestCase
{
    public function test_a_single_newline_renders_as_a_line_break_inside_the_paragraph(): void
    {
        $html = MarkdownRenderer::render("Line A\nLine B");

        $this->assertSame("<p>Line A<br />\nLine B</p>", trim((string) $html));
    }

    public function test_a_blank_line_still_starts_a_new_paragraph(): void
    {
        $html = MarkdownRenderer::render("Line A\n\nLine B");

        $this->assertSame("<p>Line A</p>\n<p>Line B</p>", trim((string) $html));
        $this->assertStringNotContainsString('<br', (string) $html);
    }

    public function test_the_sanitizer_still_strips_scripts(): void
    {
        // Inline raw HTML rides through the markdown converter untouched; the
        // sanitizer behind the renderer is what strips it. Keep the hostile
        // markup inline (a tag on its own line is a CommonMark HTML block and
        // takes a different path) so the soft break is exercised alongside it.
        $html = MarkdownRenderer::render(
            "Safe line <script>alert(1)</script>\n"
            ."<img src=\"x\" onerror=\"alert(2)\"> <a href=\"javascript:alert(3)\">click</a>\n"
            .'Another line'
        );

        $this->assertStringNotContainsString('<script', (string) $html);
        $this->assertStringNotContainsString('onerror', (string) $html);
        $this->assertStringNotContainsString('javascript:', (string) $html);
        // The surrounding text and its line breaks survive the scrub.
        $this->assertStringContainsString('Safe line', (string) $html);
        $this->assertStringContainsString('Another line', (string) $html);
        $this->assertSame(2, substr_count((string) $html, '<br />'));
    }

    public function test_a_fenced_code_block_is_unaffected(): void
    {
        $html = MarkdownRenderer::render("```\nfirst\nsecond\n```");

        // Code blocks keep literal newlines; no <br /> may be injected into them.
        $this->assertSame("<pre><code>first\nsecond\n</code></pre>", trim((string) $html));
    }

    public function test_blank_input_still_renders_to_null(): void
    {
        $this->assertNull(MarkdownRenderer::render(null));
        $this->assertNull(MarkdownRenderer::render("  \n  "));
    }
}
