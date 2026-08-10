<?php

namespace App\Jobs;

use App\Models\TryOnSession;
use App\Services\TryOnService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollTryOnSessionStatus implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $sessionId,
        public int $attempt = 1,
    ) {}

    public function handle(TryOnService $tryOn): void
    {
        $session = TryOnSession::query()->find($this->sessionId);
        if (! $session instanceof TryOnSession) {
            return;
        }

        if ($session->isTerminal()) {
            return;
        }

        $session = $tryOn->refreshSession($session);

        if ($session->isTerminal()) {
            return;
        }

        $maxAttempts = (int) config('perfectcorp.max_poll_attempts', 40);
        if ($this->attempt >= $maxAttempts) {
            $session->update([
                'status' => 'error',
                'error_code' => 'timeout',
                'error_message' => 'Still working — refresh or try again in a moment.',
            ]);

            return;
        }

        $delay = (int) config('perfectcorp.poll_interval_seconds', 3);
        self::dispatch($this->sessionId, $this->attempt + 1)
            ->delay(now()->addSeconds(max(1, $delay)));
    }
}
