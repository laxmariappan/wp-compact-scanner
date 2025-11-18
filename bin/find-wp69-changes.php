#!/usr/bin/env php
<?php
/**
 * Find WordPress 6.9 Changes
 * 
 * Compares WordPress compatibility maps to find new/changed symbols in 6.9
 * 
 * Usage:
 *   php find-wp69-changes.php [--baseline=6.8] [--target=6.9-RC1]
 */

$baseline_version = '6.8';
$target_version = '6.9-RC1';

// Parse command line arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--baseline=') === 0) {
        $baseline_version = substr($arg, 11);
    } elseif (strpos($arg, '--target=') === 0) {
        $target_version = substr($arg, 9);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php find-wp69-changes.php [--baseline=6.8] [--target=6.9-RC1]\n";
        echo "\n";
        echo "Options:\n";
        echo "  --baseline=VERSION  Baseline WordPress version (default: 6.8)\n";
        echo "  --target=VERSION   Target WordPress version (default: 6.9-RC1)\n";
        echo "  --help, -h          Show this help message\n";
        exit(0);
    }
}

$plugin_dir = dirname(__DIR__);
$baseline_map = $plugin_dir . "/wp-since-{$baseline_version}.json";
$target_map = $plugin_dir . "/wp-since-{$target_version}.json";

// Check if maps exist
if (!file_exists($baseline_map)) {
    echo "❌ Baseline map not found: {$baseline_map}\n";
    echo "💡 Generate it with: wp compat-scanner generate_map --version={$baseline_version}\n";
    exit(1);
}

if (!file_exists($target_map)) {
    echo "❌ Target map not found: {$target_map}\n";
    echo "💡 Generate it with: wp compat-scanner generate_map --version={$target_version}\n";
    exit(1);
}

// Load maps
$baseline_data = json_decode(file_get_contents($baseline_map), true);
$target_data = json_decode(file_get_contents($target_map), true);

if (!$baseline_data || !$target_data) {
    echo "❌ Failed to load compatibility maps\n";
    exit(1);
}

echo "🔍 Comparing WordPress {$baseline_version} → {$target_version}\n";
echo str_repeat('=', 60) . "\n\n";

// Find new symbols in target version
$new_symbols = [];
foreach ($target_data as $symbol => $data) {
    if (!isset($baseline_data[$symbol])) {
        $new_symbols[$symbol] = $data;
    }
}

// Find removed symbols
$removed_symbols = [];
foreach ($baseline_data as $symbol => $data) {
    if (!isset($target_data[$symbol])) {
        $removed_symbols[$symbol] = $data;
    }
}

// Find changed symbols (deprecated status changed)
$newly_deprecated = [];
foreach ($target_data as $symbol => $target_info) {
    if (isset($baseline_data[$symbol])) {
        $baseline_info = $baseline_data[$symbol];
        
        // Check if newly deprecated
        if (empty($baseline_info['deprecated']) && !empty($target_info['deprecated'])) {
            $newly_deprecated[$symbol] = $target_info;
        }
    }
}

// Find version changes (symbol introduced in different version)
$version_changes = [];
foreach ($target_data as $symbol => $target_info) {
    if (isset($baseline_data[$symbol])) {
        $baseline_info = $baseline_data[$symbol];
        
        // Check if version changed (shouldn't happen, but check anyway)
        if ($baseline_info['since'] !== $target_info['since']) {
            $version_changes[$symbol] = [
                'baseline' => $baseline_info['since'],
                'target' => $target_info['since'],
            ];
        }
    }
}

// Display results
echo "📊 Summary:\n";
echo "   New symbols: " . count($new_symbols) . "\n";
echo "   Removed symbols: " . count($removed_symbols) . "\n";
echo "   Newly deprecated: " . count($newly_deprecated) . "\n";
echo "   Version changes: " . count($version_changes) . "\n";
echo "\n";

// Show new symbols
if (!empty($new_symbols)) {
    echo "✨ New Symbols in {$target_version}:\n";
    echo str_repeat('-', 60) . "\n";
    
    // Group by type
    $by_type = [];
    foreach ($new_symbols as $symbol => $data) {
        $type = $data['type'] ?? 'unknown';
        if (!isset($by_type[$type])) {
            $by_type[$type] = [];
        }
        $by_type[$type][$symbol] = $data;
    }
    
    foreach ($by_type as $type => $symbols) {
        echo "\n{$type}:\n";
        foreach ($symbols as $symbol => $data) {
            echo "  • {$symbol} (since {$data['since']})\n";
        }
    }
    echo "\n";
}

// Show removed symbols
if (!empty($removed_symbols)) {
    echo "🗑️  Removed Symbols (in {$baseline_version} but not in {$target_version}):\n";
    echo str_repeat('-', 60) . "\n";
    
    foreach ($removed_symbols as $symbol => $data) {
        echo "  • {$symbol} (was since {$data['since']})\n";
    }
    echo "\n";
}

// Show newly deprecated
if (!empty($newly_deprecated)) {
    echo "⚠️  Newly Deprecated Symbols:\n";
    echo str_repeat('-', 60) . "\n";
    
    foreach ($newly_deprecated as $symbol => $data) {
        echo "  • {$symbol} (deprecated in {$data['deprecated']})\n";
        echo "    Type: {$data['type']}\n";
        if (!empty($data['file'])) {
            echo "    File: {$data['file']}\n";
        }
    }
    echo "\n";
}

// Show version changes
if (!empty($version_changes)) {
    echo "🔄 Version Changes:\n";
    echo str_repeat('-', 60) . "\n";
    
    foreach ($version_changes as $symbol => $versions) {
        echo "  • {$symbol}: {$versions['baseline']} → {$versions['target']}\n";
    }
    echo "\n";
}

// Export to file
$output_file = $plugin_dir . "/wp69-changes-{$baseline_version}-to-{$target_version}.json";
$output = [
    'baseline_version' => $baseline_version,
    'target_version' => $target_version,
    'summary' => [
        'new_symbols' => count($new_symbols),
        'removed_symbols' => count($removed_symbols),
        'newly_deprecated' => count($newly_deprecated),
        'version_changes' => count($version_changes),
    ],
    'new_symbols' => $new_symbols,
    'removed_symbols' => $removed_symbols,
    'newly_deprecated' => $newly_deprecated,
    'version_changes' => $version_changes,
];

file_put_contents($output_file, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "💾 Results saved to: {$output_file}\n";

echo "\n✅ Analysis complete!\n";

