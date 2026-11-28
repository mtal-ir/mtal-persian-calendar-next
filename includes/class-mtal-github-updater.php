<?php
/**
 * GitHub Releases updater for MTAL Booking.
 * Only stable releases whose tag is contained in main are accepted.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MTAL_GitHub_Updater {
	const API_BASE = 'https://api.github.com/repos/mtal-ir/mtal-persian-calendar-next';
	const PACKAGE_NAME = 'mtal-persian-calendar-next.zip';
	const CACHE_KEY = 'mtal_booking_github_release_v1';
	private static $release = null;
	private static $release_loaded = false;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'match_installed_directory' ), 20, 4 );
	}

	private static function plugin_file() {
		return plugin_basename( MTAL_PC_PATH . 'mtal-persian-calendar.php' );
	}

	private static function plugin_slug() {
		return dirname( self::plugin_file() );
	}

	private static function get_release() {
		if ( self::$release_loaded ) { return self::$release; }
		self::$release_loaded = true;

		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
			self::$release = $cached;
			return self::$release;
		}

		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'MTAL-Booking-WordPress-Updater' );
		$response = wp_remote_get( self::API_BASE . '/releases/latest', array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return null; }

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) { return null; }
		$tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		if ( ! preg_match( '/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $tag, $matches ) ) { return null; }
		$version = $matches[1];

		// Accept only tags that are ancestors of main (or exactly its current commit).
		$compare = wp_remote_get(
			self::API_BASE . '/compare/' . rawurlencode( $tag ) . '...main',
			array( 'timeout' => 15, 'headers' => $headers )
		);
		if ( is_wp_error( $compare ) || 200 !== (int) wp_remote_retrieve_response_code( $compare ) ) { return null; }
		$comparison = json_decode( wp_remote_retrieve_body( $compare ), true );
		if ( ! is_array( $comparison ) || ! in_array( isset( $comparison['status'] ) ? $comparison['status'] : '', array( 'ahead', 'identical' ), true ) ) { return null; }

		$package = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::PACKAGE_NAME === $asset['name'] ) {
					$package = esc_url_raw( $asset['browser_download_url'] );
					break;
				}
			}
		}
		if ( ! $package || 0 !== strpos( $package, 'https://github.com/mtal-ir/mtal-persian-calendar-next/releases/download/' ) ) { return null; }

		self::$release = array(
			'version' => $version,
			'tag' => $tag,
			'package' => $package,
			'url' => isset( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : 'https://github.com/mtal-ir/mtal-persian-calendar-next/releases',
			'name' => isset( $release['name'] ) && $release['name'] ? sanitize_text_field( $release['name'] ) : 'MTAL Booking ' . $version,
			'description' => isset( $release['body'] ) ? wp_kses_post( $release['body'] ) : '',
		);
		set_site_transient( self::CACHE_KEY, self::$release, 6 * HOUR_IN_SECONDS );
		return self::$release;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) { return $transient; }
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) { $transient->response = array(); }
		$plugin = self::plugin_file();
		if ( empty( $transient->checked[ $plugin ] ) ) { return $transient; }
		$release = self::get_release();
		if ( ! $release || ! version_compare( $release['version'], $transient->checked[ $plugin ], '>' ) ) { return $transient; }

		$update = new stdClass();
		$update->slug = self::plugin_slug();
		$update->plugin = $plugin;
		$update->new_version = $release['version'];
		$update->url = $release['url'];
		$update->package = $release['package'];
		$transient->response[ $plugin ] = $update;
		return $transient;
	}

	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::plugin_slug() !== $args->slug ) { return $result; }
		$release = self::get_release();
		if ( ! $release ) { return $result; }

		$info = new stdClass();
		$info->name = 'MTAL Booking';
		$info->slug = self::plugin_slug();
		$info->version = $release['version'];
		$info->author = 'MTAL';
		$info->homepage = 'https://github.com/mtal-ir/mtal-persian-calendar-next';
		$info->download_link = $release['package'];
		$info->sections = array(
			'description' => 'افزونه تقویم جلالی، مدیریت سوئیت‌ها و گردش رزرو MTAL.',
			'changelog' => $release['description'],
		);
		return $info;
	}

	/**
	 * Preserve the existing plugin directory, including custom installation names.
	 */
	public static function match_installed_directory( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::plugin_file() !== $hook_extra['plugin'] ) { return $source; }
		$installed_slug = self::plugin_slug();
		if ( '.' === $installed_slug || basename( untrailingslashit( $source ) ) === $installed_slug ) { return $source; }

		global $wp_filesystem;
		if ( ! $wp_filesystem ) { return new WP_Error( 'mtal_updater_filesystem', 'وردپرس نتوانست فایل‌سیستم را برای به‌روزرسانی MTAL آماده کند.' ); }
		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $installed_slug;
		if ( $wp_filesystem->exists( $target ) ) { return new WP_Error( 'mtal_updater_target_exists', 'مسیر مقصد نسخه جدید MTAL از قبل وجود دارد؛ برای جلوگیری از حذف فایل‌ها، به‌روزرسانی متوقف شد.' ); }
		if ( ! $wp_filesystem->move( $source, $target, false ) ) { return new WP_Error( 'mtal_updater_rename_failed', 'تغییر نام پوشه بسته MTAL به مسیر نصب فعلی ناموفق بود.' ); }
		return $target;
	}
}

MTAL_GitHub_Updater::init();
