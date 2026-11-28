<?php
/**
 * Verified guest reviews for independent MTAL suites.
 *
 * Reviews are stored as WordPress comments attached to the internal suite
 * record. Rating details live in comment meta and are never part of public
 * review output.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Suite_Reviews {

	const COMMENT_TYPE = 'mtal_suite_review';
	const META_ORDER_ID = '_mtal_review_order_id';
	const META_SUITE_ID = '_mtal_review_suite_id';
	const META_RATINGS = '_mtal_review_ratings';
	const META_AVERAGE = '_mtal_review_average';
	const ORDER_REVIEW_META = '_mtal_suite_review_comment_id';

	/**
	 * Stable rating keys and their public labels.
	 */
	public static function rating_criteria() {
		return array(
			'cleanliness' => 'نظافت و بهداشت',
			'orderliness' => 'نظم و آراستگی',
			'comfort'     => 'امکانات و راحتی',
			'access'      => 'دسترسی و موقعیت مکانی',
			'nature'      => 'طبیعت و محیط اطراف',
			'host'        => 'برخورد و پاسخ‌گویی میزبان',
			'value'       => 'ارزش در برابر قیمت',
		);
	}

	public static function init() {
		add_action( 'admin_post_mtal_submit_suite_review', array( __CLASS__, 'handle_submission' ) );
	}

	/**
	 * Check that an order belongs to the current guest and represents a
	 * completed, paid stay whose checkout date has passed.
	 *
	 * @return WC_Order|false
	 */
	public static function get_eligible_order( $order_id, $user_id = 0 ) {
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'MTAL_Persian_Calendar' ) || ! class_exists( 'MTAL_Suite_Registry' ) ) {
			return false;
		}

		$order_id = absint( $order_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $order_id || ! $user_id ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || absint( $order->get_customer_id() ) !== $user_id ) {
			return false;
		}

		if ( ! $order->is_paid() || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return false;
		}

		$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
		if ( ! $suite_id || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			return false;
		}

		$source_post = MTAL_Suite_Registry::get_source_post( $suite_id );
		if ( ! $source_post || 'publish' !== $source_post->post_status ) {
			return false;
		}

		$checkout = MTAL_Persian_Calendar::normalize_booking_date(
			(string) $order->get_meta( '_mtal_booking_check_out' )
		);
		$today_text = MTAL_Persian_Calendar::format_jalali_datetime( current_datetime() );
		$today_text = preg_replace( '/\s.*$/u', '', $today_text );
		$today = MTAL_Persian_Calendar::normalize_booking_date( $today_text );

		if ( ! $checkout || ! $today || $checkout['jdn'] >= $today['jdn'] ) {
			return false;
		}

		return $order;
	}

	/**
	 * Return the existing review for one order, regardless of moderation state.
	 *
	 * @return int Comment ID, or 0.
	 */
	public static function get_review_id_for_order( $order_id ) {
		$ids = get_comments(
			array(
				'type'      => self::COMMENT_TYPE,
				'status'    => 'all',
				'number'    => 1,
				'fields'    => 'ids',
				'meta_key'  => self::META_ORDER_ID,
				'meta_value'=> absint( $order_id ),
			)
		);

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Validate all seven ratings. No partial rating submissions are accepted.
	 *
	 * @return array|WP_Error
	 */
	public static function validate_ratings( $raw_ratings ) {
		if ( ! is_array( $raw_ratings ) ) {
			return new WP_Error( 'mtal_review_ratings_missing', 'لطفاً به همه معیارهای امتیازدهی پاسخ دهید.' );
		}

		$ratings = array();
		foreach ( self::rating_criteria() as $key => $label ) {
			if ( ! isset( $raw_ratings[ $key ] ) || ! is_scalar( $raw_ratings[ $key ] ) ) {
				return new WP_Error( 'mtal_review_rating_missing', 'امتیاز معیار «' . $label . '» ثبت نشده است.' );
			}

			$value = absint( $raw_ratings[ $key ] );
			if ( $value < 1 || $value > 5 || (string) $value !== (string) $raw_ratings[ $key ] ) {
				return new WP_Error( 'mtal_review_rating_invalid', 'امتیاز هر معیار باید عددی بین ۱ تا ۵ باشد.' );
			}
			$ratings[ $key ] = $value;
		}

		return $ratings;
	}

	/**
	 * Create a moderated review after revalidating the booking on the server.
	 */
	public static function handle_submission() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'برای ثبت نظر ابتدا وارد حساب کاربری شوید.', 'mtal-persian-calendar' ), '', array( 'response' => 401 ) );
		}

		check_admin_referer( 'mtal_submit_suite_review', 'mtal_review_nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order = self::get_eligible_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'این رزرو واجد شرایط ثبت نظر نیست یا اقامت هنوز به پایان نرسیده است.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		$suite_id = absint( $order->get_meta( '_mtal_booking_suite_id' ) );
		$lock_key = 'mtal_suite_review_lock_' . $order_id;
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			wp_die( esc_html__( 'درخواست ثبت نظر دیگری برای این رزرو در حال انجام است. کمی بعد دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 409 ) );
		}

		if ( self::get_review_id_for_order( $order_id ) || absint( $order->get_meta( self::ORDER_REVIEW_META ) ) ) {
			delete_option( $lock_key );
			wp_die( esc_html__( 'برای این رزرو قبلاً نظر ثبت شده است.', 'mtal-persian-calendar' ), '', array( 'response' => 409 ) );
		}

		$raw_ratings = isset( $_POST['ratings'] ) ? wp_unslash( $_POST['ratings'] ) : array();
		$ratings = self::validate_ratings( $raw_ratings );
		$body = isset( $_POST['review_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_content'] ) ) : '';
		$body_length = function_exists( 'mb_strlen' ) ? mb_strlen( $body ) : strlen( $body );
		$title = isset( $_POST['review_title'] ) ? sanitize_text_field( wp_unslash( $_POST['review_title'] ) ) : '';

		if ( is_wp_error( $ratings ) || $body_length < 10 || $body_length > 20000 ) {
			delete_option( $lock_key );
			$message = is_wp_error( $ratings ) ? $ratings->get_error_message() : 'متن نقد و بررسی باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.';
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}

		$user = wp_get_current_user();
		$average = round( array_sum( $ratings ) / count( $ratings ), 2 );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $suite_id,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_content'      => $body,
				'comment_type'         => self::COMMENT_TYPE,
				'user_id'              => get_current_user_id(),
				'comment_approved'     => 0,
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', true ),
			)
		);

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			delete_option( $lock_key );
			wp_die( esc_html__( 'ثبت نظر انجام نشد. لطفاً دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 500 ) );
		}

		add_comment_meta( $comment_id, self::META_ORDER_ID, $order_id, true );
		add_comment_meta( $comment_id, self::META_SUITE_ID, $suite_id, true );
		add_comment_meta( $comment_id, self::META_RATINGS, $ratings, true );
		add_comment_meta( $comment_id, self::META_AVERAGE, $average, true );
		if ( '' !== $title ) {
			add_comment_meta( $comment_id, '_mtal_review_title', $title, true );
		}
		$order->update_meta_data( self::ORDER_REVIEW_META, $comment_id );
		$order->save();
		delete_option( $lock_key );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = function_exists( 'wc_get_account_endpoint_url' )
				? wc_get_account_endpoint_url( 'mtal-customer-bookings' )
				: home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( 'mtal_review_submitted', '1', $redirect ) );
		exit;
	}

	/**
	 * A manager can inspect private review details only for a suite they can
	 * manage. Site administrators retain access to all suites.
	 */
	public static function can_manage_suite_reviews( $suite_id ) {
		$suite_id = absint( $suite_id );
		if ( ! $suite_id || ! class_exists( 'MTAL_Suite_Registry' ) || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return class_exists( 'MTAL_Booking_Admin' )
			&& MTAL_Booking_Admin::current_user_can_manage_bookings()
			&& current_user_can( 'edit_post', $suite_id );
	}

	/**
	 * Public aggregate: only approved reviews contribute to the average.
	 * Private criterion scores are deliberately not returned.
	 */
	public static function get_public_summary( $suite_id ) {
		$suite_id = absint( $suite_id );
		if ( ! $suite_id || ! class_exists( 'MTAL_Suite_Registry' ) || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			return array( 'average' => 0, 'count' => 0 );
		}

		$comments = get_comments(
			array(
				'post_id' => $suite_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'approve',
				'number'  => 0,
				'fields'  => 'ids',
			)
		);
		$total = 0;
		$count = 0;
		foreach ( $comments as $comment_id ) {
			$value = get_comment_meta( $comment_id, self::META_AVERAGE, true );
			if ( is_numeric( $value ) && (float) $value >= 1 && (float) $value <= 5 ) {
				$total += (float) $value;
				$count++;
			}
		}

		return array(
			'average' => $count ? round( $total / $count, 2 ) : 0,
			'count'   => $count,
		);
	}
}

MTAL_Suite_Reviews::init();
