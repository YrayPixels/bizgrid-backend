<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PromptService
{
    /**
     * Base path for prompt files.
     */
    private string $basePath;

    public function __construct()
    {
        $this->basePath = config('prompts.path', base_path('prompts'));
    }

    /**
     * Load a prompt for an agent at a given version.
     */
    public function load(string $agentName, string $version = 'v1'): string
    {
        $path = $this->promptPath($agentName, $version);

        if (! File::exists($path)) {
            Log::warning("Prompt file not found: {$path}");

            return '';
        }

        $content = File::get($path);

        return trim($content);
    }

    /**
     * Load a prompt template and substitute variables.
     *
     * @param  array<string, string>  $variables
     */
    public function render(string $agentName, array $variables = [], string $version = 'v1'): string
    {
        $template = $this->load($agentName, $version);

        foreach ($variables as $key => $value) {
            $template = str_replace("{{ \${key} }}", $value, $template);
        }

        return $template;
    }

    /**
     * Get the latest version for an agent's prompts.
     */
    public function latestVersion(string $agentName): string
    {
        $dir = $this->promptDir($agentName);

        if (! File::isDirectory($dir)) {
            return 'v1';
        }

        $files = File::files($dir);
        $versions = [];

        foreach ($files as $file) {
            if (preg_match('/^v(\d+)\.txt$/', $file->getFilename(), $matches)) {
                $versions[] = (int) $matches[1];
            }
        }

        if ($versions === []) {
            return 'v1';
        }

        return 'v'.max($versions);
    }

    /**
     * List available versions for an agent.
     *
     * @return list<string>
     */
    public function versions(string $agentName): array
    {
        $dir = $this->promptDir($agentName);

        if (! File::isDirectory($dir)) {
            return [];
        }

        $versions = [];

        foreach (File::files($dir) as $file) {
            if (preg_match('/^(v\d+)\.txt$/', $file->getFilename(), $matches)) {
                $versions[] = $matches[1];
            }
        }

        sort($versions);

        return $versions;
    }

    private function promptDir(string $agentName): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$agentName;
    }

    private function promptPath(string $agentName, string $version): string
    {
        return $this->promptDir($agentName).DIRECTORY_SEPARATOR.$version.'.txt';
    }
}
