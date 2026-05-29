/**
 * Manager Core - EVE Online Formatting Utilities
 *
 * Include via: <script src="{{ asset('vendor/manager-core/js/eve-formatting.js') }}"></script>
 *
 * Usage:
 *   ManagerCore.formatISK(1234567890)     // "1.2B ISK"
 *   ManagerCore.formatVolume(45678.12)    // "45,678.12 m³"
 *   ManagerCore.formatNumber(1234567)     // "1,234,567"
 *   ManagerCore.typeIconUrl(34)           // "https://images.evetech.net/types/34/icon?size=64"
 */
(function (global) {
    'use strict';

    var MC = global.ManagerCore = global.ManagerCore || {};

    /**
     * Format ISK value with B/M/K/T suffix
     * @param {number} value
     * @param {number} [decimals=2]
     * @param {boolean} [suffix=true] - Append ' ISK'
     * @returns {string}
     */
    MC.formatISK = function (value, decimals, suffix) {
        if (decimals === undefined) decimals = 2;
        if (suffix === undefined) suffix = true;
        var s = suffix ? ' ISK' : '';
        var abs = Math.abs(value);

        if (abs >= 1e12) return (value / 1e12).toFixed(decimals) + 'T' + s;
        if (abs >= 1e9)  return (value / 1e9).toFixed(decimals) + 'B' + s;
        if (abs >= 1e6)  return (value / 1e6).toFixed(decimals) + 'M' + s;
        if (abs >= 1e3)  return (value / 1e3).toFixed(decimals) + 'K' + s;
        return MC.formatNumber(value, 0) + s;
    };

    /**
     * Format ISK value with full precision (no abbreviation)
     * @param {number} value
     * @param {number} [decimals=2]
     * @param {boolean} [suffix=true]
     * @returns {string}
     */
    MC.formatISKFull = function (value, decimals, suffix) {
        if (decimals === undefined) decimals = 2;
        if (suffix === undefined) suffix = true;
        var s = suffix ? ' ISK' : '';
        return MC.formatNumber(value, decimals) + s;
    };

    /**
     * Format volume in m3
     * @param {number} volume
     * @param {number} [decimals=2]
     * @returns {string}
     */
    MC.formatVolume = function (volume, decimals) {
        if (decimals === undefined) decimals = 2;
        return MC.formatNumber(volume, decimals) + ' m\u00B3';
    };

    /**
     * Format a number with thousand separators
     * @param {number} value
     * @param {number} [decimals=0]
     * @returns {string}
     */
    MC.formatNumber = function (value, decimals) {
        if (decimals === undefined) decimals = 0;
        return Number(value).toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    };

    /**
     * Format quantity with K/M/B suffix
     * @param {number} value
     * @param {number} [decimals=1]
     * @returns {string}
     */
    MC.formatQuantity = function (value, decimals) {
        if (decimals === undefined) decimals = 1;
        var abs = Math.abs(value);

        if (abs >= 1e9) return (value / 1e9).toFixed(decimals) + 'B';
        if (abs >= 1e6) return (value / 1e6).toFixed(decimals) + 'M';
        if (abs >= 1e3) return (value / 1e3).toFixed(decimals) + 'K';
        return MC.formatNumber(value, 0);
    };

    /**
     * Generate EVE type icon URL
     * @param {number} typeId
     * @param {string} [variation='icon'] - 'icon', 'render', 'bp', 'bpc'
     * @param {number} [size=64] - 32, 64, 128, 256, 512
     * @returns {string}
     */
    MC.typeIconUrl = function (typeId, variation, size) {
        if (!variation) variation = 'icon';
        if (!size) size = 64;
        return 'https://images.evetech.net/types/' + typeId + '/' + variation + '?size=' + size;
    };

})(typeof window !== 'undefined' ? window : this);
