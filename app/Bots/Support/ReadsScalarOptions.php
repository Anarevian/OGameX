<?php

namespace OGame\Bots\Support;

/**
 * Reads console options that may arrive as something other than a string.
 *
 * Options typed on the command line are always strings, but Artisan::call() and the
 * $this->artisan() test helper pass PHP values through untouched, so `['--count' => 1]` arrives
 * as an int. Guarding with is_string() therefore silently ignores the option whenever a command
 * is invoked programmatically, and the command falls back to its default — which is how a
 * "spawn one bot" call in a test ended up spawning the configured population of 200.
 */
trait ReadsScalarOptions
{
    /**
     * Get an option as a non-empty string, or null when it was not supplied.
     *
     * Booleans are treated as absent: they belong to flag options such as --force, which are
     * read directly rather than through this helper.
     */
    protected function scalarOption(string $name): string|null
    {
        /** @var mixed $value */
        $value = $this->option($name);

        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * Get an option as an integer, or null when it was not supplied.
     */
    protected function intOption(string $name): int|null
    {
        $value = $this->scalarOption($name);

        return $value === null ? null : (int) $value;
    }

    /**
     * Get an option as a float, or null when it was not supplied.
     */
    protected function floatOption(string $name): float|null
    {
        $value = $this->scalarOption($name);

        return $value === null ? null : (float) $value;
    }
}
