<?php
/**
 * Moderation screens for verified MTAL suite reviews.
 *
 * Site administrators can moderate from wp-admin and WooCommerce My Account.
 * Accommodation managers can moderate only assigned suites from My Account.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MTAL_Suite_Review_Management {

	const ENDPOINT = 'mtal-suite-review-management';
	const REJECTED_META = '_mtal_review_rejected';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_endpoint' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_account_menu_item' ), 42 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_account_endpoint' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_mtal_moderate_suite_review', array( __CLASS__, 'handle_moderation' ) );
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function maybe_flush_endpoint() {
		if ( '1' === get_option( 'mtal_suite_review_management_rewrite_version' ) ) {
			return;
		}
		self::register_endpoint();
		flush_rewrite_rules( false );
		update_option( 'mtal_suite_review_management_rewrite_version', '1', false );
	}

	private static function is_accommodation_manager() {
		$user = wp_get_current_user();
		$role = class_exists( 'MTAL_Accommodation_Manager_Role' )
			? MTAL_Accommodation_Manager_Role::ROLE
			: 'mtal_accommodation_manager';
		return $user && $user->exists() && in_array( $role, (array) $user->roles, true );
	}

	private static function can_open_management() {
		return current_user_can( 'manage_options' ) || self::is_accommodation_manager();
	}

	public static function add_account_menu_item( $items ) {
		if ( ! is_user_logged_in() || ! self::can_open_management() ) {
			return $items;
		}
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = 'مدیریت نظرات';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function register_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_menu_page(
			'مدیریت نظرات سوئیت‌ها',
			'مدیریت نظرات',
			'manage_options',
			'mtal-suite-review-management',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-star-filled',
			57
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>مدیریت نظرات سوئیت‌ها</h1>';
		self::render_review_list( true );
		echo '</div>';
	}

	public static function render_account_endpoint() {
		if ( ! is_user_logged_in() || ! self::can_open_management() ) {
			echo '<div class="woocommerce-error">شما اجازه دسترسی به بخش مدیریت نظرات را ندارید.</div>';
			return;
		}
		echo '<div class="mtal-suite-review-management"><h3>مدیریت نظرات</h3>';
		self::render_review_list( false );
		echo '</div>';
	}

	private static function get_scope_suite_ids() {
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}
		if ( ! self::is_accommodation_manager() || ! class_exists( 'MTAL_Suite_Registry' ) ) {
			return array();
		}
		return MTAL_Suite_Registry::get_assigned_suite_ids( get_current_user_id() );
	}

	private static function get_review_query( $page, $count = false ) {
		$args = array(
			'type'   => MTAL_Suite_Reviews::COMMENT_TYPE,
			'number' => 0,
			'fields' => 'all',
			'orderby'=> 'comment_date',
			'order'  => 'DESC',
		);
		$suite_ids = self::get_scope_suite_ids();
		if ( is_array( $suite_ids ) ) {
			if ( empty( $suite_ids ) ) {
				return $count ? 0 : array();
			}
			$args['meta_query'] = array(
				array(
					'key'     => MTAL_Suite_Reviews::META_SUITE_ID,
					'value'   => array_map( 'absint', $suite_ids ),
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				),
			);
		}

		// Pending/held and approved reviews are actionable; spam and trash are not.
		$reviews = array_merge(
			get_comments( array_merge( $args, array( 'status' => 'approve' ) ) ),
			get_comments( array_merge( $args, array( 'status' => 'hold' ) ) )
		);
		usort(
			$reviews,
			static function ( $a, $b ) {
				return strcmp( $b->comment_date_gmt, $a->comment_date_gmt );
			}
		);

		if ( $count ) {
			return count( $reviews );
		}

		$offset = ( max( 1, absint( $page ) ) - 1 ) * 20;
		return array_slice( $reviews, $offset, 20 );
	}

	private static function render_review_list( $admin_screen ) {
		if ( ! class_exists( 'MTAL_Suite_Reviews' ) || ! class_exists( 'MTAL_Suite_Registry' ) ) {
			echo '<p>بخش نقد و بررسی در دسترس نیست.</p>';
			return;
		}

		$page = isset( $_GET['review_page'] ) ? max( 1, absint( $_GET['review_page'] ) ) : 1;
		$total = (int) self::get_review_query( 1, true );
		$reviews = self::get_review_query( $page, false );
		if ( isset( $_GET['mtal_moderation_result'] ) ) {
			$result = sanitize_key( wp_unslash( $_GET['mtal_moderation_result'] ) );
			if ( 'approved' === $result ) {
				echo '<div class="' . ( $admin_screen ? 'notice notice-success' : 'woocommerce-message' ) . '"><p>نظر با موفقیت تأیید و منتشر شد.</p></div>';
			} elseif ( 'rejected' === $result ) {
				echo '<div class="' . ( $admin_screen ? 'notice notice-info' : 'woocommerce-info' ) . '"><p>نظر رد شد و از نمایش عمومی خارج شد؛ نویسنده می‌تواند آن را ویرایش و دوباره ارسال کند.</p></div>';
			}
		}
		if ( empty( $reviews ) ) {
			echo '<p>نظری برای بررسی وجود ندارد.</p>';
			return;
		}

		$base_url = $admin_screen
			? admin_url( 'admin.php?page=mtal-suite-review-management&review_page=%#%' )
			: add_query_arg( 'review_page', '%#%', wc_get_account_endpoint_url( self::ENDPOINT ) );

		echo '<style>
			.mtal-review-moderation-card{margin:18px 0;padding:18px;border:1px solid #dcdcde;border-radius:10px;background:#fff}
			.mtal-review-moderation-card__ratings{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;margin:12px 0}
			.mtal-review-moderation-card__actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
			.mtal-review-moderation-card__history{margin-top:14px;padding-top:12px;border-top:1px solid #eee}
			.mtal-review-moderation-card__history article{padding:10px;margin:8px 0;background:#f6f7f7}
		</style>';

		foreach ( $reviews as $review ) {
			$suite_id = absint( get_comment_meta( $review->comment_ID, MTAL_Suite_Reviews::META_SUITE_ID, true ) );
			if ( absint( $review->comment_post_ID ) !== $suite_id || ! MTAL_Suite_Reviews::can_manage_suite_reviews( $suite_id ) ) {
				continue;
			}
			if ( in_array( $review->comment_approved, array( 'spam', 'trash' ), true ) ) {
				continue;
			}
			$suite = MTAL_Suite_Registry::get_display_data( $suite_id );
			$suite_title = is_array( $suite ) && ! empty( $suite['title'] ) ? $suite['title'] : 'سوئیت #' . $suite_id;
			$rejected = '1' === (string) get_comment_meta( $review->comment_ID, self::REJECTED_META, true );
			$status = $rejected ? 'رد شده' : ( '1' === (string) $review->comment_approved ? 'منتشرشده' : 'در انتظار بررسی' );
			$title = (string) get_comment_meta( $review->comment_ID, '_mtal_review_title', true );
			$ratings = get_comment_meta( $review->comment_ID, MTAL_Suite_Reviews::META_RATINGS, true );

			echo '<article class="mtal-review-moderation-card">';
			echo '<h4>' . esc_html( $suite_title ) . '</h4>';
			echo '<p><strong>نویسنده:</strong> ' . esc_html( $review->comment_author ) . ' | <strong>تاریخ:</strong> ' . esc_html( get_date_from_gmt( $review->comment_date_gmt, 'Y/m/d H:i' ) ) . '</p>';
			echo '<p><strong>وضعیت:</strong> ' . esc_html( $status ) . ' | <strong>میانگین:</strong> ' . esc_html( get_comment_meta( $review->comment_ID, MTAL_Suite_Reviews::META_AVERAGE, true ) ) . ' از ۵</p>';
			if ( '' !== $title ) {
				echo '<h5>' . esc_html( $title ) . '</h5>';
			}
			echo '<div class="mtal-review-moderation-card__ratings">';
			foreach ( MTAL_Suite_Reviews::rating_criteria() as $key => $label ) {
				$value = isset( $ratings[ $key ] ) ? absint( $ratings[ $key ] ) : 0;
				echo '<div><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . ' از ۵</div>';
			}
			echo '</div>';
			echo '<div>' . nl2br( esc_html( $review->comment_content ) ) . '</div>';

			$history = MTAL_Suite_Reviews::get_review_history( $review->comment_ID );
			if ( ! empty( $history ) ) {
				echo '<details class="mtal-review-moderation-card__history"><summary>مشاهده تاریخچه ویرایش‌ها (' . esc_html( count( $history ) ) . ')</summary>';
				foreach ( array_reverse( $history ) as $version ) {
					echo '<article><p><strong>تاریخ تغییر:</strong> ' . esc_html( isset( $version['changed_at'] ) ? $version['changed_at'] : '' ) . '</p>';
					if ( ! empty( $version['title'] ) ) {
						echo '<h5>' . esc_html( $version['title'] ) . '</h5>';
					}
					echo '<p>' . nl2br( esc_html( isset( $version['content'] ) ? $version['content'] : '' ) ) . '</p>';
					if ( ! empty( $version['ratings'] ) && is_array( $version['ratings'] ) ) {
						foreach ( MTAL_Suite_Reviews::rating_criteria() as $key => $label ) {
							$value = isset( $version['ratings'][ $key ] ) ? absint( $version['ratings'][ $key ] ) : 0;
							echo '<span>' . esc_html( $label . ': ' . $value . ' از ۵' ) . '؛ </span>';
						}
					}
					echo '</article>';
				}
				echo '</details>';
			}

			echo '<div class="mtal-review-moderation-card__actions">';
			if ( '1' !== (string) $review->comment_approved || $rejected ) {
				self::render_moderation_form( $review->comment_ID, 'approve', 'تأیید و انتشار', $admin_screen );
			}
			if ( ! $rejected ) {
				self::render_moderation_form( $review->comment_ID, 'reject', 'رد نظر', $admin_screen );
			}
			echo '</div></article>';
		}

		$total_pages = (int) ceil( $total / 20 );
		if ( $total_pages > 1 ) {
			echo '<nav class="woocommerce-pagination" aria-label="صفحه‌بندی نظرات">';
			echo wp_kses_post( paginate_links( array(
				'base'      => $base_url,
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => '« قبلی',
				'next_text' => 'بعدی »',
			) ) );
			echo '</nav>';
		}
	}

	private static function render_moderation_form( $comment_id, $decision, $label, $admin_screen ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mtal_moderate_suite_review" />';
		echo '<input type="hidden" name="comment_id" value="' . absint( $comment_id ) . '" />';
		echo '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '" />';
		wp_nonce_field( 'mtal_moderate_suite_review_' . absint( $comment_id ) . '_' . $decision, 'mtal_review_moderation_nonce' );
		$class = 'approve' === $decision ? 'button button-primary' : 'button';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	public static function handle_moderation() {
		if ( ! is_user_logged_in() || ! self::can_open_management() ) {
			wp_die( esc_html__( 'شما اجازه مدیریت نظرات را ندارید.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}
		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		if ( ! $comment_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( esc_html__( 'درخواست مدیریت نظر معتبر نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'mtal_moderate_suite_review_' . $comment_id . '_' . $decision, 'mtal_review_moderation_nonce' );

		$review = get_comment( $comment_id );
		$suite_id = $review ? absint( get_comment_meta( $comment_id, MTAL_Suite_Reviews::META_SUITE_ID, true ) ) : 0;
		if (
			! $review ||
			MTAL_Suite_Reviews::COMMENT_TYPE !== $review->comment_type ||
			! $suite_id ||
			absint( $review->comment_post_ID ) !== $suite_id ||
			! MTAL_Suite_Reviews::can_manage_suite_reviews( $suite_id ) ||
			in_array( $review->comment_approved, array( 'spam', 'trash' ), true )
		) {
			wp_die( esc_html__( 'این نظر در محدوده دسترسی شما نیست.', 'mtal-persian-calendar' ), '', array( 'response' => 403 ) );
		}

		$lock_key = 'mtal_suite_review_moderation_lock_' . $comment_id;
		if ( ! MTAL_Suite_Reviews::acquire_lock( $lock_key ) ) {
			wp_die( esc_html__( 'درخواست دیگری برای این نظر در حال انجام است. کمی بعد دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 409 ) );
		}

		if ( 'approve' === $decision ) {
			$updated = wp_set_comment_status( $comment_id, 'approve' );
			if ( $updated ) {
				delete_comment_meta( $comment_id, self::REJECTED_META );
			}
		} else {
			$updated = wp_set_comment_status( $comment_id, 'hold' );
			if ( $updated ) {
				update_comment_meta( $comment_id, self::REJECTED_META, '1' );
			}
		}
		MTAL_Suite_Reviews::release_lock( $lock_key );
		if ( ! $updated ) {
			wp_die( esc_html__( 'تغییر وضعیت نظر انجام نشد. لطفاً دوباره تلاش کنید.', 'mtal-persian-calendar' ), '', array( 'response' => 500 ) );
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = current_user_can( 'manage_options' )
				? admin_url( 'admin.php?page=mtal-suite-review-management' )
				: wc_get_account_endpoint_url( self::ENDPOINT );
		}
		wp_safe_redirect( add_query_arg( 'mtal_moderation_result', 'approve' === $decision ? 'approved' : 'rejected', $redirect ) );
		exit;
	}
}

MTAL_Suite_Review_Management::init();
