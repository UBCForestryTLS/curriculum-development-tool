<?php

namespace App\Support;

class SearchedTextHighlighter
{
    public const START_MARKER = '__startsel__';

    public const END_MARKER = '__endsel__';

    public static function render(string $snippet): string
    {
        return str_replace(
            [self::START_MARKER, self::END_MARKER],
            ['<mark>', '</mark>'],
            e($snippet)
        );
    }
}
