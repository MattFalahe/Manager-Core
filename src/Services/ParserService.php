<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Sde\InvType;

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

        // EFT first — its `[ShipType, FittingName]` header is a unique signature
        // that no other format produces. Match wins immediately and keeps the
        // ship hull as item #1 (which the legacy parser chain silently dropped).
        $parsedResult = $this->tryEftParser($lines);
        if ($parsedResult['success']) {
            return [
                'items' => $parsedResult['items'],
                'unparsed' => $parsedResult['unparsed'],
                'parser' => 'eft',
                'success' => !empty($parsedResult['items']),
            ];
        }

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
                $parsedResult = $this->tryNameQuantityParser($lines);
                if ($parsedResult['success']) {
                    $items = $parsedResult['items'];
                    $unparsedLines = $parsedResult['unparsed'];
                    $parserUsed = 'name_quantity';
                } else {
                    $parsedResult = $this->tryListingParser($lines);
                    if ($parsedResult['success']) {
                        $items = $parsedResult['items'];
                        $unparsedLines = $parsedResult['unparsed'];
                        $parserUsed = 'listing';
                    }
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
     * Try to parse as an EFT fitting export.
     *
     * Format:
     *
     *   [ShipType, FittingName]
     *   <module>
     *   <module>
     *   (blank line separates sections: high / mid / low / rigs / subsystems / drones / cargo)
     *   <module>
     *
     * Rules implemented:
     *  - First non-empty line MUST match `[ShipType, FittingName]`; if it
     *    doesn't we return success=false and the legacy parser chain takes
     *    over so we don't hijack non-EFT input that just happens to start
     *    with a square-bracket line.
     *  - Ship hull is the first emitted item (qty 1).
     *  - Repeated module lines are aggregated (6 lines of "Cap Recharger II"
     *    -> one item with qty 6).
     *  - EFT empty-slot markers like "[Empty High slot]" are skipped.
     *  - "Module, Charge" lines (e.g. "Heavy Missile Launcher II, Scourge
     *    Fury Heavy Missile") emit BOTH items, each counted once per line.
     *  - "Item Name xN" qty suffix is honored.
     *
     * @param array $lines
     * @return array
     */
    protected function tryEftParser($lines)
    {
        // Find the first non-empty line and verify it's an EFT header.
        $firstIdx = null;
        $firstLine = null;
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $firstIdx = $idx;
                $firstLine = $trimmed;
                break;
            }
        }

        if ($firstLine === null) {
            return ['success' => false, 'items' => [], 'unparsed' => $lines];
        }

        // [Ship, Fit Name] — capture group 1 = ship type. Permissive on the
        // fitting-name half (allow commas, apostrophes etc.) but strict on
        // the ship-type half: no commas, no closing bracket.
        if (!preg_match('/^\[([^,\]]+),\s*[^\]]*\]$/u', $firstLine, $m)) {
            return ['success' => false, 'items' => [], 'unparsed' => $lines];
        }

        $shipName = trim($m[1]);
        $itemCounts = [$shipName => 1];

        foreach (array_slice($lines, $firstIdx + 1) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip any other [bracketed] non-item lines (slot markers, etc.)
            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                continue;
            }

            // "Module, Charge" — split on the first comma. If the right side
            // looks like a name (doesn't start with a digit), emit both.
            $commaPos = strpos($line, ',');
            if ($commaPos !== false) {
                $modulePart = trim(substr($line, 0, $commaPos));
                $chargePart = trim(substr($line, $commaPos + 1));
                if ($modulePart !== '' && $chargePart !== '' && !preg_match('/^\d/', $chargePart)) {
                    $itemCounts[$modulePart] = ($itemCounts[$modulePart] ?? 0) + 1;
                    $itemCounts[$chargePart] = ($itemCounts[$chargePart] ?? 0) + 1;
                    continue;
                }
            }

            // "Item Name xN" qty suffix
            if (preg_match('/^(.+?)\s+x\s*(\d+)\s*$/iu', $line, $matches)) {
                $name = trim($matches[1]);
                $qty = (int) $matches[2];
            } else {
                $name = $line;
                $qty = 1;
            }

            if ($name !== '') {
                $itemCounts[$name] = ($itemCounts[$name] ?? 0) + $qty;
            }
        }

        $items = [];
        foreach ($itemCounts as $name => $qty) {
            $items[] = ['name' => $name, 'quantity' => $qty];
        }

        return [
            'success' => true,
            'items' => $items,
            'unparsed' => [],
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

            // Split by tabs first
            $parts = explode("\t", $line);

            // If only one part, try splitting by multiple spaces
            if (count($parts) < 2) {
                $parts = preg_split('/\s{2,}/', $line);
            }

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
     * Try to parse as "Name Quantity" format
     * Supports: "Veldspar 1000", "Tritanium x500", "Pyerite x 250"
     *
     * @param array $lines
     * @return array
     */
    protected function tryNameQuantityParser($lines)
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

            // Pattern: "Item Name 1000" or "Item Name x1000" or "Item Name x 1000"
            if (preg_match('/^(.+?)\s+x?\s*([\d,\'\. ]+)$/iu', $line, $matches)) {
                $itemName = trim($matches[1]);
                $quantity = (int) str_replace([',', '.', "'", ' '], '', $matches[2]);

                // Don't match if "name" is purely numeric (that's cargo scan format)
                if (is_numeric(trim($itemName))) {
                    $unparsed[$lineNum] = $line;
                    continue;
                }

                // Check for Blueprint Copy
                $isBPC = false;
                if (str_ends_with($itemName, ' (Copy)')) {
                    $isBPC = true;
                    $itemName = substr($itemName, 0, -7);
                }

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
     * Validate item names against SDE
     *
     * Uses batch queries instead of per-item lookups to avoid N+1 performance issues.
     *
     * @param array $items Array of items with 'name', 'quantity', 'is_bpc', 'line'
     * @return array ['valid' => [], 'invalid' => []]
     */
    public function validateItems($items)
    {
        $validated = [
            'valid' => [],
            'invalid' => [],
        ];

        if (empty($items)) {
            return $validated;
        }

        // Collect all names we need to look up (originals + Blueprint variants for BPCs)
        $allNames = [];
        $blueprintNames = [];

        foreach ($items as $item) {
            $name = trim($item['name']);
            $allNames[] = $name;

            if (isset($item['is_bpc']) && $item['is_bpc']) {
                $bpName = $name . ' Blueprint';
                $allNames[] = $bpName;
                $blueprintNames[$name] = $bpName;
            }
        }

        // Batch fetch all matching types in one query (case-insensitive)
        $lowerNames = array_map('strtolower', array_unique($allNames));
        $types = InvType::with('group')
            ->whereRaw('LOWER(typeName) IN (' . implode(',', array_fill(0, count($lowerNames), '?')) . ')', $lowerNames)
            ->get();

        // Build lookup maps: lowercase name -> type model
        $exactMap = [];
        $lowerMap = [];
        foreach ($types as $type) {
            $exactMap[$type->typeName] = $type;
            $lowerMap[strtolower($type->typeName)] = $type;
        }

        // Match each item against the pre-fetched results
        foreach ($items as $item) {
            $itemName = trim($item['name']);
            $type = null;

            // Try exact match first
            if (isset($exactMap[$itemName])) {
                $type = $exactMap[$itemName];
            }

            // Try case-insensitive match
            if (!$type && isset($lowerMap[strtolower($itemName)])) {
                $type = $lowerMap[strtolower($itemName)];
            }

            // Try Blueprint suffix for BPCs
            if (!$type && isset($blueprintNames[$itemName])) {
                $bpName = $blueprintNames[$itemName];

                if (isset($exactMap[$bpName])) {
                    $type = $exactMap[$bpName];
                } elseif (isset($lowerMap[strtolower($bpName)])) {
                    $type = $lowerMap[strtolower($bpName)];
                }

                if ($type) {
                    Log::debug("[Manager Core] Found BPC with Blueprint suffix: {$itemName} -> {$type->typeName}");
                }
            }

            if ($type) {
                $item['type_id'] = $type->typeID;
                $item['type_name'] = $type->typeName;
                $item['group_id'] = $type->groupID ?? null;
                $item['category_id'] = $type->group->categoryID ?? null;
                $validated['valid'][] = $item;

                Log::debug("[Manager Core] Validated item: {$itemName} -> Type ID {$type->typeID}");
            } else {
                $validated['invalid'][] = [
                    'name' => $itemName,
                    'quantity' => $item['quantity'],
                    'line' => $item['line'] ?? null,
                    'reason' => 'Item not found in EVE Online database',
                ];

                Log::warning("[Manager Core] Invalid item name: '{$itemName}'");
            }
        }

        Log::info("[Manager Core] Validated " . count($validated['valid']) . " items, " . count($validated['invalid']) . " invalid");

        return $validated;
    }
}
