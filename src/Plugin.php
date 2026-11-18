<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

use IndianOpinion\PostEmbeddings\Admin\Screen;
use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Budget\SpendGate;
use IndianOpinion\PostEmbeddings\Cli\Command;
use IndianOpinion\PostEmbeddings\Front\Hooks;
use IndianOpinion\PostEmbeddings\Front\RelatedRenderer;
use IndianOpinion\PostEmbeddings\Front\SearchRenderer;
use IndianOpinion\PostEmbeddings\Health\Exposure;
use IndianOpinion\PostEmbeddings\Health\SiteHealth;
use IndianOpinion\PostEmbeddings\Index\Backfill;
use IndianOpinion\PostEmbeddings\Index\Drain;
use IndianOpinion\PostEmbeddings\Index\DrainLock;
use IndianOpinion\PostEmbeddings\Index\Embedder;
use IndianOpinion\PostEmbeddings\Index\Indexer;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\Posts\PostSource;
use IndianOpinion\PostEmbeddings\Posts\WpPostSource;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Query\HitRanker;
use IndianOpinion\PostEmbeddings\Query\RateLimiter;
use IndianOpinion\PostEmbeddings\Query\Related;
use IndianOpinion\PostEmbeddings\Query\Search;
use IndianOpinion\PostEmbeddings\Rest\Routes;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Store\Schema;
use IndianOpinion\PostEmbeddings\Store\WpdbIndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;

/**
 * Wires the services and the WordPress hooks. Tests build it with their own
 * store, post source, clock and log writer.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CRON_SCHEDULE = 'post_embeddings_interval';

    private static ?self $instance = null;

    public readonly Settings $settings;
    public readonly State $state;
    public readonly Clock $clock;
    public readonly Logger $logger;
    public readonly Cache $cache;
    public readonly IndexStore $store;
    public readonly PostSource $posts;
    public readonly ProviderResolver $providers;
    public readonly Eligibility $eligibility;
    public readonly EmbeddingText $text;
    public readonly TokenEstimator $tokens;
    public readonly Counters $counters;
    public readonly SpendGate $meter;
    public readonly Directory $directory;
    public readonly Sidecar $sidecar;
    public readonly Embedder $embedder;
    public readonly Indexer $indexer;
    public readonly Drain $drain;
    public readonly Backfill $backfill;
    public readonly HitRanker $ranker;
    public readonly Related $related;
    public readonly RateLimiter $limiter;
    public readonly Search $search;
    public readonly RelatedRenderer $relatedRenderer;
    public readonly SearchRenderer $searchRenderer;
    public readonly Hooks $front;
    public readonly StatusReport $status;
    public readonly Routes $routes;
    public readonly Screen $screen;
    public readonly Exposure $exposure;
    public readonly SiteHealth $health;

    /**
     * @param callable(string): void|null $logWriter
     * @param callable(string): void|null $headerWriter
     */
    public function __construct(
        public readonly string $file,
        ?IndexStore $store = null,
        ?PostSource $posts = null,
        ?Clock $clock = null,
        ?callable $logWriter = null,
        ?callable $headerWriter = null
    ) {
        global $wpdb;
        $this->settings = new Settings();
        $this->state = new State();
        $this->clock = $clock ?? new SystemClock();
        $this->logger = new Logger($this->settings, $this->clock, $logWriter);
        $this->cache = new Cache();
        $this->store = $store ?? new WpdbIndexStore($wpdb);
        $this->posts = $posts ?? new WpPostSource($wpdb);
        $this->providers = new ProviderResolver($this->settings);
        $this->eligibility = new Eligibility($this->settings);
        $this->text = new EmbeddingText();
        $this->tokens = new TokenEstimator();
        $this->counters = new Counters($this->settings, $this->clock, $this->logger);
        $this->meter = new SpendGate();
        $this->directory = new Directory();
        $this->sidecar = new Sidecar($this->directory, $this->store, $this->eligibility, $this->state, $this->clock, $this->logger);
        $this->embedder = new Embedder(
            $this->store,
            $this->counters,
            $this->meter,
            $this->tokens,
            $this->settings,
            $this->state,
            $this->clock,
            $this->logger
        );
        $this->indexer = new Indexer(
            $this->store,
            $this->providers,
            $this->eligibility,
            $this->text,
            $this->settings,
            $this->sidecar,
            $this->clock
        );
        $this->drain = new Drain(
            $this->store,
            $this->providers,
            $this->eligibility,
            $this->text,
            $this->tokens,
            $this->embedder,
            $this->counters,
            $this->sidecar,
            $this->settings,
            $this->state,
            $this->clock,
            $this->logger,
            new DrainLock($this->clock)
        );
        $this->backfill = new Backfill(
            $this->store,
            $this->posts,
            $this->providers,
            $this->eligibility,
            $this->text,
            $this->tokens,
            $this->embedder,
            $this->counters,
            $this->sidecar,
            $this->settings,
            $this->state,
            $this->clock
        );
        $this->ranker = new HitRanker($this->eligibility, $this->store);
        $this->related = new Related(
            $this->store,
            $this->providers,
            $this->sidecar,
            $this->ranker,
            $this->cache,
            $this->settings,
            $this->state,
            $this->logger
        );
        $this->limiter = new RateLimiter($this->cache, $this->clock);
        $this->search = new Search(
            $this->store,
            $this->providers,
            $this->sidecar,
            $this->ranker,
            $this->limiter,
            $this->counters,
            $this->meter,
            $this->tokens,
            $this->cache,
            $this->settings,
            $this->logger
        );
        $this->relatedRenderer = new RelatedRenderer($this->related, $this->logger);
        $this->searchRenderer = new SearchRenderer($this->search, $this->logger);
        $this->front = new Hooks(
            $this->relatedRenderer,
            $this->searchRenderer,
            $this->search,
            $this->providers,
            $this->store,
            $this->settings,
            $this->logger,
            dirname($file),
            $headerWriter
        );
        $this->status = new StatusReport(
            $this->providers,
            $this->store,
            $this->posts,
            $this->counters,
            $this->sidecar,
            $this->directory,
            $this->settings,
            $this->state
        );
        $this->routes = new Routes($this->related, $this->search, $this->status, $this->logger);
        $this->screen = new Screen($this->settings, $this->state, $this->status, $this->drain, $this->sidecar, $this->providers, $this->store);
        $this->exposure = new Exposure($this->directory, $this->state, $this->clock);
        $this->health = new SiteHealth(
            $this->providers,
            $this->store,
            $this->sidecar,
            $this->exposure,
            $this->settings,
            $this->state,
            $this->clock
        );
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    /** Capability for everything admin-facing, filterable. */
    public static function capability(): string
    {
        $cap = apply_filters('post_embeddings_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /** Boots the plugin from the main file. */
    public static function boot(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->register();
        }

        return self::$instance;
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [$this, 'init']);
        add_action('save_post', [$this, 'onSave'], 20, 3);
        add_action('transition_post_status', [$this, 'onTransition'], 20, 3);
        add_action('deleted_post', [$this, 'onDeleted'], 10, 1);
        add_action(Drain::HOOK, [$this, 'runDrain']);
        add_action(Sidecar::REBUILD_HOOK, [$this, 'runRebuild']);
        add_action(Sidecar::HOURLY_HOOK, [$this, 'runHourly']);
        add_action('rest_api_init', [$this->routes, 'register']);
        add_action('admin_menu', [$this->screen, 'menu']);
        add_action('admin_post_' . Screen::ACTION, [$this->screen, 'handleRequest']);
        add_action('admin_notices', [$this->screen, 'notices']);
        add_action('admin_init', [$this, 'adminInit']);
        add_filter('site_status_tests', [$this->health, 'register']);
        add_action('wp_uninitialize_site', [$this, 'onSiteDeleted'], 10, 1);
        add_action('template_redirect', [$this, 'sendHeaders']);
        add_filter('the_posts', [$this, 'thePosts'], 10, 2);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('post-embeddings', new Command($this));
        }
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public function cronSchedules(array $schedules): array
    {
        $interval = max(60, min(3600, $this->settings->int('drain_interval')));
        $schedules[self::CRON_SCHEDULE] = ['interval' => $interval, 'display' => 'Post embeddings drain'];

        return $schedules;
    }

    public function init(): void
    {
        $this->front->registerBlocks();
        $this->front->registerShortcodes();
        $this->schedule();
    }

    /** Ensures the recurring events exist; covers must-use installs. */
    public function schedule(): void
    {
        if (wp_next_scheduled(Drain::HOOK) === false) {
            wp_schedule_event($this->clock->now() + 60, self::CRON_SCHEDULE, Drain::HOOK);
        }
        if (wp_next_scheduled(Sidecar::HOURLY_HOOK) === false) {
            wp_schedule_event($this->clock->now() + 300, 'hourly', Sidecar::HOURLY_HOOK);
        }
    }

    public function adminInit(): void
    {
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            (new Schema($wpdb))->maybeUpgrade();
        }
        Privacy::register();
    }

    public function activate(): void
    {
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            (new Schema($wpdb))->install();
        }
        $this->schedule();
        if ($this->directory->protect() === null) {
            $key = $this->providers->modelKey();
            $this->exposure->check(true, $key === null ? null : Sidecar::fileName($key));
        }
    }

    public static function deactivate(): void
    {
        Uninstaller::unschedule();
    }

    public function onSave(int $postId, mixed $post = null, mixed $update = null): void
    {
        $this->guard(fn () => $this->indexer->onSave($postId, $post), 'save_error');
    }

    public function onTransition(string $new, string $old, mixed $post): void
    {
        $this->guard(fn () => $this->indexer->onTransition($new, $old, $post), 'save_error');
    }

    public function onDeleted(int $postId): void
    {
        $this->guard(fn () => $this->indexer->onDeleted($postId), 'delete_error');
    }

    public function runDrain(): void
    {
        $this->guard(fn () => $this->drain->run(), 'drain_error');
    }

    public function runRebuild(): void
    {
        $this->guard(function (): void {
            $p = $this->providers->provider();
            if ($p !== null) {
                $this->sidecar->rebuild((string) $this->providers->modelKey(), $p->dimensions());
            }
        }, 'rebuild_error');
    }

    public function runHourly(): void
    {
        $this->guard(function (): void {
            $p = $this->providers->provider();
            if ($p !== null) {
                $this->sidecar->rebuild(
                    (string) $this->providers->modelKey(),
                    $p->dimensions(),
                    max(60, $this->settings->int('sidecar_max_age'))
                );
            }
        }, 'rebuild_error');
    }

    public function sendHeaders(): void
    {
        $this->front->cacheHeaders();
    }

    /**
     * @param array<mixed> $posts
     * @return array<mixed>
     */
    public function thePosts(array $posts, mixed $query = null): array
    {
        return $this->front->reorderCoreSearch($posts, $query);
    }

    public function onSiteDeleted(mixed $site): void
    {
        global $wpdb;
        if (!is_object($site) || !isset($site->blog_id) || !$wpdb instanceof \wpdb) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        try {
            (new Uninstaller($wpdb, $this->sidecar, $this->directory))->site();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Runs a hook body; any exception goes to the log, never to the page.
     */
    private function guard(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->error($code, ['class' => get_class($e)]);
        }
    }
}
