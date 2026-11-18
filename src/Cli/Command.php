<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Cli;

use IndianOpinion\PostEmbeddings\Plugin;

/**
 * Manage post embeddings: backfill, drain, status, rebuild, verify, related,
 * search, retry, resume, prune, kill.
 *
 * ## EXAMPLES
 *
 *     wp post-embeddings backfill --cap=200 --dry-run
 *     wp post-embeddings backfill --cap=200 --yes
 *     wp post-embeddings status
 *     wp post-embeddings related 123
 */
final class Command
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $command = array_shift($args) ?? '';
        $runner = new Runner(
            $this->plugin,
            static function (string $line): void {
                \WP_CLI::line($line);
            },
            static function (string $question): bool {
                fwrite(STDOUT, $question . ' [y/n] ');
                $answer = fgets(STDIN);

                return is_string($answer) && strtolower(trim($answer)) === 'y';
            }
        );
        \WP_CLI::halt($runner->run($command, array_values($args), $assoc));
    }
}
