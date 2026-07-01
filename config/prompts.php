<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt File Storage Path
    |--------------------------------------------------------------------------
    |
    | The directory where versioned prompt files are stored. Each agent gets
    | its own subdirectory (e.g. prompts/interpreter/v1.txt).
    |
    */
    'path' => env('PROMPT_PATH', base_path('prompts')),

    /*
    |--------------------------------------------------------------------------
    | Default Prompt Version
    |--------------------------------------------------------------------------
    |
    | The default version to use when no version is specified. Change this
    | to roll out a new prompt version across all agents.
    |
    */
    'default_version' => env('PROMPT_DEFAULT_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Agent-specific version overrides
    |--------------------------------------------------------------------------
    |
    | Pin specific agents to specific versions. Useful for A/B testing
    | or gradual rollouts. e.g. ['interpreter' => 'v2', 'editor' => 'v1']
    |
    */
    'agent_versions' => [],

    /*
    |--------------------------------------------------------------------------
    | Eval Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, AI calls return canned responses from the eval fixtures
    | directory instead of hitting OpenAI. Used for prompt regression testing.
    |
    */
    'eval_mode' => env('PROMPT_EVAL_MODE', false),

];
