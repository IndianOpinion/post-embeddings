<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Text;

/**
 * Estimates input tokens when the provider does not report usage.
 *
 * Characters divided by a per-script ratio (characters per token), rounded
 * up. Latin text runs at about four characters per token; most non-Latin
 * scripts run far lower, so a flat divide-by-four would undercount them.
 * Over-estimating is the safe direction for a budget. The ratios pass
 * through `post_embeddings_token_ratios`, and the result through
 * `post_embeddings_token_estimate`.
 */
final class TokenEstimator
{
    /** @return array<string, float> script group => characters per token */
    public static function defaultRatios(): array
    {
        return [
            'latin' => 4.0,
            'cyrillic_greek' => 2.5,
            'arabic_hebrew' => 2.0,
            'indic' => 1.5,
            'southeast_asian' => 1.5,
            'cjk' => 1.0,
            'other' => 2.0,
        ];
    }

    /** @return array<string, string> script group => regex character class */
    private static function groups(): array
    {
        return [
            'cyrillic_greek' => '[\p{Cyrillic}\p{Greek}\p{Armenian}\p{Georgian}]',
            'arabic_hebrew' => '[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}]',
            'indic' => '[\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Oriya}\p{Tamil}\p{Telugu}'
                . '\p{Kannada}\p{Malayalam}\p{Sinhala}\p{Tibetan}\p{Ol_Chiki}]',
            'southeast_asian' => '[\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]',
            'cjk' => '[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]',
            'other_letters' => '[\p{L}\p{M}\p{N}]',
        ];
    }

    public function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $ratios = apply_filters('post_embeddings_token_ratios', self::defaultRatios());
        $ratios = is_array($ratios) ? array_merge(self::defaultRatios(), $ratios) : self::defaultRatios();
        $total = mb_strlen($text, 'UTF-8');
        $counted = 0;
        $tokens = 0.0;
        foreach (self::groups() as $group => $class) {
            $n = (int) preg_match_all('/' . $class . '/u', $text);
            if ($group === 'other_letters') {
                // Letters not in any group above, minus Latin, which is the remainder.
                $latin = (int) preg_match_all('/[\p{Latin}\p{N}]/u', $text);
                $n = max(0, $n - $counted - $latin);
                $tokens += $n / self::ratio($ratios, 'other');
                $counted += $n;
                continue;
            }
            $tokens += $n / self::ratio($ratios, $group);
            $counted += $n;
        }
        $tokens += max(0, $total - $counted) / self::ratio($ratios, 'latin');
        $estimate = (int) ceil($tokens);
        $filtered = apply_filters('post_embeddings_token_estimate', $estimate, $text);

        return is_int($filtered) && $filtered >= 0 ? $filtered : $estimate;
    }

    /**
     * @param list<string> $texts
     */
    public function estimateAll(array $texts): int
    {
        $sum = 0;
        foreach ($texts as $text) {
            $sum += $this->estimate($text);
        }

        return $sum;
    }

    /** @param array<mixed> $ratios */
    private static function ratio(array $ratios, string $group): float
    {
        $r = $ratios[$group] ?? 4.0;

        return is_numeric($r) && (float) $r > 0.0 ? (float) $r : 4.0;
    }
}
