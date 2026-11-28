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
		add_action( 'init', array( __CLASS__, 'register_account_endpoint' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_account_endpoint' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ), 41 );
		add_action( 'woocommerce_account_mtal-customer-reviews_endpoint', array( __CLASS__, 'render_my_reviews_endpoint' ) );
		add_action( 'admin_post_mtal_submit_suite_review', array( __CLASS__, 'handle_submission' ) );
		add_action( 'admin_post_mtal_update_suite_review', array( __CLASS__, 'handle_review_update' ) );
	}

	public static function register_account_endpoint() {
		add_rewrite_endpoint( 'mtal-customer-reviews', EP_ROOT | EP_PAGES );
	}

	public static function maybe_flush_account_endpoint() {
		if ( '1' === get_option( 'mtal_customer_reviews_rewrite_version' ) ) {
			return;
		}
		self::register_account_endpoint();
		flush_rewrite_rules( false );
		update_option( 'mtal_customer_reviews_rewrite_version', '1', false );
	}

	public static function add_account_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items['mtal-customer-reviews'] = 'نظرات من';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function render_my_reviews_endpoint() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="woocommerce-info">برای مشاهده نظرات ابتدا وارد حساب کاربری شوید.</div>';
			return;
		}
		$reviews = get_comments( array(
			'type'     => self::COMMENT_TYPE,
			'status'   => 'all',
			'user_id'  => get_current_user_id(),
			'number'   => 100,
			'orderby'  => 'comment_date',
			'order'    => 'DESC',
		) );
		echo '<div class="mtal-customer-reviews"><h3>نظرات من</h3>';
		if ( isset( $_GET['mtal_review_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mtal_review_updated'] ) ) ) {
			echo '<div class="woocommerce-message" role="alert">ویرایش شما ثبت شد و نظر دوباره برای بررسی مدیر ارسال شد.</div>';
		}
		if ( empty( $reviews ) ) {
			echo '<p>هنوز نقد و بررسی ثبت نکرده‌اید. پس از پایان اقامت می‌توانید از بخش رزروها نظر خود را ثبت کنید.</p></div>';
			return;
		}
		echo '<style>
			.mtal-customer-reviews__item{margin:18px 0;padding:20px;border:1px solid #e5e7eb;border-radius:12px}
			.mtal-customer-reviews__item fieldset{margin:0 0 14px;padding:0 0 12px;border:0;border-bottom:1px solid #eee}
			.mtal-customer-reviews__ratings{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
			.mtal-customer-reviews__ratings label{display:flex;align-items:center;gap:8px}
			.mtal-customer-reviews__item input[type=text],.mtal-customer-reviews__item textarea{width:100%;max-width:100%;box-sizing:border-box}
			.mtal-customer-reviews__item textarea{min-height:120px}
		</style>';
		foreach ( $reviews as $review ) {
			if ( in_array( $review->comment_approved, array( 'spam', 'trash' ), true ) ) {
				continue;
			}
			$order_id = absint( get_comment_meta( $review->comment_ID, self::META_ORDER_ID, true ) );
			$suite_id = absint( get_comment_meta( $review->comment_ID, self::META_SUITE_ID, true ) );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			if ( ! $order || absint( $order->get_customer_id() ) !== get_current_user_id() || absint( $order->get_meta( '_mtal_booking_suite_id' ) ) !== $suite_id ) {
				continue;
			}
			$suite = class_exists( 'MTAL_Suite_Registry' ) ? MTAL_Suite_Registry::get_display_data( $suite_id ) : false;
			$suite_title = is_array( $suite ) && ! empty( $suite['title'] ) ? $suite['title'] : 'سوئیت #' . $suite_id;
			$status = '1' === (string) $review->comment_approved ? 'منتشرشده' : 'در انتظار بررسی';
			$ratings = get_comment_meta( $review->comment_ID, self::META_RATINGS, true );
			$title = (string) get_comment_meta( $review->comment_ID, '_mtal_review_title', true );
			echo '<article class="mtal-customer-reviews__item">';
			echo '<h4>' . esc_html( $suite_title ) . '</h4>';
			echo '<p>وضعیت: <strong>' . esc_html( $status ) . '</strong></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="mtal_update_suite_review" />';
			echo '<input type="hidden" name="comment_id" value="' . esc_attr( $review->comment_ID ) . '" />';
			wp_nonce_field( 'mtal_update_suite_review_' . $review->comment_ID, 'mtal_review_edit_nonce' );
			echo '<p><label for="mtal-review-title-' . esc_attr( $review->comment_ID ) . '">عنوان نظر (اختیاری)</label><input id="mtal-review-title-' . esc_attr( $review->comment_ID ) . '" type="text" name="review_title" maxlength="120" value="' . esc_attr( $title ) . '" /></p>';
			echo '<fieldset><legend>امتیازهای شما</legend><div class="mtal-customer-reviews__ratings">';
			foreach ( self::rating_criteria() as $key => $label ) {
				$current_rating = isset( $ratings[ $key ] ) ? absint( $ratings[ $key ] ) : 0;
				echo '<label>' . esc_html( $label ) . '<select name="ratings[' . esc_attr( $key ) . ']" required>';
				echo '<option value="">انتخاب امتیاز</option>';
				for ( $rating = 1; $rating <= 5; $rating++ ) {
					echo '<option value="' . esc_attr( $rating ) . '"' . selected( $current_rating, $rating, false ) . '>' . esc_html( $rating ) . ' از ۵</option>';
				}
				echo '</select></label>';
			}
			echo '</div></fieldset>';
			echo '<p><label for="mtal-review-content-' . esc_attr( $review->comment_ID ) . '">متن نقد و بررسی</label><textarea id="mtal-review-content-' . esc_attr( $review->comment_ID ) . '" name="review_content" minlength="10" maxlength="5000" required>' . esc_textarea( $review->comment_content ) . '</textarea></p>';
			echo '<p><button type="submit" class="woocommerce-button button">ذخیره و ارسال برای بررسی مجدد</button></p>';
			echo '</form></article>';
		}
		echo '</div>';
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
	 * Update an existing review without allowing its booking or suite relation to change.
	 * The previous version is stored in private comment meta before moderation resets.
	 */
	public static function handle_review_update() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'برای ویرایش نظر ابتدا وارد حساب کاربری شوید.', 'mtal-persian-calendar' ), '', array( 'response' => 401 ) );
		}
		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		check_admin_referer( 'mtal_update_suite_review_' . $comment_id, 'mtal_review_edit_nonce' );
		$review = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $review || self::COMMENT_TYPE !== $review->comment_type || absint( $review->user_id ) !== get_current_user_id() || in_array( $review->comment_approved, array( 'spam', 'trash' ), true ) ) {
			wp_die( esc_html__( 'این نظر متعلق به حساب کاربری شما نیست یا قابل ویرایش نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		$order_id = absint( get_comment_meta( $comment_id, self::META_ORDER_ID, true ) );
		$suite_id = absint( get_comment_meta( $comment_id, self::META_SUITE_ID, true ) );
		$order = self::get_eligible_order( $order_id );
		if ( ! $order || absint( $order->get_meta( '_mtal_booking_suite_id' ) ) !== $suite_id || absint( $review->comment_post_ID ) !== $suite_id || self::get_review_id_for_order( $order_id ) !== $comment_id ) {
			wp_die( esc_html__( 'مالکیت رزرو یا ارتباط نظر با سوئیت تأیید نشد.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		$lock_key = 'mtal_suite_review_edit_lock_' . $comment_id;
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			wp_die( esc_html__( 'ویرایش دیگری برای این نظر در حال انجام است. کمی بعد دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 409 ) );
		}
		$ratings = self::validate_ratings( isset( $_POST['ratings'] ) ? wp_unslash( $_POST['ratings'] ) : array() );
		$body = isset( $_POST['review_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_content'] ) ) : '';
		$body_length = function_exists( 'mb_strlen' ) ? mb_strlen( $body ) : strlen( $body );
		$title = isset( $_POST['review_title'] ) ? sanitize_text_field( wp_unslash( $_POST['review_title'] ) ) : '';
		if ( is_wp_error( $ratings ) || $body_length < 10 || $body_length > 20000 ) {
			delete_option( $lock_key );
			$message = is_wp_error( $ratings ) ? $ratings->get_error_message() : 'متن نقد و بررسی باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.';
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}
		$history = get_comment_meta( $comment_id, '_mtal_review_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$previous_ratings = get_comment_meta( $comment_id, self::META_RATINGS, true );
		$history[] = array(
			'changed_at' => current_time( 'mysql' ),
			'changed_by' => get_current_user_id(),
			'title' => (string) get_comment_meta( $comment_id, '_mtal_review_title', true ),
			'content' => (string) $review->comment_content,
			'ratings' => is_array( $previous_ratings ) ? $previous_ratings : array(),
			'average' => get_comment_meta( $comment_id, self::META_AVERAGE, true ),
			'previous_status' => (string) $review->comment_approved,
		);
		$updated = wp_update_comment( wp_slash( array(
			'comment_ID' => $comment_id,
			'comment_content' => $body,
			'comment_approved' => 0,
		) ), true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			delete_option( $lock_key );
			wp_die( esc_html__( 'ذخیره ویرایش انجام نشد. لطفاً دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 500 ) );
		}
		update_comment_meta( $comment_id, '_mtal_review_history', $history );
		update_comment_meta( $comment_id, self::META_RATINGS, $ratings );
		update_comment_meta( $comment_id, self::META_AVERAGE, round( array_sum( $ratings ) / count( $ratings ), 2 ) );
		if ( '' === $title ) {
			delete_comment_meta( $comment_id, '_mtal_review_title' );
		} else {
			update_comment_meta( $comment_id, '_mtal_review_title', $title );
		}
		delete_option( $lock_key );
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'mtal-customer-reviews' ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'mtal_review_updated', '1', $url ) );
		exit;
	}

	/**
	 * Return previous versions only to a manager authorized for this suite.
	 */
	public static function get_review_history( $comment_id ) {
		$review = get_comment( absint( $comment_id ) );
		if ( ! $review || self::COMMENT_TYPE !== $review->comment_type || ! self::can_manage_suite_reviews( absint( get_comment_meta( $review->comment_ID, self::META_SUITE_ID, true ) ) ) ) {
			return array();
		}
		$history = get_comment_meta( $review->comment_ID, '_mtal_review_history', true );
		return is_array( $history ) ? $history : array();
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

		// The current accommodation-manager role has site-wide suite-editing
		// capabilities and no per-suite assignment map. Until that relation is
		// implemented, do not expose private review details to managers broadly.
		return current_user_can( 'manage_options' );
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
