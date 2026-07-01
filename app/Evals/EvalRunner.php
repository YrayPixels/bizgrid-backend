<?php

namespace App\Evals;

use App\Agents\AgentRegistry;

class EvalRunner
{
    public function __construct(
        private readonly AgentRegistry $registry,
    ) {}

    /**
     * Run eval cases for a specific agent.
     *
     * @param  list<EvalCase>  $cases
     */
    public function run(string $agentName, array $cases): EvalReport
    {
        $report = new EvalReport;

        if (! $this->registry->has($agentName)) {
            foreach ($cases as $case) {
                $report->addResult($case->name, false, "Agent [{$agentName}] not registered.", 0);
            }

            return $report;
        }

        $agent = $this->registry->agent($agentName);

        foreach ($cases as $case) {
            $start = microtime(true);

            try {
                $result = $agent->execute($case->input);

                if ($result === null) {
                    $duration = (microtime(true) - $start) * 1000;
                    $report->addResult($case->name, false, 'Agent returned null (AI may be unavailable).', $duration);

                    continue;
                }

                $passed = true;
                $messages = [];

                // Assert expected keys/values
                foreach ($case->expected as $key => $expectedValue) {
                    $actualValue = $result[$key] ?? null;

                    if ($actualValue !== $expectedValue) {
                        $passed = false;
                        $messages[] = "Expected {$key} = ".json_encode($expectedValue).', got '.json_encode($actualValue);
                    }
                }

                // Run custom assertions
                foreach ($case->assertions as $label => $assertion) {
                    if (! $assertion($result)) {
                        $passed = false;
                        $messages[] = "Assertion [{$label}] failed.";
                    }
                }

                $duration = (microtime(true) - $start) * 1000;
                $report->addResult(
                    $case->name,
                    $passed,
                    $passed ? 'OK' : implode('; ', $messages),
                    $duration,
                );
            } catch (\Throwable $e) {
                $duration = (microtime(true) - $start) * 1000;
                $report->addResult($case->name, false, "Exception: {$e->getMessage()}", $duration);
            }
        }

        return $report;
    }
}
