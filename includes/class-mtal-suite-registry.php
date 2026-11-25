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
