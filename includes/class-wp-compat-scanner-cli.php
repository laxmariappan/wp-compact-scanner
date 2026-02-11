<?php
/**
 * WP-CLI commands for WP Compat Scanner plugin.
 *
 * @package WP_Compat_Scanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command class for compat-scanner.
 */
class WP_Compat_Scanner_CLI {

	/**
	 * Scan plugins for WordPress compatibility issues.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Scan all installed plugins, not just active ones.
	 *
	 * [--plugin=<plugin>]
	 * : Scan a specific plugin by slug.
	 *
	 * [--since-map=<path>]
	 * : Path to custom wp-since.json compatibility map file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan all active plugins
	 *     $ wp compat-scanner scan
	 *
	 *     # Scan all installed plugins
	 *     $ wp compat-scanner scan --all
	 *
	 *     # Scan a specific plugin
	 *     $ wp compat-scanner scan --plugin=akismet
	 *
	 *     # Use custom compatibility map
	 *     $ wp compat-scanner scan --since-map=/path/to/wp-since-6.9.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function scan( $args, $assoc_args ) {
		// Check if wp-since classes are available.
		if ( ! class_exists( '\WP_Since\Checker\CompatibilityChecker' ) ) {
			WP_CLI::error( 'wp-since library not found. Please run: composer install' );
			return;
		}

		// Find wp-since.json file.
		$since_map_path = isset( $assoc_args['since-map'] ) ? $assoc_args['since-map'] : $this->find_since_map();
		if ( ! $since_map_path || ! file_exists( $since_map_path ) ) {
			WP_CLI::error( 'wp-since.json not found. The compatibility map is required.' );
			WP_CLI::log( 'Use --since-map=<path> to specify a custom map, or run: wp compat-scanner generate_map --version=6.9-RC1' );
			return;
		}

		$scan_all = isset( $assoc_args['all'] );
		$plugin_slug = isset( $assoc_args['plugin'] ) ? $assoc_args['plugin'] : null;

		// Get plugins to scan.
		if ( $plugin_slug ) {
			$plugins = $this->get_plugin_by_slug( $plugin_slug );
		} elseif ( $scan_all ) {
			$plugins = $this->get_all_plugins();
		} else {
			$plugins = $this->get_active_plugins();
		}

		if ( empty( $plugins ) ) {
			WP_CLI::warning( 'No plugins found to scan.' );
			return;
		}

		WP_CLI::log( sprintf( '🔍 Scanning %d plugin(s)...', count( $plugins ) ) );
		WP_CLI::log( '' );

		// Load the since map.
		$since_map = json_decode( file_get_contents( $since_map_path ), true );
		if ( ! $since_map ) {
			WP_CLI::error( 'Failed to load wp-since.json map.' );
			return;
		}

		$has_issues = false;
		$results = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$plugin_name = $plugin_data['Name'];

			WP_CLI::log( sprintf( '📦 Plugin: %s', $plugin_name ) );
			WP_CLI::log( sprintf( '   Path: %s', $plugin_path ) );

			try {
				// Try WordPress's get_plugin_data first (more reliable for finding main file).
				$plugin_version = null;
				$version_source = null;
				
				if ( function_exists( 'get_plugin_data' ) ) {
					$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
					if ( ! empty( $plugin_data['RequiresWP'] ) ) {
						$plugin_version = $plugin_data['RequiresWP'];
						$version_source = 'main plugin file header (via get_plugin_data)';
					}
				}
				
				// Fallback to wp-since's VersionResolver.
				if ( ! $plugin_version ) {
					$version_resolver = \WP_Since\Resolver\VersionResolver::resolve( $plugin_path );
					if ( $version_resolver && isset( $version_resolver['version'] ) ) {
						$plugin_version = $version_resolver['version'];
						$version_source = isset( $version_resolver['source'] ) ? $version_resolver['source'] : 'unknown';
					}
				}
				
				if ( ! $plugin_version ) {
					WP_CLI::warning( '   ⚠️  Could not determine minimum required WP version. Skipping...' );
					WP_CLI::log( '   💡 Tip: Add "Requires at least: X.X" to your plugin header or readme.txt' );
					WP_CLI::log( '' );
					continue;
				}

				$declared_version = $plugin_version;
				$source = $version_source;
				WP_CLI::log( sprintf( '   ✅ Minimum version declared: %s (from %s)', $declared_version, $source ) );

				// Scan for used symbols, excluding vendor directories.
				$used_symbols = $this->scan_plugin_excluding_vendor( $plugin_path );

				// Check compatibility (v1.4.0 type-prefixed keys prevent function/hook collisions).
				$checker = new \WP_Since\Checker\CompatibilityChecker( $since_map );
				$incompatible = $checker->check( $used_symbols, $declared_version );

				// Check for symbols introduced in 6.9 RC1 (if checking against 6.9 RC1 map).
				$new_in_69 = $this->get_symbols_introduced_in_version( $used_symbols, $since_map, '6.9' );

				// Check for deprecated symbols that might be removed or changed in 6.9.
				$deprecated_symbols = $this->get_deprecated_symbols( $used_symbols, $since_map, '6.9' );

				if ( ! empty( $incompatible ) ) {
					$has_issues = true;
					$results[ $plugin_name ] = $incompatible;

					WP_CLI::log( '   🚨 Compatibility issues found:' );
					WP_CLI::log( '' );

					// Display issues in a table format.
					$table_data = array();
					foreach ( $incompatible as $symbol => $version ) {
						$table_data[] = array(
							'Symbol' => $this->format_symbol_name( $symbol ),
							'Introduced in WP' => $version,
						);
					}

					WP_CLI\Utils\format_items( 'table', $table_data, array( 'Symbol', 'Introduced in WP' ) );

					// Get suggested version.
					$suggested_version = $this->get_suggested_version( $incompatible, $declared_version );
					if ( $suggested_version ) {
						WP_CLI::log( '' );
						WP_CLI::log( sprintf( '   📌 Suggested version required: %s', $suggested_version ) );
					}
				} else {
					WP_CLI::log( sprintf( '   ✅ All good! Plugin is compatible with WP %s.', $declared_version ) );
				}

				// Show new 6.9 features being used (informational).
				if ( ! empty( $new_in_69 ) ) {
					WP_CLI::log( '' );
					WP_CLI::log( '   ℹ️  Using new WordPress 6.9 features:' );
					$new_table = array();
					foreach ( $new_in_69 as $symbol => $version ) {
						$new_table[] = array(
							'Symbol' => $this->format_symbol_name( $symbol ),
							'Introduced in WP' => $version,
						);
					}
					WP_CLI\Utils\format_items( 'table', $new_table, array( 'Symbol', 'Introduced in WP' ) );
				}

				// Show deprecated symbols that might cause issues in 6.9.
				if ( ! empty( $deprecated_symbols ) ) {
					WP_CLI::log( '' );
					WP_CLI::warning( '   ⚠️  Using deprecated symbols (may be removed or changed in 6.9):' );
					$deprecated_table = array();
					foreach ( $deprecated_symbols as $symbol => $info ) {
						$deprecated_table[] = array(
							'Symbol' => $this->format_symbol_name( $symbol ),
							'Deprecated in WP' => $info['deprecated'],
							'Type' => isset( $info['type'] ) ? $info['type'] : 'unknown',
						);
					}
					WP_CLI\Utils\format_items( 'table', $deprecated_table, array( 'Symbol', 'Deprecated in WP', 'Type' ) );
					WP_CLI::log( '   💡 Consider updating to newer alternatives before WordPress 6.9 release.' );
				}
			} catch ( Exception $e ) {
				WP_CLI::warning( sprintf( '   ⚠️  Error scanning plugin: %s', $e->getMessage() ) );
			}

			WP_CLI::log( '' );
		}

		// Summary.
		if ( $has_issues ) {
			WP_CLI::warning( sprintf( 'Found compatibility issues in %d plugin(s).', count( $results ) ) );
		} else {
			WP_CLI::success( 'All scanned plugins are compatible!' );
		}
	}

	/**
	 * Get active plugins.
	 *
	 * @return array Array of plugin files and data.
	 */
	private function get_active_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins = array();

		foreach ( $active_plugins as $plugin_file ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
			if ( ! empty( $plugin_data['Name'] ) ) {
				$plugins[ $plugin_file ] = $plugin_data;
			}
		}

		return $plugins;
	}

	/**
	 * Get all installed plugins.
	 *
	 * @return array Array of plugin files and data.
	 */
	private function get_all_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = get_plugins();
		return $all_plugins;
	}

	/**
	 * Get a specific plugin by slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return array Array of plugin files and data.
	 */
	private function get_plugin_by_slug( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = get_plugins();
		$plugins = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( dirname( $plugin_file ) === $slug ) {
				$plugins[ $plugin_file ] = $plugin_data;
				break;
			}
		}

		return $plugins;
	}

	/**
	 * Get suggested WordPress version based on issues.
	 *
	 * @param array  $issues          Array of symbol => version.
	 * @param string $declared_version Currently declared version.
	 * @return string|null Suggested version or null.
	 */
	private function get_suggested_version( $issues, $declared_version ) {
		if ( empty( $issues ) ) {
			return null;
		}

		$versions = array_values( $issues );
		$max_version = array_reduce(
			$versions,
			function( $carry, $v ) {
				return \WP_Since\Utils\VersionHelper::compare( $carry, $v ) < 0 ? $v : $carry;
			},
			$declared_version
		);

		return $max_version;
	}

	/**
	 * Generate wp-since.json compatibility map from WordPress source.
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : WordPress version to generate map for (e.g., 6.9-RC1, 6.9, latest).
	 *   Default: latest
	 *
	 * [--output=<path>]
	 * : Output path for the generated wp-since.json file.
	 *   Default: wp-content/plugins/wp-compat-scanner/wp-since-{version}.json
	 *
	 * [--source-dir=<path>]
	 * : Use existing WordPress source directory instead of downloading.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate map for WordPress 6.9 RC1
	 *     $ wp compat-scanner generate_map --version=6.9-RC1
	 *
	 *     # Generate map for latest WordPress
	 *     $ wp compat-scanner generate_map
	 *
	 *     # Use existing WordPress source
	 *     $ wp compat-scanner generate_map --source-dir=/path/to/wordpress
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate_map( $args, $assoc_args ) {
		$version = isset( $assoc_args['version'] ) ? $assoc_args['version'] : 'latest';
		$source_dir = isset( $assoc_args['source-dir'] ) ? $assoc_args['source-dir'] : null;
		$output_path = isset( $assoc_args['output'] ) ? $assoc_args['output'] : null;

		// Check if generate script exists.
		$generate_script = WP_COMPAT_SCANNER_DIR . 'vendor/eduardovillao/wp-since/generate-since-json.php';
		if ( ! file_exists( $generate_script ) ) {
			WP_CLI::error( 'wp-since generate script not found. Please run: composer install' );
			return;
		}

		$temp_dir = null;

		// If source directory not provided, download WordPress.
		if ( ! $source_dir ) {
			WP_CLI::log( sprintf( '📥 Downloading WordPress %s...', $version ) );

			$temp_dir = sys_get_temp_dir() . '/wp-compat-scanner-' . uniqid();
			if ( ! mkdir( $temp_dir, 0755, true ) ) {
				WP_CLI::error( 'Failed to create temporary directory.' );
				return;
			}

			$download_url = $this->get_wordpress_download_url( $version );
			if ( ! $download_url ) {
				WP_CLI::error( sprintf( 'Could not determine download URL for version: %s', $version ) );
				return;
			}

			$zip_path = $temp_dir . '/wordpress.zip';
			WP_CLI::log( sprintf( 'Downloading from: %s', $download_url ) );

			// Download WordPress - try multiple URLs for RC versions.
			$zip_content = false;
			if ( preg_match( '/\b(RC|rc|beta|Beta)\b/i', $version ) ) {
				// Try multiple URL formats for RC versions.
				$version_safe = str_replace( array( ' ', '_' ), '-', $version );
				$version_lower = strtolower( $version_safe );
				$urls_to_try = array(
					"https://wordpress.org/wordpress-{$version_safe}.zip",
					"https://wordpress.org/wordpress-{$version_lower}.zip",
					"https://wordpress.org/nightly/wordpress-{$version_safe}.zip",
					"https://wordpress.org/nightly/wordpress-{$version_lower}.zip",
				);

				foreach ( $urls_to_try as $url ) {
					WP_CLI::log( sprintf( 'Trying: %s', $url ) );
					$context = stream_context_create(
						array(
							'http' => array(
								'timeout' => 30,
								'follow_location' => true,
							),
						)
					);
					$zip_content = @file_get_contents( $url, false, $context );
					if ( $zip_content !== false ) {
						WP_CLI::log( sprintf( '✓ Successfully downloaded from: %s', $url ) );
						break;
					}
				}
			} else {
				$context = stream_context_create(
					array(
						'http' => array(
							'timeout' => 30,
							'follow_location' => true,
						),
					)
				);
				$zip_content = @file_get_contents( $download_url, false, $context );
			}

			if ( $zip_content === false ) {
				WP_CLI::error( 'Failed to download WordPress source. The version may not be available or the URL format may be incorrect.' );
				WP_CLI::log( 'Tip: You can use --source-dir to point to an existing WordPress source directory.' );
				return;
			}

			file_put_contents( $zip_path, $zip_content );

			// Extract WordPress.
			WP_CLI::log( '📦 Extracting WordPress source...' );
			$zip = new ZipArchive();
			if ( $zip->open( $zip_path ) === true ) {
				$zip->extractTo( $temp_dir );
				$zip->close();
				unlink( $zip_path );

				// Find the WordPress directory inside.
				$extracted_dir = $temp_dir . '/wordpress';
				if ( ! is_dir( $extracted_dir ) ) {
					// Sometimes it extracts to current directory.
					$extracted_dir = $temp_dir;
				}
				$source_dir = $extracted_dir;
			} else {
				WP_CLI::error( 'Failed to extract WordPress archive.' );
				return;
			}
		}

		if ( ! is_dir( $source_dir ) ) {
			WP_CLI::error( sprintf( 'Source directory does not exist: %s', $source_dir ) );
			return;
		}

		// Set output path.
		if ( ! $output_path ) {
			$version_safe = preg_replace( '/[^a-zA-Z0-9.-]/', '-', $version );
			$output_path = WP_COMPAT_SCANNER_DIR . "wp-since-{$version_safe}.json";
		}

		WP_CLI::log( sprintf( '🔍 Generating compatibility map from: %s', $source_dir ) );
		WP_CLI::log( sprintf( '📝 Output: %s', $output_path ) );

		// Modify the generate script to use our source directory and output path.
		$generate_script_content = file_get_contents( $generate_script );
		
		// Find the correct vendor autoload path (wp-since uses parent vendor directory).
		$vendor_autoload = WP_COMPAT_SCANNER_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $vendor_autoload ) ) {
			WP_CLI::error( 'Composer vendor autoload not found. Please run: composer install' );
			return;
		}
		
		// Escape paths for use in PHP string
		$source_dir_escaped = addslashes( $source_dir );
		$output_path_escaped = addslashes( $output_path );
		$vendor_autoload_escaped = addslashes( $vendor_autoload );

		$modified_script = str_replace(
			array(
				'require __DIR__ . \'/vendor/autoload.php\';',
				'$sourceDir = __DIR__ . \'/wp-source\';',
				'$outputPath = __DIR__ . \'/wp-since.json\';',
			),
			array(
				"require '{$vendor_autoload_escaped}';",
				"\$sourceDir = '{$source_dir_escaped}';",
				"\$outputPath = '{$output_path_escaped}';",
			),
			$generate_script_content
		);

		$temp_script = sys_get_temp_dir() . '/wp-compat-scanner-generate-' . uniqid() . '.php';
		file_put_contents( $temp_script, $modified_script );

		// Run the generate script.
		$output = array();
		$return_var = 0;
		exec( "php {$temp_script} 2>&1", $output, $return_var );

		// Clean up temp script.
		if ( file_exists( $temp_script ) ) {
			unlink( $temp_script );
		}

		// Clean up temp directory if we created it.
		if ( $temp_dir && is_dir( $temp_dir ) ) {
			$this->remove_directory( $temp_dir );
		}

		// Show output for debugging.
		if ( ! empty( $output ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Script output:' );
			foreach ( $output as $line ) {
				WP_CLI::log( $line );
			}
			WP_CLI::log( '' );
		}

		if ( $return_var !== 0 ) {
			WP_CLI::error( sprintf( 'Failed to generate compatibility map (exit code: %d).', $return_var ) );
			if ( ! empty( $output ) ) {
				WP_CLI::log( 'Error details:' );
				WP_CLI::log( implode( "\n", $output ) );
			}
			return;
		}

		if ( file_exists( $output_path ) ) {
			WP_CLI::success( sprintf( 'Compatibility map generated: %s', $output_path ) );
			WP_CLI::log( sprintf( 'Use it with: wp compat-scanner scan --since-map=%s', $output_path ) );
		} else {
			WP_CLI::warning( 'Map generation completed but output file not found.' );
			if ( ! empty( $output ) ) {
				WP_CLI::log( 'Script output:' );
				WP_CLI::log( implode( "\n", $output ) );
			}
		}
	}

	/**
	 * Get WordPress download URL for a specific version.
	 *
	 * @param string $version Version string (e.g., 6.9-RC1, 6.9, latest).
	 * @return string|null Download URL or null if not found.
	 */
	private function get_wordpress_download_url( $version ) {
		if ( $version === 'latest' ) {
			return 'https://wordpress.org/latest.zip';
		}

		// Handle RC/Beta versions - try multiple URL formats.
		if ( preg_match( '/\b(RC|rc|beta|Beta)\b/i', $version ) ) {
			// Try standard format first: wordpress-6.9-RC1.zip
			$version_safe = str_replace( array( ' ', '_' ), '-', $version );
			$urls = array(
				"https://wordpress.org/wordpress-{$version_safe}.zip",
				"https://wordpress.org/nightly/wordpress-{$version_safe}.zip",
			);

			// Also try lowercase version.
			$version_lower = strtolower( $version_safe );
			$urls[] = "https://wordpress.org/wordpress-{$version_lower}.zip";
			$urls[] = "https://wordpress.org/nightly/wordpress-{$version_lower}.zip";

			// Return first URL (will be checked when downloading).
			return $urls[0];
		}

		// Regular versions.
		return "https://wordpress.org/wordpress-{$version}.zip";
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool True on success, false on failure.
	 */
	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->remove_directory( $path ) : unlink( $path );
		}

		return rmdir( $dir );
	}

	/**
	 * Scan plugin excluding vendor directories.
	 * 
	 * Wraps PluginScanner::scan() but excludes vendor directories to avoid
	 * false positives from third-party dependencies.
	 *
	 * @param string $plugin_path Path to the plugin directory.
	 * @return array Array of used symbols.
	 */
	private function scan_plugin_excluding_vendor( $plugin_path ) {
		// Use the standard scanner but filter out vendor directories.
		// We'll scan the plugin directory but exclude vendor subdirectories.
		$used_symbols = array();
		
		// Get ignore patterns from .distignore or .gitattributes if they exist.
		$ignore_paths = \WP_Since\Resolver\IgnoreRulesResolver::getIgnoredPaths( $plugin_path );
		
		// Always exclude vendor directories.
		$ignore_paths[] = 'vendor';
		$ignore_paths[] = 'node_modules'; // Also exclude node_modules if present.
		
		// Use PHP Parser to scan files, excluding vendor directories.
		$parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);
		$traverser = new \PhpParser\NodeTraverser();
		$traverser->addVisitor(new \PhpParser\NodeVisitor\ParentConnectingVisitor());
		
		$var_map = array();
		$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator( $plugin_path ));
		
		foreach ( $rii as $file ) {
			// Get relative path from plugin root.
			$plugin_path_normalized = rtrim( $plugin_path, '/' ) . '/';
			$relative_path = str_replace( $plugin_path_normalized, '', $file->getPathname() );
			
			// Skip directories, non-PHP files, and ignored paths (including vendor).
			if (
				$file->isDir() ||
				$file->getExtension() !== 'php' ||
				\WP_Since\Resolver\IgnoreRulesResolver::shouldIgnore( $relative_path, $ignore_paths )
			) {
				continue;
			}
			
			// Skip vendor directories explicitly (at root or anywhere in path).
			if ( strpos( $relative_path, 'vendor/' ) === 0 || strpos( $relative_path, '/vendor/' ) !== false ) {
				continue;
			}
			
			$code = file_get_contents( $file->getPathname() );
			$ignored_lines = \WP_Since\Resolver\InlineIgnoreResolver::extractIgnoredLines( $code );
			
			$visitor = new \WP_Since\Scanner\SymbolExtractorVisitor( $used_symbols, $var_map, $ignored_lines );
			$traverser->addVisitor( $visitor );
			
			try {
				$stmts = $parser->parse( $code );
				if ( $stmts ) {
					$traverser->traverse( $stmts );
				}
			} catch ( \Exception $e ) {
				// Skip files with parse errors.
			}
			
			$traverser->removeVisitor( $visitor );
		}
		
		return array_unique( $used_symbols );
	}

	/**
	 * Get symbols introduced in a specific WordPress version.
	 *
	 * @param array  $used_symbols Array of symbols used by the plugin.
	 * @param array  $since_map    Compatibility map.
	 * @param string $version     Version to check (e.g., '6.9').
	 * @return array Array of symbol => version.
	 */
	private function get_symbols_introduced_in_version( $used_symbols, $since_map, $version ) {
		$symbols_in_version = array();
		$version_normalized = \WP_Since\Utils\VersionHelper::normalize( $version );

		foreach ( $used_symbols as $symbol ) {
			if ( isset( $since_map[ $symbol ] ) ) {
				$symbol_version = $since_map[ $symbol ]['since'];
				$symbol_version_normalized = \WP_Since\Utils\VersionHelper::normalize( $symbol_version );

				// Check if symbol was introduced in the specified version (6.9.x).
				if ( strpos( $symbol_version_normalized, $version_normalized ) === 0 ) {
					$symbols_in_version[ $symbol ] = $symbol_version;
				}
			}
		}

		return $symbols_in_version;
	}

	/**
	 * Get deprecated symbols that might be removed or changed in a target version.
	 *
	 * @param array  $used_symbols Array of symbols used by the plugin.
	 * @param array  $since_map    Compatibility map.
	 * @param string $target_version Target WordPress version (e.g., '6.9').
	 * @return array Array of symbol => info (deprecated version, type, etc.).
	 */
	private function get_deprecated_symbols( $used_symbols, $since_map, $target_version ) {
		$deprecated = array();
		$target_normalized = \WP_Since\Utils\VersionHelper::normalize( $target_version );

		foreach ( $used_symbols as $symbol ) {
			if ( isset( $since_map[ $symbol ] ) && ! empty( $since_map[ $symbol ]['deprecated'] ) ) {
				$deprecated_version = $since_map[ $symbol ]['deprecated'];
				$deprecated_normalized = \WP_Since\Utils\VersionHelper::normalize( $deprecated_version );

				// Check if deprecated before or in the target version.
				// If deprecated in 6.8 or earlier, it might be removed in 6.9.
				if ( \WP_Since\Utils\VersionHelper::compare( $deprecated_normalized, $target_normalized ) <= 0 ) {
					$deprecated[ $symbol ] = array(
						'deprecated' => $deprecated_version,
						'type' => isset( $since_map[ $symbol ]['type'] ) ? $since_map[ $symbol ]['type'] : 'unknown',
						'file' => isset( $since_map[ $symbol ]['file'] ) ? $since_map[ $symbol ]['file'] : '',
					);
				}
			}
		}

		return $deprecated;
	}

	/**
	 * Format a symbol key for display.
	 *
	 * Converts type-prefixed keys (e.g. "function:set_transient") into
	 * a human-readable format like "set_transient (function)".
	 *
	 * @param string $symbol Symbol key, optionally type-prefixed.
	 * @return string Formatted symbol name.
	 */
	private function format_symbol_name( $symbol ) {
		if ( strpos( $symbol, ':' ) !== false ) {
			list( $type, $name ) = explode( ':', $symbol, 2 );
			return sprintf( '%s (%s)', $name, $type );
		}
		return $symbol;
	}

	/**
	 * Find the wp-since.json map file.
	 *
	 * @return string|null Path to wp-since.json or null if not found.
	 */
	private function find_since_map() {
		// Check in vendor directory first.
		$vendor_path = WP_COMPAT_SCANNER_DIR . 'vendor/eduardovillao/wp-since/wp-since.json';
		if ( file_exists( $vendor_path ) ) {
			return $vendor_path;
		}

		// Check in plugin root.
		$plugin_path = WP_COMPAT_SCANNER_DIR . 'wp-since.json';
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		// Check in WordPress root.
		$wp_root_path = ABSPATH . 'wp-since.json';
		if ( file_exists( $wp_root_path ) ) {
			return $wp_root_path;
		}

		return null;
	}
}

