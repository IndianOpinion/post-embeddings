<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use WP_Post;

/**
 * Filters and orders scan hits in PHP (cases 45 to 48): drop the post
 * itself, ineligible posts and disallowed types; order by score, then newer
 * date, then lower id; drop near-identical copies; keep `count`.
 */
final class HitRanker
{
    public function __construct(private readonly Eligibility $eligibility, private readonly IndexStore $store)
    {
    }

    /**
     * @param list<Hit> $hits
     * @param list<string> $types
     * @param list<float>|null $self the source post's vector, for duplicate checks
     * @return list<Scored>
     */
    public function rank(
        array $hits,
        int $count,
        array $types,
        int $excludeId = 0,
        ?array $self = null,
        float $duplicateScore = 1.01,
        ?int $notBefore = null,
        string $modelKey = '',
        int $dim = 0
    ): array {
        $ids = [];
        foreach ($hits as $hit) {
            if ($hit->id !== $excludeId) {
                $ids[] = $hit->id;
            }
        }
        $posts = $this->eligibility->filterIds($ids, $types);
        $candidates = [];
        foreach ($hits as $hit) {
            $post = $posts[$hit->id] ?? null;
            if ($post === null || $hit->id === $excludeId) {
                continue;
            }
            if ($notBefore !== null && self::time($post) < $notBefore) {
                continue;
            }
            $candidates[] = new Scored($post, $hit->score);
        }
        usort($candidates, static function (Scored $a, Scored $b): int {
            return [$b->score, self::time($b->post), $a->post->ID] <=> [$a->score, self::time($a->post), $b->post->ID];
        });
        if ($duplicateScore > 1.0 || $modelKey === '' || $dim < 1) {
            return array_slice($candidates, 0, $count);
        }
        $rows = $this->store->getMany(array_map(static fn (Scored $s): int => $s->post->ID, $candidates), $modelKey);
        $kept = [];
        $keptVectors = [];
        foreach ($candidates as $c) {
            if (count($kept) >= $count) {
                break;
            }
            $vector = isset($rows[$c->post->ID]) ? VectorMath::unpack($rows[$c->post->ID]->vector, $dim) : null;
            if ($self !== null && $c->score >= $duplicateScore) {
                continue;
            }
            if ($vector !== null) {
                foreach ($keptVectors as $other) {
                    if (VectorMath::cosine($vector, $other) >= $duplicateScore) {
                        continue 2;
                    }
                }
                $keptVectors[] = $vector;
            }
            $kept[] = $c;
        }

        return $kept;
    }

    public static function time(WP_Post $post): int
    {
        $t = strtotime($post->post_date_gmt . ' UTC');
        if ($t === false || str_starts_with($post->post_date_gmt, '0000')) {
            $t = strtotime($post->post_date);
        }

        return $t === false ? 0 : $t;
    }
}
