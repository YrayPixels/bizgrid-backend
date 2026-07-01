<?php

namespace App\Evals;

class EvalReport
{
    /** @var list<array{case: string, passed: bool, message: string, duration_ms: float}> */
    public array $results = [];

    public int $passed = 0;
    public int $failed = 0;
    public float $totalDurationMs = 0;

    public function addResult(string $caseName, bool $passed, string $message, float $durationMs): void
    {
        $this->results[] = [
            'case' => $caseName,
            'passed' => $passed,
            'message' => $message,
            'duration_ms' => $durationMs,
        ];

        if ($passed) {
            $this->passed++;
        } else {
            $this->failed++;
        }

        $this->totalDurationMs += $durationMs;
    }

    public function passRate(): float
    {
        $total = $this->passed + $this->failed;

        return $total > 0 ? round(($this->passed / $total) * 100, 1) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'pass_rate' => $this->passRate().'%',
            'total_duration_ms' => $this->totalDurationMs,
            'results' => $this->results,
        ];
    }
}
