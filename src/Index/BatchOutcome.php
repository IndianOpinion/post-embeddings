<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

/** What one batch did. `stop` names why the caller must stop, if it must. */
final class BatchOutcome
{
    public int $sent = 0;
    public int $stored = 0;
    public int $errors = 0;
    public int $retried = 0;
    public int $tokens = 0;
    public int $calls = 0;
    public ?string $stop = null;

    public function merge(self $other): void
    {
        $this->sent += $other->sent;
        $this->stored += $other->stored;
        $this->errors += $other->errors;
        $this->retried += $other->retried;
        $this->tokens += $other->tokens;
        $this->calls += $other->calls;
        $this->stop ??= $other->stop;
    }
}
