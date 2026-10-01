<?php

namespace App\Services\Chet;

/**
 * What an agent-authored body may carry into a Teams activity once it is NO
 * LONGER run through TeamsText::escape (teams_post_message, card 5sALzgSC).
 *
 * Markdown survives: lists, emphasis, code, blank lines, headings, quotes and
 * ordinary `[text](https://...)` links. Each rule below swaps or drops a single
 * character and keeps everything around it, so none of the agent's text is
 * lost. No rule tries to decide whether a match really is a tag, image or link,
 * so the rules do not depend on parsing markdown the way the renderer does.
 * The cost is that they apply everywhere in the body, including inside code
 * spans and code blocks, and to prose that only looks like the syntax
 * (`[Status]: done` posts as `[Status]： done`).
 *
 *  1. Tag-shaped HTML. A `<` followed by a letter, `/`, `!` or `?` (`<at>`,
 *     `</at>`, `<img`, `<!--`, `<https://`) becomes U+FF1C FULLWIDTH LESS-THAN
 *     SIGN. The text stays legible and nothing can be parsed as a tag or
 *     autolink. This is what stops a body forging a Teams `<at>` mention marker;
 *     the mention ENTITY itself is only ever built server-side. Any other `<`
 *     (`a < b`, `<= 5`) is left alone.
 *  2. Images. Every markdown image (inline, reference-style or shortcut) starts
 *     with `![`. That `!` is dropped, so what remains is at most a plain link
 *     and a body cannot embed a remote image (a read beacon) in the operator chat.
 *  3. Non-web link targets. An inline link's target always follows `](`, and a
 *     reference definition's target always follows `]:`, whatever the link
 *     text, label or surrounding container. Unless what follows (after optional
 *     spaces or tabs and at most one line break) starts with http:// or
 *     https://, that `(` becomes U+FF08 or that `:` becomes U+FF1A. Link syntax
 *     therefore cannot point at javascript:, data:, file:, a relative path, ...
 *     The target stays visible as plain text.
 *
 * Cards and adaptive payloads are not this class's concern: the activity is
 * built in TeamsBotClient::postMarkdownMessage() from type/text/textFormat and
 * server-built mention entities only, so no body text can become an attachment.
 */
final class TeamsMarkdownPolicy
{
    public const FULLWIDTH_LT = "\u{FF1C}";

    public const FULLWIDTH_LPAREN = "\u{FF08}";

    public const FULLWIDTH_COLON = "\u{FF1A}";

    /**
     * @return array{text: string, neutralized: array{html_tags: int, images: int, links: int}}
     */
    public static function apply(string $text): array
    {
        $tags = 0;
        $text = (string) preg_replace_callback(
            '/<(?=[\/!?]|[A-Za-z])/u',
            function () use (&$tags): string {
                $tags++;

                return self::FULLWIDTH_LT;
            },
            $text,
        );

        $images = 0;
        $text = (string) preg_replace_callback(
            '/!(?=\[)/u',
            function () use (&$images): string {
                $images++;

                return '';
            },
            $text,
        );

        $links = 0;
        $text = (string) preg_replace_callback(
            '/\]([(:])(?![ \t]*(?:\r\n|\n|\r)?[ \t]*https?:\/\/[^\s\/])/iu',
            function (array $m) use (&$links): string {
                $links++;

                return ']'.($m[1] === '(' ? self::FULLWIDTH_LPAREN : self::FULLWIDTH_COLON);
            },
            $text,
        );

        return [
            'text' => $text,
            'neutralized' => ['html_tags' => $tags, 'images' => $images, 'links' => $links],
        ];
    }
}
