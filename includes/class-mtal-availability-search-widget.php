<?php
/**
 * MTAL availability search widget for the homepage hero.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;

class MTAL_Availability_Search_Widget extends Widget_Base {

	public function get_name() {
		return 'mtal_availability_search';
	}

	public function get_title() {
		return esc_html__( 'جستجوی سوئیت‌های قابل رزرو MTAL', 'mtal-persian-calendar' );
	}

	public function get_icon() {
		return 'eicon-search';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_style_depends() {
		return array( 'mtal-persian-calendar-css' );
	}

	public function get_script_depends() {
		return array( 'mtal-availability-search' );
	}

	protected function render() {
		$widget_id = 'mtal-availability-search-' . esc_attr( $this->get_id() );
		?>
		<div id="<?php echo esc_attr( $widget_id ); ?>" class="mtal-availability-search" dir="rtl">
			<div class="mtal-availability-search-fields">
				<button type="button" class="mtal-availability-date-field" data-date-field="check-in" aria-label="انتخاب تاریخ ورود">
					<span class="mtal-availability-date-label">تاریخ ورود</span>
					<span class="mtal-availability-date-value">انتخاب تاریخ</span>
					<span class="mtal-availability-date-icon" aria-hidden="true">▦</span>
				</button>
				<button type="button" class="mtal-availability-date-field" data-date-field="check-out" aria-label="انتخاب تاریخ خروج">
					<span class="mtal-availability-date-label">تاریخ خروج</span>
					<span class="mtal-availability-date-value">انتخاب تاریخ</span>
					<span class="mtal-availability-date-icon" aria-hidden="true">▦</span>
				</button>
				<button type="button" class="mtal-availability-search-button" hidden disabled>جستجو</button>
			</div>
			<div class="mtal-availability-calendar-source" aria-hidden="true"></div>
			<div class="mtal-availability-search-status" role="status" aria-live="polite" hidden></div>
			<div class="mtal-availability-search-results" hidden></div>
		</div>
		<?php
	}
}
