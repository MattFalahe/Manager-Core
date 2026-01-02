<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Log;

/**
 * ParserService - Parses various EVE Online data formats
 *
 * Based on go-evepraisal's parser system
 * Supports: cargo scans, assets, contracts, killmails, etc.
 */
class ParserService
{
    /**
     * Available parsers
     *
     * @var array
     */
    protected $parsers = [];

    /**
     * Constructor - Register all parsers
     */
    public function __construct()
    {
        $this->registerParsers();
    }

    /**
     * Register all available parsers
     *
     * @return void
     */
    protected function registerParsers()
    {
        // Parsers will be implemented as separate classes
        // For now, we'll use simple regex-based parsing
    }

    /**
     * Parse input text and return items
     *
     * @param string $input
     * @return array
     */
    public function parse($input)
    {
        $lines = explode("\n", trim($input));
        $items = [];
        $unparsedLines = [];
        $parserUsed = 'unknown';

        // Try each parser in order of likelihood
        $parsedResult = $this->tryCargoScanParser($lines);
        if ($parsedResult['success']) {
            $items = $parsedResult['items'];
            $unparsedLines = $parsedResult['unparsed'];
            $parserUsed = 'cargo_scan';
        } else {
            $parsedResult = $this->tryAssetParser($lines);
            if ($parsedResult['success']) {
                $items = $parsedResult['items'];
                $unparsedLines = $parsedResult['unparsed'];
                $parserUsed = 'assets';
            } else {
                $parsedResult = $this->tryListingParser($lines);
                if ($parsedResult['success']) {
                    $items = $parsedResult['items'];
                    $unparsedLines = $parsedResult['unparsed'];
                    $parserUsed = 'listing';
                }
            }
        }

        return [
            'items' => $items,
            'unparsed' => $unparsedLines,
            'parser' => $parserUsed,
            'success' => !empty($items),
        ];
    }

    /**
     * Try to parse as cargo scan format
     * Format: "1,234 Item Name" or "1234 Item Name"
     *
     * @param array $lines
     * @return array
     */
    protected function tryCargoScanParser($lines)
    {
        $items = [];
        $unparsed = [];
        $lineNum = 0;

        foreach ($lines as $line) {
            $lineNum++;
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Regex: Number (with optional comma/dot separators) followed by item name
            if (preg_match('/^([\d,\'\. ]+)\s+(.+)$/u', $line, $matches)) {
                $quantity = (int) str_replace([',', '.', "'", ' '], '', $matches[1]);
                $itemName = trim($matches[2]);

                // Check for Blueprint Copy
                $isBPC = false;
                if (str_ends_with($itemName, ' (Copy)')) {
                    $isBPC = true;
                    $itemName = substr($itemName, 0, -7); // Remove " (Copy)"
                }

                // Remove " (Original)" if present
                $itemName = str_replace(' (Original)', '', $itemName);

                if ($quantity > 0 && !empty($itemName)) {
                    $items[] = [
                        'name' => $itemName,
                        'quantity' => $quantity,
                        'is_bpc' => $isBPC,
                        'line' => $lineNum,
                    ];
                    continue;
                }
            }

            $unparsed[$lineNum] = $line;
        }

        return [
            'success' => !empty($items),
            'items' => $this->consolidateItems($items),
            'unparsed' => $unparsed,
        ];
    }

    /**
     * Try to parse as asset list format
     * Format: "Item Name\tQuantity\t..." (tab-separated)
     *
     * @param array $lines
     * @return array
     */
    protected function tryAssetParser($lines)
    {
        $items = [];
        $unparsed = [];
        $lineNum = 0;

        foreach ($lines as $line) {
            $lineNum++;
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Split by tabs
            $parts = explode("\t", $line);

            if (count($parts) >= 2) {
                $itemName = trim($parts[0]);
                $quantity = (int) str_replace([',', '.', "'"], '', $parts[1]);

                if ($quantity === 0) {
                    $quantity = 1;
                }

                if (!empty($itemName)) {
                    $items[] = [
                        'name' => $itemName,
                        'quantity' => $quantity,
                        'is_bpc' => false,
                        'line' => $lineNum,
                    ];
                    continue;
                }
            }

            $unparsed[$lineNum] = $line;
        }

        return [
            'success' => !empty($items),
            'items' => $this->consolidateItems($items),
            'unparsed' => $unparsed,
        ];
    }

    /**
     * Try to parse as simple listing format
     * Format: Just item names, one per line
     *
     * @param array $lines
     * @return array
     */
    protected function tryListingParser($lines)
    {
        $items = [];
        $unparsed = [];
        $lineNum = 0;

        foreach ($lines as $line) {
            $lineNum++;
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Simple item name
            if (strlen($line) > 2 && strlen($line) < 200) {
                $items[] = [
                    'name' => $line,
                    'quantity' => 1,
                    'is_bpc' => false,
                    'line' => $lineNum,
                ];
            } else {
                $unparsed[$lineNum] = $line;
            }
        }

        return [
            'success' => !empty($items) && count($items) > count($unparsed),
            'items' => $this->consolidateItems($items),
            'unparsed' => $unparsed,
        ];
    }

    /**
     * Consolidate duplicate items
     *
     * @param array $items
     * @return array
     */
    protected function consolidateItems($items)
    {
        $consolidated = [];

        foreach ($items as $item) {
            $key = $item['name'] . ($item['is_bpc'] ? '_bpc' : '');

            if (isset($consolidated[$key])) {
                $consolidated[$key]['quantity'] += $item['quantity'];
            } else {
                $consolidated[$key] = $item;
            }
        }

        return array_values($consolidated);
    }

    /**
     * Validate item names against SDE (future implementation)
     *
     * @param array $items
     * @return array
     */
    public function validateItems($items)
    {
        // TODO: Validate against universe_types table or use SeAT's type service
        return $items;
    }
}
