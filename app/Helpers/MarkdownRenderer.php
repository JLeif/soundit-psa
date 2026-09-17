<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class MarkdownRenderer
{
    /**
     * A single newline inside a paragraph renders as a line break, not a
     * space. Strict CommonMark collapses it (a "soft break"), which turned
     * one-line-per-line text - the Html2Text prefill of an email-originated
     * ticket, anything pasted from chat or mail - into run-on paragraphs
     * after a save (Charlie, 2026-09-17). Jeeves ruled the GitHub/Slack/Teams
     * behaviour: a typed line break is a rendered line break. A blank line
     * still starts a new paragraph; fenced code is untouched. Every markdown
     * surface shares this renderer and inherits the setting by design.
     */
    private const OPTIONS = [
        'renderer' => ['soft_break' => "<br />\n"],
    ];

    /**
     * Convert markdown text to sanitized HTML.
     */
    public static function render(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return HtmlSanitizer::sanitize(Str::markdown($text, self::OPTIONS));
    }
}
