<?php

namespace App\Services\Search;

/** Query-focused, word-bounded excerpts from the selected source passage. */
final class SearchSnippet
{
    public static function make(string $text, string $query, ?string $title, int $maxLength = 200): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(['\\r\\n', '\\n', '\\t'], ' ', $text)) ?? $text);
        $title = trim((string) $title);
        while ($title !== '' && str_starts_with($text, $title)) {
            $text = ltrim(mb_substr($text, mb_strlen($title)));
        }
        $repeatedTitle = $title === '' ? false : mb_strpos($text, $title);
        if ($repeatedTitle !== false && $repeatedTitle < $maxLength && ! preg_match('/[.!?]/u', mb_substr($text, 0, $repeatedTitle))) {
            $text = ltrim(mb_substr($text, $repeatedTitle + mb_strlen($title)));
        }
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $terms = self::terms($query);
        preg_match_all('/[.!?]\s+/u', $text, $boundaries, PREG_OFFSET_CAPTURE);
        $starts = [0];
        foreach ($boundaries[0] as [$boundary, $byteOffset]) {
            $starts[] = mb_strlen(substr($text, 0, $byteOffset + strlen($boundary)));
        }
        $bestStart = 0;
        $bestScore = -1;
        foreach ($starts as $start) {
            $window = mb_strtolower(mb_substr($text, $start, $maxLength));
            preg_match_all('/[\p{L}\p{N}_]+/u', $window, $windowWords);
            $stems = array_map(self::stem(...), $windowWords[0]);
            $score = count(array_intersect($terms, array_unique($stems)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStart = $start;
            }
        }
        if ($bestStart > 0) {
            while ($bestStart < mb_strlen($text) && ! preg_match('/\s/u', mb_substr($text, $bestStart - 1, 1))) {
                $bestStart++;
            }
        }
        $excerpt = mb_substr($text, $bestStart, $maxLength - ($bestStart > 0 ? 2 : 0));
        if ($bestStart + mb_strlen($excerpt) < mb_strlen($text)) {
            $excerpt = preg_replace('/\s+\S*$/u', '', $excerpt) ?? $excerpt;
            $excerpt = rtrim($excerpt).'…';
        }

        return ($bestStart > 0 ? '… ' : '').$excerpt;
    }

    /** @return list<string> */
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($text), $words);
        $stopWords = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'of', 'on', 'or', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'your', 'its', 'beyond', 'before', 'after', 'should', 'must', 'need', 'useful', 'best', 'they', 'their', 'there', 'another'];
        $filtered = array_filter($words[0], fn (string $word): bool => mb_strlen($word) > 2 && ! in_array($word, $stopWords, true));

        return array_values(array_unique(array_map(self::stem(...), $filtered)));
    }

    private static function stem(string $word): string
    {
        if (mb_strlen($word) <= 5) {
            return $word;
        }
        $word = preg_replace('/(?<=(?:ch|sh|ss|x|z))es$/u', '', $word) ?? $word;
        $word = preg_replace('/(?:ing|ed|s)$/u', '', $word) ?? $word;

        return mb_strlen($word) > 4 ? preg_replace('/e$/u', '', $word) ?? $word : $word;
    }
}
