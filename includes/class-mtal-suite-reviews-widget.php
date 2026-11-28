<?php
/**
 * Public Elementor widget for verified MTAL suite reviews.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

final class MTAL_Suite_Reviews_Widget extends Widget_Base {

	public function get_name() {
		return 'mtal_suite_reviews';
	}

	public function get_title() {
		return esc_html__( 'نقد و بررسی سوئیت MTAL', 'mtal-persian-calendar' );
	}

	public function get_icon() {
		return 'eicon-testimonial';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_reviews_settings',
			array(
				'label' => esc_html__( 'تنظیمات نقد و بررسی', 'mtal-persian-calendar' ),
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'       => esc_html__( 'عنوان بخش', 'mtal-persian-calendar' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'نظرات مهمانان', 'mtal-persian-calendar' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'review_count',
			array(
				'label'   => esc_html__( 'تعداد نقدهای قابل نمایش', 'mtal-persian-calendar' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 20,
				'step'    => 1,
				'default' => 5,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Resolve the independent suite connected to the current public source post.
	 */
	private function get_current_suite_id() {
		$post_id = absint( get_queried_object_id() );
		if ( ! $post_id || ! class_exists( 'MTAL_Suite_Registry' ) ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 0;
		}

		$post_type = $post->post_type;
		if ( MTAL_Suite_Registry::POST_TYPE === $post_type ) {
			return $post_id;
		}

		return absint( MTAL_Suite_Registry::get_suite_by_source( $post_id, $post_type ) );
	}

	protected function render() {
		if ( ! class_exists( 'MTAL_Suite_Reviews' ) || ! class_exists( 'MTAL_Suite_Registry' ) ) {
			return;
		}

		$suite_id = $this->get_current_suite_id();
		if ( ! $suite_id || ! MTAL_Suite_Registry::is_suite( $suite_id ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="mtal-suite-reviews-empty">این ویجت را در صفحه متصل به یک سوئیت قرار دهید.</div>';
			}
			return;
		}

		$settings = $this->get_settings_for_display();
		$heading  = isset( $settings['heading'] ) ? trim( (string) $settings['heading'] ) : '';
		$limit    = isset( $settings['review_count'] ) ? absint( $settings['review_count'] ) : 5;
		$limit    = max( 1, min( 20, $limit ) );
		$summary  = MTAL_Suite_Reviews::get_public_summary( $suite_id );
		$reviews  = MTAL_Suite_Reviews::get_public_reviews( $suite_id, $limit );
		$average  = isset( $summary['average'] ) ? (float) $summary['average'] : 0;
		$count    = isset( $summary['count'] ) ? absint( $summary['count'] ) : 0;
		$filled_stars = max( 0, min( 5, (int) round( $average ) ) );
		$empty_stars  = 5 - $filled_stars;
		?>
		<section class="mtal-suite-reviews" dir="rtl" aria-label="<?php echo esc_attr( '' !== $heading ? $heading : 'نقد و بررسی سوئیت' ); ?>">
			<style>
				.mtal-suite-reviews{width:100%;color:inherit}
				.mtal-suite-reviews__heading{margin:0 0 16px}
				.mtal-suite-reviews__summary{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 20px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px}
				.mtal-suite-reviews__score{font-size:26px;font-weight:700;line-height:1.2}
				.mtal-suite-reviews__stars{color:#d69e18;letter-spacing:2px;white-space:nowrap}
				.mtal-suite-reviews__count{font-size:14px;opacity:.8}
				.mtal-suite-reviews__list{display:grid;gap:14px}
				.mtal-suite-reviews__item{padding:16px;border:1px solid #e5e7eb;border-radius:12px}
				.mtal-suite-reviews__item-title{margin:0 0 8px;font-size:16px}
				.mtal-suite-reviews__meta{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 10px;font-size:13px;opacity:.72}
				.mtal-suite-reviews__content{margin:0;line-height:1.9;white-space:normal;overflow-wrap:anywhere}
				.mtal-suite-reviews__empty{padding:16px;border:1px dashed #d1d5db;border-radius:12px}
			</style>
			<?php if ( '' !== $heading ) : ?>
				<h3 class="mtal-suite-reviews__heading"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>

			<div class="mtal-suite-reviews__summary" aria-label="<?php echo esc_attr( sprintf( 'میانگین امتیاز %s از ۵ بر اساس %d نقد', number_format_i18n( $average, 2 ), $count ) ); ?>">
				<strong class="mtal-suite-reviews__score"><?php echo esc_html( number_format_i18n( $average, 2 ) ); ?><small> / ۵</small></strong>
				<span class="mtal-suite-reviews__stars" aria-hidden="true"><?php echo esc_html( str_repeat( '★', $filled_stars ) . str_repeat( '☆', $empty_stars ) ); ?></span>
				<span class="mtal-suite-reviews__count"><?php echo esc_html( number_format_i18n( $count ) ); ?> نقد تأییدشده</span>
			</div>

			<?php if ( empty( $reviews ) ) : ?>
				<p class="mtal-suite-reviews__empty">هنوز نقد و بررسی تأییدشده‌ای برای این سوئیت ثبت نشده است.</p>
			<?php else : ?>
				<div class="mtal-suite-reviews__list">
					<?php foreach ( $reviews as $review ) : ?>
						<article class="mtal-suite-reviews__item">
							<?php if ( '' !== $review['title'] ) : ?>
								<h4 class="mtal-suite-reviews__item-title"><?php echo esc_html( $review['title'] ); ?></h4>
							<?php endif; ?>
							<p class="mtal-suite-reviews__meta">
								<span><?php echo esc_html( $review['author'] ); ?></span>
								<time><?php echo esc_html( $review['date'] ); ?></time>
							</p>
							<p class="mtal-suite-reviews__content"><?php echo nl2br( esc_html( $review['content'] ) ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
