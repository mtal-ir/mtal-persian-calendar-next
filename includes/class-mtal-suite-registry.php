<?php
/**
 * Internal data model for MTAL suites.
 *
 * A MTAL suite is an independent plugin entity. Its source WordPress post
 * supplies fallback content only; it is not the suite's identity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Suite_Registry {

	const POST_TYPE = 'mtal_suite';

	const META_SOURCE_POST_TYPE = '_mtal_suite_source_post_type';
	const META_SOURCE_POST_ID   = '_mtal_suite_source_post_id';
	const META_TITLE_OVERRIDE  = '_mtal_suite_title_override';
	const META_IMAGE_OVERRIDE  = '_mtal_suite_image_override_id';

	/**
	 * Register the internal suite entity.
	 *
	 * The post type intentionally has no public URL or built-in admin screen.
	 * A dedicated management interface can be added without exposing these
	 * records as ordinary website content.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'migrate_legacy_calendar_data' ), 20 );
	}

	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => 'سوئیت‌های MTAL',
					'singular_name' => 'سوئیت MTAL',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Check whether an ID belongs to an MTAL suite entity.
	 */
	public static function is_suite( $suite_id ) {
		$suite = get_post( absint( $suite_id ) );
		return $suite && self::POST_TYPE === $suite->post_type;
	}


	/**
	 * Calendar settings stored on legacy display posts before the independent
	 * suite model was introduced.
	 */
	private static function get_legacy_calendar_meta_keys() {
		return array(
			'_mtal_blocked_dates',
			'_mtal_holiday_dates',
			'_mtal_manual_prices',
			'_mtal_regular_price',
			'_mtal_weekend_price',
			'_mtal_base_capacity',
			'_mtal_extra_guest_price',
		);
	}

	/**
	 * Find the MTAL suite linked to one display post.
	 */
	public static function get_suite_by_source( $source_post_id, $source_post_type = '' ) {
		$source_post_id = absint( $source_post_id );
		$source_post = $source_post_id ? get_post( $source_post_id ) : null;
		if ( ! $source_post ) {
			return 0;
		}

		$source_post_type = $source_post_type ? sanitize_key( $source_post_type ) : $source_post->post_type;
		if ( $source_post_type !== $source_post->post_type || self::POST_TYPE === $source_post_type ) {
			return 0;
		}

		// Avoid meta_query here: direct metadata reads are more reliable on
		// installations where WP_Query's meta EXISTS/AND clauses miss records.
		$offset = 0;
		$batch_size = 200;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'posts_per_page'         => $batch_size,
					'offset'                 => $offset,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $suite_id ) {
				$linked_type = (string) get_post_meta( $suite_id, self::META_SOURCE_POST_TYPE, true );
				$linked_id = absint( get_post_meta( $suite_id, self::META_SOURCE_POST_ID, true ) );
				if ( $source_post_type === $linked_type && $source_post_id === $linked_id ) {
					return absint( $suite_id );
				}
			}

			$found = count( $query->posts );
			$offset += $found;
		} while ( $found === $batch_size );

		return 0;
	}

	/**
	 * Copy legacy settings without overwriting values already on the MTAL suite.
	 */
	public static function migrate_calendar_settings( $source_post_id, $suite_id ) {
		$source_post_id = absint( $source_post_id );
		$suite_id = absint( $suite_id );
		if ( ! $source_post_id || ! self::is_suite( $suite_id ) ) {
			return false;
		}

		foreach ( self::get_legacy_calendar_meta_keys() as $meta_key ) {
			if ( metadata_exists( 'post', $suite_id, $meta_key ) ) {
				continue;
			}
			if ( metadata_exists( 'post', $source_post_id, $meta_key ) ) {
				update_post_meta( $suite_id, $meta_key, get_post_meta( $source_post_id, $meta_key, true ) );
			}
		}
		update_post_meta( $suite_id, '_mtal_calendar_settings_migrated_from', $source_post_id );
		return true;
	}

	/**
	 * Return the linked suite, creating it only when no relationship exists.
	 */
	public static function get_or_create_for_source( $source_post_id ) {
		$source_post_id = absint( $source_post_id );
		$source_post = $source_post_id ? get_post( $source_post_id ) : null;
		if ( ! $source_post || self::POST_TYPE === $source_post->post_type || in_array( $source_post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return 0;
		}

		$suite_id = self::get_suite_by_source( $source_post_id, $source_post->post_type );
		if ( ! $suite_id ) {
			$suite_id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish' === $source_post->post_status ? 'publish' : 'draft',
					'post_title'  => wp_strip_all_tags( get_the_title( $source_post ) ),
				),
				true
			);
			if ( is_wp_error( $suite_id ) || ! $suite_id ) {
				return 0;
			}
			update_post_meta( $suite_id, self::META_SOURCE_POST_TYPE, $source_post->post_type );
			update_post_meta( $suite_id, self::META_SOURCE_POST_ID, $source_post_id );
		}

		self::migrate_calendar_settings( $source_post_id, $suite_id );
		return absint( $suite_id );
	}

	/**
	 * Migrate existing posts with MTAL calendar metadata in small admin batches.
	 * Legacy values remain on source posts; existing MTAL values take precedence.
	 */
	public static function migrate_legacy_calendar_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_key = '_mtal_suite_calendar_migration_v3';
		$state = get_option( $option_key, array() );
		if ( ! empty( $state['complete'] ) ) {
			return;
		}

		$source_post_types = get_post_types( array(), 'names' );
		unset( $source_post_types[ self::POST_TYPE ], $source_post_types['revision'], $source_post_types['nav_menu_item'], $source_post_types['attachment'] );
		if ( empty( $source_post_types ) ) {
			update_option( $option_key, array( 'complete' => true, 'processed' => 0, 'scanned' => 0 ), false );
			return;
		}

		$batch_size = 50;
		$offset = isset( $state['offset'] ) ? absint( $state['offset'] ) : 0;
		$processed = isset( $state['processed'] ) ? absint( $state['processed'] ) : 0;
		$query = new WP_Query(
			array(
				'post_type'              => array_values( $source_post_types ),
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'         => $batch_size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$matched = 0;
		$meta_keys = self::get_legacy_calendar_meta_keys();
		foreach ( $query->posts as $source_post_id ) {
			$has_calendar_data = false;
			foreach ( $meta_keys as $meta_key ) {
				if ( metadata_exists( 'post', $source_post_id, $meta_key ) ) {
					$has_calendar_data = true;
					break;
				}
			}
			if ( ! $has_calendar_data ) {
				continue;
			}

			$matched++;
			if ( self::get_or_create_for_source( $source_post_id ) ) {
				$processed++;
			}
		}

		$scanned = $offset + count( $query->posts );
		if ( count( $query->posts ) < $batch_size ) {
			update_option(
				$option_key,
				array(
					'complete'     => true,
					'processed'    => $processed,
					'scanned'      => $scanned,
				'completed_at' => current_time( 'mysql', true ),
				),
				false
			);
		} else {
			update_option(
				$option_key,
				array(
					'complete'  => false,
					'offset'    => $scanned,
					'processed' => $processed,
				'scanned'   => $scanned,
				),
				false
			);
		}
	}

	/**
	 * Resolve the optional source post connected to a suite.
	 *
	 * The source post type is stored per suite, so installations are not tied
	 * to a hard-coded host post type such as "suites".
	 *
	 * @return WP_Post|null
	 */
	public static function get_source_post( $suite_id ) {
		if ( ! self::is_suite( $suite_id ) ) {
			return null;
		}

		$source_post_type = sanitize_key(
			(string) get_post_meta( absint( $suite_id ), self::META_SOURCE_POST_TYPE, true )
		);
		$source_post_id = absint(
			get_post_meta( absint( $suite_id ), self::META_SOURCE_POST_ID, true )
		);

		if (
			! $source_post_type ||
			self::POST_TYPE === $source_post_type ||
			! post_type_exists( $source_post_type ) ||
			! $source_post_id
		) {
			return null;
		}

		$source_post = get_post( $source_post_id );
		if (
			! $source_post ||
			$source_post_type !== $source_post->post_type ||
			in_array( $source_post->post_status, array( 'trash', 'auto-draft' ), true )
		) {
			return null;
		}

		return $source_post;
	}

	/**
	 * Return the effective title and image for a suite.
	 *
	 * Explicit overrides take precedence. Empty overrides fall back to the
	 * connected source post, and finally to the internal suite record.
	 *
	 * @return array|false
	 */
	public static function get_display_data( $suite_id ) {
		$suite_id = absint( $suite_id );
		$suite    = get_post( $suite_id );

		if ( ! $suite || self::POST_TYPE !== $suite->post_type ) {
			return false;
		}

		$source_post = self::get_source_post( $suite_id );
		$title_override = trim(
			(string) get_post_meta( $suite_id, self::META_TITLE_OVERRIDE, true )
		);
		$image_override_id = absint(
			get_post_meta( $suite_id, self::META_IMAGE_OVERRIDE, true )
		);

		$title = $title_override;
		$title_source = 'override';
		if ( '' === $title && $source_post ) {
			$title = get_the_title( $source_post );
			$title_source = 'source';
		}
		if ( '' === $title ) {
			$title = get_the_title( $suite_id );
			$title_source = 'suite';
		}

		$image_id = 0;
		$image_source = 'none';
		if ( $image_override_id && wp_attachment_is_image( $image_override_id ) ) {
			$image_id = $image_override_id;
			$image_source = 'override';
		} elseif ( $source_post ) {
			$source_image_id = get_post_thumbnail_id( $source_post->ID );
			if ( $source_image_id ) {
				$image_id = absint( $source_image_id );
				$image_source = 'source';
			}
		}

		return array(
			'suiteId'        => $suite_id,
			'sourcePostType' => $source_post ? $source_post->post_type : '',
			'sourcePostId'   => $source_post ? absint( $source_post->ID ) : 0,
			'title'          => $title,
			'titleSource'    => $title_source,
			'imageId'        => $image_id,
			'imageUrl'       => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
			'imageSource'    => $image_source,
		);
	}
}
