<?php

namespace ManagerCore\Helpers;

/**
 * EveFormatting - Static helpers for EVE Online value formatting
 *
 * Provides consistent ISK, volume, and number formatting across Manager Core.
 * Other plugins can optionally use these helpers when Manager Core is installed.
 */
class EveFormatting
{
    /**
     * Format ISK value with B/M/K suffix
     *
     * @param float $value
     * @param int $decimals
     * @param bool $suffix Whether to append ' ISK'
     * @return string e.g., "1.23B ISK", "456.78M ISK", "1,234 ISK"
     */
    public static function isk(float $value, int $decimals = 2, bool $suffix = true): string
    {
        $iskSuffix = $suffix ? ' ISK' : '';

        if (abs($value) >= 1e12) {
            return number_format($value / 1e12, $decimals) . 'T' . $iskSuffix;
        }

        if (abs($value) >= 1e9) {
            return number_format($value / 1e9, $decimals) . 'B' . $iskSuffix;
        }

        if (abs($value) >= 1e6) {
            return number_format($value / 1e6, $decimals) . 'M' . $iskSuffix;
        }

        if (abs($value) >= 1e3) {
            return number_format($value / 1e3, $decimals) . 'K' . $iskSuffix;
        }

        return number_format($value, 0) . $iskSuffix;
    }

    /**
     * Format ISK value with full precision (no abbreviation)
     *
     * @param float $value
     * @param int $decimals
     * @param bool $suffix
     * @return string e.g., "1,234,567,890.12 ISK"
     */
    public static function iskFull(float $value, int $decimals = 2, bool $suffix = true): string
    {
        $formatted = number_format($value, $decimals);

        return $suffix ? $formatted . ' ISK' : $formatted;
    }

    /**
     * Format volume in m3
     *
     * @param float $volume
     * @param int $decimals
     * @return string e.g., "45,678.12 m³"
     */
    public static function volume(float $volume, int $decimals = 2): string
    {
        return number_format($volume, $decimals) . " m\u{00B3}";
    }

    /**
     * Format a number with thousand separators
     *
     * @param float $value
     * @param int $decimals
     * @return string
     */
    public static function number(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals);
    }

    /**
     * Format quantity with K/M/B suffix for large numbers
     *
     * @param float $value
     * @param int $decimals
     * @return string e.g., "1.2M", "456K", "789"
     */
    public static function quantity(float $value, int $decimals = 1): string
    {
        if (abs($value) >= 1e9) {
            return number_format($value / 1e9, $decimals) . 'B';
        }

        if (abs($value) >= 1e6) {
            return number_format($value / 1e6, $decimals) . 'M';
        }

        if (abs($value) >= 1e3) {
            return number_format($value / 1e3, $decimals) . 'K';
        }

        return number_format($value, 0);
    }

    /**
     * Format a type ID + name for display with icon
     *
     * @param int $typeId
     * @param string $typeName
     * @param int $iconSize
     * @return string HTML with img tag and name span
     */
    public static function typeDisplay(int $typeId, string $typeName, int $iconSize = 32): string
    {
        $iconUrl = "https://images.evetech.net/types/{$typeId}/icon?size={$iconSize}";
        $escapedName = e($typeName);

        return "<img src=\"{$iconUrl}\" width=\"{$iconSize}\" height=\"{$iconSize}\" "
             . "alt=\"{$escapedName}\" class=\"img-circle\" style=\"vertical-align: middle; margin-right: 5px;\">"
             . "<span>{$escapedName}</span>";
    }
}
