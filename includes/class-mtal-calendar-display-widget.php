<?php
/**
 * MTAL customer-facing inline availability calendar with a booking hand-off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

class MTAL_Calendar_Display_Widget extends Widget_Base {

	public function get_name() {
		return 'mtal_calendar_display';
	}

	public function get_title() {
		return esc_html__( 'تقویم نمایش و رزرو MTAL', 'mtal-persian-calendar' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_style_depends() {
		return array( 'mtal-persian-calendar-css' );
	}

	public function get_script_depends() {
		return array( 'mtal-persian-calendar-display' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_display_settings',
			array(
				'label' => esc_html__( 'تنظیمات تقویم رزرو MTAL', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'target_widget_selector',
			array(
				'label'       => esc_html__( 'سلکتور ویجت اصلی انتخاب مشتری', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '.mtal-cal-inline-host, .mtal-cal-modal-host',
				'description' => esc_html__( 'در صورت وجود چند ویجت انتخاب مشتری، کلاس یا شناسه ویجت مقصد را وارد کنید. ویجت مقصد باید در همین صفحه باشد.', 'mtal-persian-calendar' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Return only suites that have a published source page and are visible
	 * in customer booking entry points.
	 */
	private function get_bookable_suites() {
		if ( ! class_exists( 'MTAL_Suite_Registry' ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => MTAL_Suite_Registry::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$suites = array();
		foreach ( $query->posts as $suite_id ) {
			$suite_id = absint( $suite_id );
			if ( MTAL_Suite_Registry::is_hidden( $suite_id ) ) {
				continue;
			}

			$source = MTAL_Suite_Registry::get_source_post( $suite_id );
			if ( ! $source || 'publish' !== $source->post_status ) {
				continue;
			}

			$display = MTAL_Suite_Registry::get_display_data( $suite_id );
			if ( ! $display ) {
				continue;
			}

			$suites[] = array(
				'referenceId' => absint( $source->ID ),
				'suiteId'     => $suite_id,
				'title'       => $display['title'],
				'url'         => get_permalink( $source->ID ),
			);
		}

		usort(
			$suites,
			static function ( $a, $b ) {
				return absint( $a['suiteId'] ) <=> absint( $b['suiteId'] );
			}
		);

		return $suites;
	}

	protected function render() {
		$settings          = $this->get_settings_for_display();
		$target_selector   = ! empty( $settings['target_widget_selector'] ) ? $settings['target_widget_selector'] : '.mtal-cal-inline-host, .mtal-cal-modal-host';
		$current_post_id   = absint( get_queried_object_id() );
		$current_post_type = $current_post_id ? get_post_type( $current_post_id ) : '';
		$locked_suite_id   = $current_post_id && class_exists( 'MTAL_Suite_Registry' )
			? MTAL_Suite_Registry::get_suite_by_source( $current_post_id, $current_post_type )
			: 0;
		$suites            = $this->get_bookable_suites();
		$selected_suite    = 0;

		if ( $locked_suite_id ) {
			foreach ( $suites as $suite ) {
				if ( absint( $suite['suiteId'] ) === absint( $locked_suite_id ) ) {
					$selected_suite = $suite;
					break;
				}
			}
		}

		$widget_id = 'mtal-calendar-display-' . esc_attr( $this->get_id() );
		$locked    = (bool) $locked_suite_id;
		$display_suites = $locked ? ( $selected_suite ? array( $selected_suite ) : array() ) : $suites;
		?>
		<div
			id="<?php echo esc_attr( $widget_id ); ?>"
			class="mtal-cal-display-host"
			data-mtal-display-locked="<?php echo $locked ? '1' : '0'; ?>"
			data-mtal-display-suite="<?php echo $selected_suite ? esc_attr( $selected_suite['referenceId'] ) : '0'; ?>"
			data-mtal-target-widget="<?php echo esc_attr( $target_selector ); ?>"
			data-mtal-suites="<?php echo esc_attr( wp_json_encode( $suites ) ); ?>"
		>
			<div class="mtal-cal-display-toolbar" dir="rtl">
				<label class="mtal-cal-display-room-label" for="<?php echo esc_attr( $widget_id . '-room' ); ?>">انتخاب سوئیت</label>
				<select class="mtal-cal-display-room" id="<?php echo esc_attr( $widget_id . '-room' ); ?>" <?php disabled( $locked || empty( $suites ) ); ?>>
					<?php if ( ! $locked ) : ?>
						<option value="">ابتدا سوئیت موردنظر را انتخاب کنید</option>
					<?php endif; ?>
					<?php if ( $locked && ! $selected_suite ) : ?>
						<option value="">این سوئیت در حال حاضر قابل رزرو نیست</option>
					<?php endif; ?>
					<?php foreach ( $display_suites as $suite ) : ?>
						<option value="<?php echo esc_attr( $suite['referenceId'] ); ?>" data-suite-url="<?php echo esc_url( $suite['url'] ); ?>" <?php selected( $locked ? $selected_suite['referenceId'] : 0, $suite['referenceId'] ); ?>>
							<?php echo esc_html( $suite['title'] ); ?>
						</option>
						<?php endforeach; ?>
				</select>
			</div>
			<?php if ( ! $locked ) : ?>
				<p class="mtal-cal-display-message mtal-cal-display-guide" role="note">این تقویم برای مشاهده روزهای باز و بسته و برآورد قیمت اقامت است. برای ثبت رزرو، پس از انتخاب سوئیت وارد صفحه اختصاصی آن شوید و تاریخ ورود و خروج را مجدداً در تقویم همان صفحه انتخاب کنید.</p>
			<?php endif; ?>
			<?php if ( empty( $display_suites ) ) : ?>
				<p class="mtal-cal-display-message" role="status">در حال حاضر سوئیت قابل رزروی برای نمایش وجود ندارد.</p>
			<?php endif; ?>
			<div class="mtal-cal-display-calendar" hidden>
				<div class="mtal-cal-display-mount"></div>
				<div class="mtal-cal-display-pricing" hidden aria-live="polite"></div>
				<div class="mtal-cal-display-actions" dir="rtl">
					<button type="button" class="mtal-cal-clear-btn mtal-cal-display-clear">پاک کردن</button>
					<button type="button" class="mtal-cal-confirm-btn mtal-cal-display-book" disabled><?php echo $locked ? 'رزرو' : 'مشاهده سوئیت و رزرو'; ?></button>
				</div>
				<p class="mtal-cal-display-message" role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}
}
