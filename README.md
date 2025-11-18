# WP Compat Scanner

WordPress plugin that scans your installed plugins for compatibility issues with specific WordPress versions. Uses the [wp-since](https://github.com/eduardovillao/wp-since) library to detect deprecated functions, version mismatches, and compatibility problems.

<img width="922" height="268" alt="image" src="https://github.com/user-attachments/assets/d12bc45f-be94-41c2-8f69-ac554e3b36b3" />

## Features

- 🔍 **Scan all plugins** - Check active or all installed plugins at once
- ⚠️ **Detect deprecated code** - Find functions/hooks that may be removed in future WordPress versions
- 📊 **Version validation** - Verify declared minimum WordPress versions match actual usage
- 🆕 **New features detection** - See which new WordPress features your plugins are using
- 🗺️ **Custom compatibility maps** - Generate maps for specific WordPress versions (RC, beta, etc.)
- 🚀 **WP-CLI integration** - Easy command-line interface

## Installation

1. Navigate to the plugin directory:
```bash
cd wp-content/plugins/wp-compat-scanner
```

2. Install Composer dependencies:
```bash
composer install
```

3. Activate the plugin:
```bash
wp plugin activate wp-compat-scanner
```

## Quick Start

### Scan All Active Plugins

```bash
wp compat-scanner scan
```

### Scan All Plugins

```bash
wp compat-scanner scan --all
```

### Scan Specific Plugin

```bash
wp compat-scanner scan --plugin=your-plugin-slug
```

### Check Against WordPress 6.9 RC1

```bash
# Generate compatibility map for 6.9 RC1
wp compat-scanner generate_map --version=6.9-RC1

# Scan plugins against 6.9 RC1
wp compat-scanner scan --since-map=wp-content/plugins/wp-compat-scanner/wp-since-6.9-RC1.json
```

## Usage Examples

### Basic Scan

```bash
wp compat-scanner scan
```

Output:
```
🔍 Scanning 3 plugin(s)...

📦 Plugin: Your Plugin
   ✅ Minimum version declared: 6.7
   ✅ All good! Plugin is compatible with WP 6.7.
```

### Finding Deprecated Functions

```bash
wp compat-scanner scan --all
```

Output:
```
📦 Plugin: Example Plugin
   ✅ Minimum version declared: 5.0
   ✅ All good! Plugin is compatible with WP 5.0.
   
   ⚠️  Using deprecated symbols (may be removed or changed in 6.9):
   Symbol              Deprecated in WP    Type
   clean_url          3.0.0               function
   💡 Consider updating to newer alternatives before WordPress 6.9 release.
```

### Version Mismatch Detection

```bash
wp compat-scanner scan --plugin=my-plugin
```

Output:
```
📦 Plugin: My Plugin
   ✅ Minimum version declared: 5.0
   🚨 Compatibility issues found:
   
   Symbol              Introduced in WP
   register_setting    5.5.0
   
   📌 Suggested version required: 5.5.0
```

## Commands

### `scan`

Scan plugins for compatibility issues.

**Options:**
- `--all` - Scan all installed plugins (not just active)
- `--plugin=<slug>` - Scan a specific plugin by slug
- `--since-map=<path>` - Use custom compatibility map file

**Examples:**
```bash
# Scan active plugins
wp compat-scanner scan

# Scan all plugins
wp compat-scanner scan --all

# Scan specific plugin
wp compat-scanner scan --plugin=akismet

# Use custom map
wp compat-scanner scan --since-map=/path/to/wp-since-6.9.json
```

### `generate_map`

Generate a compatibility map from WordPress source code.

**Options:**
- `--version=<version>` - WordPress version (e.g., 6.9-RC1, 6.9, latest)
- `--output=<path>` - Output path for the generated map
- `--source-dir=<path>` - Use existing WordPress source directory

**Examples:**
```bash
# Generate map for WordPress 6.9 RC1
wp compat-scanner generate_map --version=6.9-RC1

# Use existing WordPress installation
wp compat-scanner generate_map --source-dir=/path/to/wordpress
```

## What It Checks

### ✅ Detects

- **Symbol availability** - Functions/classes/hooks requiring newer WP versions
- **Deprecated symbols** - Functions/hooks marked as deprecated
- **Version mismatches** - When declared version doesn't match usage
- **New features** - Symbols introduced in specific WordPress versions

### ❌ Does NOT Detect

- Breaking changes (function signature changes)
- Behavioral changes (how functions work internally)
- Removed functions (not just deprecated)
- Database schema changes
- JavaScript/API changes

**Note:** Always combine with manual testing for complete compatibility verification.

## How It Works

1. **Scans** your plugin for used WordPress core symbols:
   - Functions
   - Classes
   - Class methods (static and instance)
   - Action and filter hooks

2. **Reads** the declared "Requires at least:" version from your plugin's `readme.txt` or main plugin file header

3. **Compares** those symbols with a version map built from WordPress core using `@since` tags

4. **Reports** any issues:
   - Symbols requiring newer WP version than declared
   - Deprecated symbols that may be removed
   - New WordPress features being used

## Requirements

- PHP 7.4+
- WordPress 5.0+
- WP-CLI
- Composer

## Credits

Uses the [wp-since](https://github.com/eduardovillao/wp-since) library by Eduardo Villão for core compatibility checking functionality.

## License

MIT
