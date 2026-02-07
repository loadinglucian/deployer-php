<?php

declare(strict_types=1);

namespace DeployerPHP\Enums;

/**
 * Canonical WWW handling modes for site configuration.
 */
enum WwwMode: string
{
    case REDIRECT_TO_ROOT = 'redirect-to-root';
    case REDIRECT_TO_WWW = 'redirect-to-www';
    case NONE = 'none';
    case UNKNOWN = 'unknown';

    /**
     * Get all WWW mode values.
     *
     * @return array<int, string>
     */
    public static function values(bool $includeUnknown = true): array
    {
        $values = [
            self::REDIRECT_TO_ROOT->value,
            self::REDIRECT_TO_WWW->value,
            self::NONE->value,
        ];

        if ($includeUnknown) {
            $values[] = self::UNKNOWN->value;
        }

        return $values;
    }

    /**
     * Get user-selectable WWW modes for CLI prompts/options.
     *
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        return [
            self::REDIRECT_TO_ROOT->value => 'Redirect www to non-www',
            self::REDIRECT_TO_WWW->value => 'Redirect non-www to www',
            self::NONE->value => 'Do not configure a www alias',
        ];
    }

    /**
     * Check if the provided mode value is valid.
     */
    public static function isValid(string $value, bool $includeUnknown = true): bool
    {
        return in_array($value, self::values($includeUnknown), true);
    }

    /**
     * Check if the mode is a selectable user-facing value.
     */
    public static function isSelectable(string $value): bool
    {
        return array_key_exists($value, self::selectableOptions());
    }

    /**
     * Whether this mode should configure a WWW alias.
     */
    public function hasWwwAlias(): bool
    {
        return in_array($this, [self::REDIRECT_TO_ROOT, self::REDIRECT_TO_WWW], true);
    }
}
