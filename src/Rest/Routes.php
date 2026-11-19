<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Rest;

use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Plugin;
use IndianOpinion\PostEmbeddings\Query\Related;
use IndianOpinion\PostEmbeddings\Query\Scored;
use IndianOpinion\PostEmbeddings\Query\Search;
use IndianOpinion\PostEmbeddings\StatusReport;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes under post-embeddings/v1 (case 61). Public routes are
 * read-only and rate-limited; scores only for the manage capability.
 */
final class Routes
{
    public const NS = 'post-embeddings/v1';

    public function __construct(
        private readonly Related $related,
        private readonly Search $search,
        private readonly StatusReport $status,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NS, '/related/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'related'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['validate_callback' => static fn ($v): bool => is_numeric($v) && (int) $v > 0],
                'count' => ['default' => 4],
            ],
        ]);
        register_rest_route(self::NS, '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => ['q' => ['required' => true], 'count' => ['default' => 10]],
        ]);
        register_rest_route(self::NS, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool|WP_Error
    {
        if (current_user_can(Plugin::capability())) {
            return true;
        }

        return new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    public function related(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $post = $id > 0 ? get_post($id) : null;
        if (
            !$post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_password !== ''
            || !is_post_type_viewable($post->post_type)
        ) {
            return new WP_Error('rest_post_invalid_id', 'Not found.', ['status' => 404]);
        }
        $count = self::clamp($request->get_param('count'), 1, 12, 4);
        try {
            $results = $this->related->find($id, ['count' => $count]);
        } catch (\Throwable $e) {
            $this->logger->error('related_error', ['post' => $id, 'class' => get_class($e)]);
            $results = [];
        }

        return new WP_REST_Response($this->shape($results, current_user_can(Plugin::capability())), 200);
    }

    public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $q = $request->get_param('q');
        $count = self::clamp($request->get_param('count'), 1, 20, 10);
        $outcome = $this->search->search(is_string($q) ? $q : '', $count, $_SERVER);
        if ($outcome->mode === 'invalid') {
            return new WP_Error(
                'rest_invalid_param',
                sprintf('q must be %d to %d characters.', Search::MIN_LEN, Search::MAX_LEN),
                ['status' => 400]
            );
        }
        $scores = $outcome->mode === 'semantic' && current_user_can(Plugin::capability());

        return new WP_REST_Response(['mode' => $outcome->mode, 'results' => $this->shape($outcome->results, $scores)], 200);
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response($this->status->collect(), 200);
    }

    /**
     * @param list<Scored> $results
     * @return list<array<string, mixed>>
     */
    private function shape(array $results, bool $withScores): array
    {
        $out = [];
        foreach ($results as $s) {
            $item = [
                'id' => $s->post->ID,
                'title' => \IndianOpinion\PostEmbeddings\Front\Markup::title($s->post),
                'link' => (string) get_permalink($s->post),
                'date' => (string) get_post_time('c', true, $s->post),
            ];
            if ($withScores) {
                $item['score'] = round($s->score, 6);
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function clamp(mixed $v, int $min, int $max, int $default): int
    {
        return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
    }
}
