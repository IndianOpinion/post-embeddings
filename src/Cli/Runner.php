<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Cli;

use IndianOpinion\PackedVectors\Exception\PackedVectorsError;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PostEmbeddings\Plugin;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/**
 * The WP-CLI commands, free of WP-CLI so they can be tested. Each command
 * returns an exit code; output goes through the writer.
 */
final class Runner
{
    public const COMMANDS = ['backfill', 'drain', 'status', 'rebuild', 'verify', 'related', 'search', 'retry', 'resume', 'prune', 'kill'];

    /** @var callable(string): void */
    private $out;

    /** @var callable(string): bool */
    private $confirm;

    /**
     * @param callable(string): void $out
     * @param callable(string): bool|null $confirm asked before destructive work; default yes
     */
    public function __construct(private readonly Plugin $plugin, callable $out, ?callable $confirm = null)
    {
        $this->out = $out;
        $this->confirm = $confirm ?? static fn (string $q): bool => true;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function run(string $command, array $args, array $assoc): int
    {
        if (!in_array($command, self::COMMANDS, true)) {
            $this->say('Usage: wp post-embeddings <' . implode('|', self::COMMANDS) . '>');

            return 1;
        }
        try {
            return $this->{$command}($args, $assoc);
        } catch (\Throwable $e) {
            $this->say('Error: ' . $e->getMessage());

            return 1;
        }
    }

    private function say(string $line): void
    {
        ($this->out)($line);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function backfill(array $args, array $assoc): int
    {
        $usage = 'Usage: wp post-embeddings backfill --cap=<n> [--post-type=<a,b>] [--order=newest|oldest] [--since=<YYYY-MM-DD>] [--batch=<n>] [--dry-run] [--resume] [--yes]';
        $cap = $assoc['cap'] ?? null;
        if (!is_numeric($cap) || (int) $cap < 1) {
            $this->say('Error: --cap is required and must be a positive number.');
            $this->say($usage);

            return 1;
        }
        $cap = (int) $cap;
        $ceiling = $this->plugin->settings->int('backfill_cap_ceiling');
        if ($cap > $ceiling) {
            $this->say(sprintf('Error: --cap=%d is above the backfill_cap_ceiling setting (%d). Refused.', $cap, $ceiling));

            return 1;
        }
        $types = $this->plugin->settings->postTypes();
        if (isset($assoc['post-type']) && is_string($assoc['post-type'])) {
            $public = get_post_types(['public' => true]);
            $asked = array_values(array_filter(
                array_map('trim', explode(',', $assoc['post-type'])),
                static fn (string $t): bool => in_array($t, $public, true)
            ));
            if ($asked === []) {
                $this->say('Error: no registered public post type in --post-type.');

                return 1;
            }
            $types = $asked;
        }
        $order = (string) ($assoc['order'] ?? 'newest');
        if (!in_array($order, ['newest', 'oldest'], true)) {
            $this->say('Error: --order must be newest or oldest.');

            return 1;
        }
        $since = null;
        if (isset($assoc['since'])) {
            $s = (string) $assoc['since'];
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, new \DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $s) {
                $this->say('Error: --since must be a date as YYYY-MM-DD.');

                return 1;
            }
            $since = $d->format('Y-m-d H:i:s');
        }
        $batch = isset($assoc['batch']) && is_numeric($assoc['batch']) ? (int) $assoc['batch'] : $this->plugin->settings->int('batch_size');
        if ($batch < 1 || $batch > 64) {
            $this->say('Error: --batch must be 1 to 64.');

            return 1;
        }
        $dry = !empty($assoc['dry-run']);
        if (!$dry && empty($assoc['yes']) && !($this->confirm)(sprintf('Send up to %d posts to the provider?', $cap))) {
            $this->say('Cancelled.');

            return 0;
        }

        return $this->plugin->backfill->run([
            'cap' => $cap,
            'types' => $types,
            'order' => $order === 'newest' ? 'desc' : 'asc',
            'since' => $since,
            'batch' => $batch,
            'dry_run' => $dry,
            'resume' => !empty($assoc['resume']),
        ], $this->out);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function drain(array $args, array $assoc): int
    {
        $r = $this->plugin->drain->run();
        $this->say(sprintf(
            'Drain: %s. Batches %d, sent %d, stored %d, errors %d, rows changed %d.',
            $r['result'],
            $r['batches'],
            $r['sent'],
            $r['stored'],
            $r['errors'],
            $r['changed']
        ));

        return in_array($r['result'], ['idle', 'no_table'], true) ? 1 : 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function status(array $args, array $assoc): int
    {
        $s = $this->plugin->status->collect();
        if (($assoc['format'] ?? '') === 'json') {
            $this->say((string) wp_json_encode($s));

            return 0;
        }
        $this->say('Model key: ' . ($s['model_key'] ?? 'none (idle)'));
        $this->say('Kill switch: ' . ($s['kill_switch']['on'] ? 'on (' . $s['kill_switch']['source'] . ')' : 'off'));
        $this->say('Paused: ' . ($s['paused'] ?? 'no'));
        $this->say('Table: ' . $s['table']);
        foreach ($s['counts'] as $key => $byStatus) {
            $parts = [];
            foreach ($byStatus as $status => $n) {
                $parts[] = $status . '=' . $n;
            }
            $this->say(sprintf('Rows %s%s: %s', $key, $key === $s['model_key'] ? ' (current)' : '', implode(' ', $parts)));
        }
        $this->say(sprintf('Progress: %d of %d posts under the current model', $s['progress']['embedded'], $s['progress']['eligible']));
        $c = $s['caps'];
        $this->say(sprintf(
            'Today (%s UTC): posts %d/%d, tokens %d/%s, queries %d/%d',
            $c['date'],
            $c['index_posts']['used'],
            $c['index_posts']['limit'],
            $c['index_tokens']['used'],
            $c['index_tokens']['limit'] > 0 ? (string) $c['index_tokens']['limit'] : 'off',
            $c['queries']['used'],
            $c['queries']['limit']
        ));
        $file = $s['sidecar']['file'];
        $this->say('Sidecar: ' . (is_array($file) ? sprintf('%s, %d records, %d bytes, %d s old', $file['path'], $file['count'], $file['bytes'], $file['age']) : 'not built'));
        $build = $s['sidecar']['last_build'];
        if (is_array($build)) {
            $this->say(sprintf('Last build: written %d, skipped %d, %s s', $build['written'], $build['skipped_total'], $build['seconds']));
        }
        $drain = $s['last_drain'];
        $this->say('Last drain: ' . (is_array($drain) ? gmdate('Y-m-d H:i:s', (int) $drain['at']) . ' UTC, ' . $drain['result'] : 'never'));

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function rebuild(array $args, array $assoc): int
    {
        $p = $this->plugin->providers->provider();
        if ($p === null) {
            $this->say('Error: no provider is configured.');

            return 1;
        }
        $r = $this->plugin->sidecar->rebuild((string) $this->plugin->providers->modelKey(), $p->dimensions());
        if ($r === null) {
            $this->say('Error: the sidecar could not be built: ' . (string) $this->plugin->state->get('sidecar_error', 'disabled'));

            return 1;
        }
        if ($r['lock_busy']) {
            $this->say('Another build holds the lock; one rebuild is queued.');

            return 0;
        }
        $this->say(sprintf('Built: written %d, skipped %d, %d bytes, %s s.', $r['written'], $r['skipped_total'], $r['bytes'], $r['seconds']));

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function verify(array $args, array $assoc): int
    {
        $p = $this->plugin->providers->provider();
        $key = $this->plugin->providers->modelKey();
        if ($p === null || $key === null) {
            $this->say('Error: no provider is configured.');

            return 1;
        }
        $rows = 0;
        $after = 0;
        do {
            $page = $this->plugin->store->page($key, $after, 500, IndexRow::OK);
            if ($page === []) {
                break;
            }
            $after = end($page)->postId;
            $eligible = $this->plugin->eligibility->filterIds(array_map(static fn (IndexRow $r): int => $r->postId, $page));
            foreach ($page as $row) {
                if (isset($eligible[$row->postId]) && VectorMath::unpack($row->vector, $p->dimensions()) !== null) {
                    $rows++;
                }
            }
        } while (count($page) === 500);
        try {
            $report = (new Scanner($this->plugin->sidecar->path($key)))->verify();
        } catch (PackedVectorsError $e) {
            $this->say('Sidecar unavailable: ' . $e->getMessage());

            return 1;
        }
        $records = $report->info()->count;
        $this->say(sprintf('Table: %d ok eligible rows. Sidecar: %d records.', $rows, $records));
        $ok = true;
        if ($records !== $rows) {
            $this->say(sprintf('Mismatch: %d rows versus %d records. Run `wp post-embeddings rebuild`.', $rows, $records));
            $ok = false;
        }
        if (!$report->ok()) {
            $this->say('Checksum check failed: ' . implode('; ', $report->problems()));
            $ok = false;
        } else {
            $this->say('Checksum check passed.');
        }

        return $ok ? 0 : 1;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function related(array $args, array $assoc): int
    {
        $id = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 0;
        if ($id < 1) {
            $this->say('Usage: wp post-embeddings related <post_id> [--count=<n>]');

            return 1;
        }
        $count = isset($assoc['count']) && is_numeric($assoc['count']) ? (int) $assoc['count'] : 4;
        $results = $this->plugin->related->find($id, ['count' => $count]);
        if ($results === []) {
            $this->say('No related posts (no current-model vector, or nothing above the minimum score).');

            return 0;
        }
        foreach ($results as $s) {
            $this->say(sprintf('%.4f  %d  %s', $s->score, $s->post->ID, \IndianOpinion\PostEmbeddings\Front\Markup::title($s->post)));
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function search(array $args, array $assoc): int
    {
        $text = implode(' ', $args);
        $count = isset($assoc['count']) && is_numeric($assoc['count']) ? (int) $assoc['count'] : 10;
        $o = $this->plugin->search->search($text, $count, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($o->mode === 'invalid') {
            $this->say('Error: the query must be 3 to 200 characters.');

            return 1;
        }
        $this->say('Mode: ' . $o->mode . ($o->reason !== null ? ' (' . $o->reason . ')' : ''));
        foreach ($o->results as $s) {
            $this->say(sprintf('%.4f  %d  %s', $s->score, $s->post->ID, \IndianOpinion\PostEmbeddings\Front\Markup::title($s->post)));
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function retry(array $args, array $assoc): int
    {
        if (empty($assoc['errors'])) {
            $this->say('Usage: wp post-embeddings retry --errors');

            return 1;
        }
        $key = $this->plugin->providers->modelKey();
        if ($key === null) {
            $this->say('Error: no provider is configured.');

            return 1;
        }
        $n = $this->plugin->store->retryErrors($key);
        $this->say(sprintf('%d error rows moved back to pending.', $n));

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function resume(array $args, array $assoc): int
    {
        $this->plugin->state->clearPause();
        $this->say('Resumed. The next drain will send again.');

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function prune(array $args, array $assoc): int
    {
        $model = isset($assoc['model']) && is_string($assoc['model']) ? $assoc['model'] : '';
        if ($model === '') {
            $this->say('Usage: wp post-embeddings prune --model=<key> [--yes]');

            return 1;
        }
        if ($model === $this->plugin->providers->modelKey()) {
            $this->say('Error: refusing to prune the current model key.');

            return 1;
        }
        if (empty($assoc['yes']) && !($this->confirm)(sprintf('Remove every index row of %s?', $model))) {
            $this->say('Cancelled.');

            return 0;
        }
        $n = $this->plugin->store->pruneModel($model);
        $this->say(sprintf('Removed %d rows of %s.', $n, $model));

        return 0;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function kill(array $args, array $assoc): int
    {
        $mode = $args[0] ?? '';
        if (!in_array($mode, ['on', 'off'], true)) {
            $this->say('Usage: wp post-embeddings kill on|off');

            return 1;
        }
        $this->plugin->settings->save(['enabled' => $mode === 'off'], $this->plugin->state);
        $this->say('Kill switch setting: ' . $mode . '.');
        if (\IndianOpinion\PostEmbeddings\Settings::constantDisabled()) {
            $this->say('Note: POST_EMBEDDINGS_DISABLED is set in wp-config.php and wins over this setting.');
        }

        return 0;
    }
}
