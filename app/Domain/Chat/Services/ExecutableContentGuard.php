<?php

namespace App\Domain\Chat\Services;

/**
 * Rejects files that are programs or scripts whatever their name or declared type says.
 * This is a cheap first filter, not a replacement for the virus scanner.
 */
class ExecutableContentGuard
{
    /** @var array<string, string> */
    private const SIGNATURES = [
        'MZ' => 'Windows program',
        "\x7FELF" => 'Linux program',
        "\xFE\xED\xFA\xCE" => 'macOS program',
        "\xFE\xED\xFA\xCF" => 'macOS program',
        "\xCE\xFA\xED\xFE" => 'macOS program',
        "\xCF\xFA\xED\xFE" => 'macOS program',
        "\xCA\xFE\xBA\xBE" => 'Java or universal binary',
        '#!' => 'script',
    ];

    /** Returns a short description when the file is executable content, otherwise null. */
    public function reason(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);
        foreach (self::SIGNATURES as $magic => $label) {
            if (str_starts_with($head, $magic)) {
                return $label;
            }
        }

        return null;
    }
}
