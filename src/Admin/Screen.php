<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Admin;

use IndianOpinion\PostEmbeddings\Index\Drain;
use IndianOpinion\PostEmbeddings\Plugin;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\StatusReport;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Store\Schema;

/**
 * Tools > Post embeddings: settings, status and the three buttons (case 69).
 * Every action is a POST with a nonce and a capability check.
 */
final class Screen
{
    public const SLUG = 'post-embeddings';
    public const ACTION = 'post_embeddings_action';
    public const OPS = ['save', 'drain', 'rebuild', 'resume'];

    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly StatusReport $status,
        private readonly Drain $drain,
        private readonly Sidecar $sidecar,
        private readonly ProviderResolver $providers,
        private readonly IndexStore $store
    ) {
    }

    public function menu(): void
    {
        add_management_page('Post embeddings', 'Post embeddings', Plugin::capability(), self::SLUG, [$this, 'page']);
    }

    /**
     * Handles a POST from the screen. Returns a result code; `forbidden` for
     * a bad nonce or a user without the capability.
     *
     * @param array<string, mixed> $post usually $_POST
     */
    public function handle(array $post): string
    {
        $op = isset($post['op']) && is_string($post['op']) ? $post['op'] : '';
        if (!in_array($op, self::OPS, true)) {
            return 'unknown';
        }
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if (!current_user_can(Plugin::capability()) || wp_verify_nonce($nonce, self::ACTION . '_' . $op) === false) {
            return 'forbidden';
        }
        switch ($op) {
            case 'save':
                $input = isset($post['settings']) && is_array($post['settings']) ? wp_unslash($post['settings']) : [];
                if (!isset($input['post_types'])) {
                    $input['post_types'] = [];
                }
                foreach (['enabled', 'search_enabled', 'search_no_store', 'log', 'send_dimensions'] as $flag) {
                    $input[$flag] = !empty($input[$flag]);
                }
                $errors = $this->settings->save($input, $this->state);
                $this->providers->reset();
                set_transient('post_embeddings_notice', $errors === [] ? ['Settings saved.'] : $errors, 60);

                return $errors === [] ? 'saved' : 'saved_with_errors';
            case 'drain':
                $report = $this->drain->run();

                return 'drain_' . $report['result'];
            case 'rebuild':
                $p = $this->providers->provider();
                if ($p === null) {
                    return 'rebuild_idle';
                }
                $report = $this->sidecar->rebuild((string) $this->providers->modelKey(), $p->dimensions());

                return $report === null ? 'rebuild_failed' : 'rebuilt';
            default:
                $this->state->clearPause();

                return 'resumed';
        }
    }

    /** admin-post.php glue. */
    public function handleRequest(): void
    {
        $result = $this->handle($_POST);
        if ($result === 'forbidden') {
            wp_die(esc_html('Sorry, you are not allowed to do that.'), 403);
        }
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'pe_result' => $result], admin_url('tools.php')));
        exit;
    }

    public function page(): void
    {
        if (!current_user_can(Plugin::capability())) {
            wp_die(esc_html('Sorry, you are not allowed to do that.'), 403);
        }
        echo $this->renderPage(); // phpcs:ignore -- every value is escaped in renderPage().
    }

    public function renderPage(): string
    {
        $s = $this->status->collect();
        $set = $this->settings->all();
        $h = '<div class="wrap"><h1>' . esc_html('Post embeddings') . '</h1>';
        if (isset($_GET['pe_result']) && is_string($_GET['pe_result'])) {
            $h .= '<div class="notice notice-info"><p>' . esc_html('Result: ' . sanitize_key($_GET['pe_result'])) . '</p></div>';
        }
        $notice = get_transient('post_embeddings_notice');
        if (is_array($notice)) {
            delete_transient('post_embeddings_notice');
            foreach ($notice as $line) {
                $h .= '<div class="notice notice-info"><p>' . esc_html((string) $line) . '</p></div>';
            }
        }

        $h .= '<h2>' . esc_html('Status') . '</h2><table class="widefat striped"><tbody>';
        $kill = $s['kill_switch'];
        $rows = [
            'Provider and model key' => $s['model_key'] ?? 'Not configured (the plugin is idle)',
            'Kill switch' => $kill['on'] ? 'On (from the ' . ($kill['source'] === 'constant' ? 'POST_EMBEDDINGS_DISABLED constant' : 'setting') . ')' : 'Off',
            'Search embeddings' => $s['search_enabled'] ? 'On' : 'Off',
            'Pause' => $s['paused'] === null ? 'Not paused' : 'Paused: the provider refused the credentials',
            'Index table' => $s['table'] . ($s['schema_missing'] === [] ? '' : ' (missing columns: ' . implode(', ', $s['schema_missing']) . ')'),
            'Counts by status' => self::pairs($s['current']),
            'Progress' => sprintf('%d of %d posts under the current model', $s['progress']['embedded'], $s['progress']['eligible']),
            'Indexing today' => sprintf(
                '%d of %d posts, %d tokens%s',
                $s['caps']['index_posts']['used'],
                $s['caps']['index_posts']['limit'],
                $s['caps']['index_tokens']['used'],
                $s['caps']['index_tokens']['limit'] > 0 ? ' of ' . $s['caps']['index_tokens']['limit'] : ' (no token cap)'
            ),
            'Search queries today' => sprintf('%d of %d', $s['caps']['queries']['used'], $s['caps']['queries']['limit']),
            'Sidecar directory' => $s['sidecar']['directory'] . ($s['sidecar']['protected'] ? ' (deny files in place)' : ' (deny files missing)'),
            'Sidecar file' => is_array($s['sidecar']['file'])
                ? sprintf('%s, %d bytes, %d records, %d s old', $s['sidecar']['file']['path'], $s['sidecar']['file']['bytes'], $s['sidecar']['file']['count'], $s['sidecar']['file']['age'])
                : 'Not built',
            'Last build' => is_array($s['sidecar']['last_build']) ? self::pairs(array_filter($s['sidecar']['last_build'], 'is_scalar')) : 'None',
            'Last sidecar error' => (string) ($s['sidecar']['error'] ?? 'None'),
            'Last drain' => is_array($s['last_drain']) ? gmdate('Y-m-d H:i:s', (int) $s['last_drain']['at']) . ' UTC, ' . self::pairs(array_filter($s['last_drain'], 'is_scalar')) : 'Never',
        ];
        foreach ($rows as $label => $value) {
            $h .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        $h .= '</tbody></table><p>';
        foreach (['drain' => 'Run drain now', 'rebuild' => 'Rebuild sidecar', 'resume' => 'Resume'] as $op => $label) {
            $h .= $this->button($op, $label) . ' ';
        }
        $h .= '</p>';
        $h .= $this->settingsForm($set);

        return $h . '</div>';
    }

    /** @param array<string, mixed> $set */
    private function settingsForm(array $set): string
    {
        $h = '<h2>' . esc_html('Settings') . '</h2>';
        $h .= '<p><strong>' . esc_html('Privacy:') . '</strong> ' . esc_html('The text of published, public posts is sent to the provider below to compute embeddings. Search queries typed by readers are sent too. Private, draft and password-protected posts are never sent.') . '</p>';
        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $h .= '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="save">';
        $h .= wp_nonce_field(self::ACTION . '_save', '_wpnonce', true, false);
        $h .= '<table class="form-table"><tbody>';
        $h .= self::text('base_url', 'Base URL', (string) $set['base_url'], 'https only; http allowed for localhost and 127.0.0.1.');
        $h .= self::text('model', 'Model', (string) $set['model'], '');
        $h .= self::text('dimensions', 'Dimensions', (string) $set['dimensions'], 'Length of every vector the model returns.');
        if (Settings::apiKeyConstantDefined()) {
            $h .= '<tr><th scope="row">' . esc_html('API key') . '</th><td>' . esc_html('Set by the POST_EMBEDDINGS_API_KEY constant.') . '</td></tr>';
        } else {
            $saved = is_string($set['api_key']) && $set['api_key'] !== '';
            $h .= '<tr><th scope="row"><label for="pe-api_key">' . esc_html('API key') . '</label></th><td>'
                . '<input type="password" id="pe-api_key" name="settings[api_key]" value="" autocomplete="off" class="regular-text"> '
                . esc_html($saved ? 'saved' : 'not set') . '</td></tr>';
        }
        foreach (['max_input_chars' => 'Max input characters', 'max_batch' => 'Max inputs per call'] as $k => $label) {
            $h .= self::text($k, $label, (string) $set[$k], '');
        }
        $h .= self::check('send_dimensions', 'Send dimensions in the request', (bool) $set['send_dimensions']);
        $h .= self::check('enabled', 'Enabled (kill switch off)', (bool) $set['enabled']);
        $h .= self::check('search_enabled', 'Search query embeddings', (bool) $set['search_enabled']);
        $types = is_array($set['post_types']) ? $set['post_types'] : [];
        $h .= '<tr><th scope="row">' . esc_html('Post types') . '</th><td>';
        foreach (get_post_types(['public' => true]) as $type) {
            $h .= '<label><input type="checkbox" name="settings[post_types][]" value="' . esc_attr((string) $type) . '"'
                . (in_array($type, $types, true) ? ' checked' : '') . '> ' . esc_html((string) $type) . '</label> ';
        }
        $h .= '</td></tr>';
        $numbers = [
            'daily_post_cap' => 'Daily post cap', 'daily_token_cap' => 'Daily token cap (0 is off)',
            'daily_query_cap' => 'Daily search query cap', 'backfill_cap_ceiling' => 'Largest backfill --cap',
            'batch_size' => 'Batch size', 'drain_interval' => 'Drain interval (s)', 'drain_max_batches' => 'Batches per drain',
            'max_attempts' => 'Max attempts', 'min_chars' => 'Minimum characters', 'min_score' => 'Related minimum score',
            'search_min_score' => 'Search minimum score', 'duplicate_score' => 'Duplicate score', 'overfetch' => 'Over-fetch',
            'max_age_days' => 'Related maximum age in days (0 is off)', 'sidecar_max_age' => 'Sidecar maximum age (s)',
        ];
        foreach ($numbers as $k => $label) {
            $h .= self::text($k, $label, (string) $set[$k], '');
        }
        $h .= self::check('search_no_store', 'Send Cache-Control: private on search requests', (bool) $set['search_no_store']);
        $h .= self::check('log', 'Write log lines to the PHP error log', (bool) $set['log']);
        $h .= '</tbody></table>';

        return $h . '<p><button type="submit" class="button button-primary">' . esc_html('Save settings') . '</button></p></form>';
    }

    private function button(string $op, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . wp_nonce_field(self::ACTION . '_' . $op, '_wpnonce', true, false)
            . '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
    }

    private static function text(string $key, string $label, string $value, string $help): string
    {
        return '<tr><th scope="row"><label for="pe-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="text" class="regular-text" id="pe-' . esc_attr($key) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">'
            . ($help === '' ? '' : '<p class="description">' . esc_html($help) . '</p>') . '</td></tr>';
    }

    private static function check(string $key, string $label, bool $on): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"'
            . ($on ? ' checked' : '') . '></td></tr>';
    }

    /** @param array<mixed> $pairs */
    private static function pairs(array $pairs): string
    {
        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = $k . ' ' . (is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? (string) $v : ''));
        }

        return implode(', ', $out);
    }

    /** Admin notices for users who can manage the plugin. */
    public function notices(): void
    {
        echo $this->noticeHtml(); // phpcs:ignore -- escaped in noticeHtml().
    }

    public function noticeHtml(): string
    {
        if (!current_user_can(Plugin::capability())) {
            return '';
        }
        $h = '';
        if ($this->state->paused() === 'paused_auth') {
            $h .= '<div class="notice notice-error"><p>' . esc_html('Post embeddings is paused: the provider refused the credentials (HTTP 401 or 403). Fix the API key and save the settings, or run `wp post-embeddings resume`.') . '</p></div>';
        }
        if (!$this->store->ready()) {
            $h .= '<div class="notice notice-error"><p>' . esc_html('Post embeddings: the index table is missing. Related posts show their fallback until it is repaired. Deactivate and activate the plugin to create it.') . '</p></div>';
        }
        $missing = Schema::missingColumns();
        if ($missing !== []) {
            $h .= '<div class="notice notice-error"><p>' . esc_html('Post embeddings: the index table is missing columns: ' . implode(', ', $missing) . '. Deactivate and activate the plugin to repair it.') . '</p></div>';
        }
        if (PHP_INT_SIZE < 8) {
            $h .= '<div class="notice notice-error"><p>' . esc_html('Post embeddings needs a 64-bit PHP build and is idle.') . '</p></div>';
        }

        return $h;
    }
}
