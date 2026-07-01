<?php

namespace App\Console\Commands;

use App\Agents\AgentRegistry;
use App\Evals\EvalReport;
use App\Evals\EvalRunner;
use Illuminate\Console\Command;

class EvalAgents extends Command
{
    protected $signature = 'agents:eval
                            {agent? : The agent to evaluate (interpreter, design-director, color-specialist, etc.)}
                            {--version= : Prompt version to test}
                            {--all : Run all agent evals}';

    protected $description = 'Run eval cases against AI agents to validate prompt quality.';

    public function handle(EvalRunner $runner): int
    {
        $agents = $this->resolveAgents();

        if ($agents === []) {
            $this->error('No agents selected. Use --all or specify an agent name.');

            return 1;
        }

        $allPassed = true;

        foreach ($agents as $agentName) {
            $fixturePath = $this->fixturePath($agentName);

            if (! file_exists($fixturePath)) {
                $this->warn("No eval fixtures found for agent [{$agentName}] at {$fixturePath}.");

                continue;
            }

            $this->info("\nRunning evals for agent: {$agentName}");
            $this->line(str_repeat('-', 60));

            $cases = require $fixturePath;

            if (! is_array($cases)) {
                $this->error("Fixture file for [{$agentName}] did not return an array.");

                continue;
            }

            $report = $runner->run($agentName, $cases);
            $this->renderReport($agentName, $report);

            if ($report->failed > 0) {
                $allPassed = false;
            }
        }

        return $allPassed ? 0 : 1;
    }

    /**
     * @return list<string>
     */
    private function resolveAgents(): array
    {
        if ($this->option('all')) {
            return ['interpreter', 'design-director'];
        }

        $agent = $this->argument('agent');

        if ($agent !== null) {
            return [$agent];
        }

        return [];
    }

    private function fixturePath(string $agentName): string
    {
        return app_path("Evals/Fixtures/{$agentName}_cases.php");
    }

    private function renderReport(string $agentName, EvalReport $report): void
    {
        foreach ($report->results as $result) {
            $icon = $result['passed'] ? '✓' : '✗';
            $line = "  {$icon} {$result['case']}";
            $line .= " ({$result['duration_ms']}ms)";

            if (! $result['passed']) {
                $line .= " — {$result['message']}";
            }

            if ($result['passed']) {
                $this->info($line);
            } else {
                $this->error($line);
            }
        }

        $this->line('');
        $this->info("  Total: {$report->passed} passed, {$report->failed} failed ({$report->passRate()}%)");
        $this->line("  Duration: {$report->totalDurationMs}ms");
    }
}
