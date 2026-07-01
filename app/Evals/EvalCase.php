<?php

namespace App\Evals;

class EvalCase
{
    /**
     * @param  array<string, mixed>  $input      The input context for the agent
     * @param  array<string, mixed>  $expected   Expected output shape (partial match)
     * @param  array<string, callable>  $assertions  Custom assertion callbacks
     */
    public function __construct(
        public readonly string $name,
        public readonly array $input,
        public readonly array $expected = [],
        public readonly array $assertions = [],
    ) {}
}
