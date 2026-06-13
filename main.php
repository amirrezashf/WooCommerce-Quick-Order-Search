<?php
/**
 * Plugin Name: WooCommerce Quick Order Search
 * Plugin URI: https://github.com/yourusername/woocommerce-quick-order-search
 * Description: Quick admin-side WooCommerce order search by order ID or customer phone number.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://github.com/amirrezashf
 * License: GPL v2 or later
 * Text Domain: woocommerce-quick-order-search
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WQOS_Quick_Order_Search {

	const VERSION = '1.0.0';
	const AJAX_ACTION = 'wqos_quick_order_search';
	const NONCE_ACTION = 'wqos_quick_order_search_nonce';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_ui' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_search' ) );

		add_action( 'woocommerce_update_order', array( $this, 'clear_order_card_cache' ) );
		add_action( 'woocommerce_new_order', array( $this, 'clear_order_card_cache' ) );

		add_action( 'before_delete_post', array( $this, 'clear_deleted_order_cache' ) );
	}

	private function is_allowed() {
		return is_admin() && current_user_can( 'manage_woocommerce' );
	}

	private function normalize_digits( $value ) {
		$value = (string) $value;

		$persian = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
		$arabic  = array( '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
		$english = array( '0','1','2','3','4','5','6','7','8','9' );

		$value = str_replace( $persian, $english, $value );
		$value = str_replace( $arabic, $english, $value );

		return $value;
	}

	private function digits_only( $value ) {
		$value = $this->normalize_digits( $value );
		return preg_replace( '/\D+/', '', $value );
	}

	private function get_phone_variants( $raw_phone ) {
		$digits = $this->digits_only( $raw_phone );

		if ( empty( $digits ) ) {
			return array();
		}

		$variants   = array();
		$variants[] = $digits;

		if ( strpos( $digits, '0098' ) === 0 ) {
			$local = substr( $digits, 4 );

			if ( $local !== '' ) {
				$variants[] = $local;
				$variants[] = '0' . $local;
				$variants[] = '98' . $local;
				$variants[] = '0098' . $local;
			}
		}

		if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) >= 12 ) {
			$local = substr( $digits, 2 );

			if ( $local !== '' ) {
				$variants[] = $local;
				$variants[] = '0' . $local;
				$variants[] = '98' . $local;
				$variants[] = '0098' . $local;
			}
		}

		if ( strpos( $digits, '0' ) === 0 && strlen( $digits ) >= 11 ) {
			$local = ltrim( $digits, '0' );

			if ( $local !== '' ) {
				$variants[] = $local;
				$variants[] = '0' . $local;
				$variants[] = '98' . $local;
				$variants[] = '0098' . $local;
			}
		}

		if ( strlen( $digits ) === 10 && strpos( $digits, '9' ) === 0 ) {
			$variants[] = $digits;
			$variants[] = '0' . $digits;
			$variants[] = '98' . $digits;
			$variants[] = '0098' . $digits;
		}

		$variants = array_map(
			function( $item ) {
				return preg_replace( '/\D+/', '', (string) $item );
			},
			$variants
		);

		$variants = array_filter( array_unique( $variants ) );

		return array_values( $variants );
	}

	private function sql_phone_cleanup_expr( $column ) {
		return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', ''), '/', '')";
	}

	private function is_hpos_enabled() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private function get_order_card_cache_key( $order_id ) {
		return 'wqos_card_' . absint( $order_id );
	}

	public function clear_order_card_cache( $order_id ) {
		delete_transient( $this->get_order_card_cache_key( $order_id ) );
	}

	public function clear_deleted_order_cache( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$this->clear_order_card_cache( $post_id );
		}
	}

	private function get_order_ids_by_phone( $raw_phone, $limit = 5 ) {
		global $wpdb;

		$limit    = max( 1, absint( $limit ) );
		$variants = $this->get_phone_variants( $raw_phone );

		if ( empty( $variants ) ) {
			return array();
		}

		if ( $this->is_hpos_enabled() ) {
			$orders_table    = $wpdb->prefix . 'wc_orders';
			$addresses_table = $wpdb->prefix . 'wc_order_addresses';
			$phone_expr      = $this->sql_phone_cleanup_expr( 'a.phone' );

			$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );

			$sql = "
				SELECT DISTINCT o.id
				FROM {$orders_table} o
				INNER JOIN {$addresses_table} a ON a.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND a.address_type = 'billing'
				  AND {$phone_expr} IN ({$placeholders})
				ORDER BY o.date_created_gmt DESC
				LIMIT %d
			";

			$args   = $variants;
			$args[] = $limit;

			$order_ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		} else {
			$posts      = $wpdb->posts;
			$postmeta   = $wpdb->postmeta;
			$phone_expr = $this->sql_phone_cleanup_expr( 'pm.meta_value' );

			$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );

			$sql = "
				SELECT DISTINCT p.ID
				FROM {$posts} p
				INNER JOIN {$postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND pm.meta_key = '_billing_phone'
				  AND {$phone_expr} IN ({$placeholders})
				ORDER BY p.ID DESC
				LIMIT %d
			";

			$args   = $variants;
			$args[] = $limit;

			$order_ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		}

		$order_ids = array_map( 'absint', (array) $order_ids );
		$order_ids = array_values( array_filter( array_unique( $order_ids ) ) );

		return $order_ids;
	}

	private function get_customer_orders_link( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}

		$user_id = $order->get_user_id();

		if ( $user_id > 0 ) {
			return admin_url( 'edit.php?post_type=shop_order&_customer_user=' . absint( $user_id ) );
		}

		$email = $order->get_billing_email();

		if ( $email ) {
			return admin_url( 'edit.php?post_type=shop_order&_billing_email=' . rawurlencode( $email ) );
		}

		$phone = $order->get_billing_phone();

		if ( $phone ) {
			return admin_url( 'edit.php?post_type=shop_order&s=' . rawurlencode( $phone ) );
		}

		return '';
	}

	private function get_customer_profile_link( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}

		$user_id = $order->get_user_id();

		if ( $user_id > 0 ) {
			return admin_url( 'user-edit.php?user_id=' . absint( $user_id ) );
		}

		return '';
	}

	private function copy_button( $value, $label = '' ) {
		$value = (string) $value;

		if ( '' === trim( $value ) || '—' === trim( $value ) ) {
			return '';
		}

		ob_start();
		?>
		<button
			type="button"
			class="wqos-copy-btn"
			data-copy="<?php echo esc_attr( $value ); ?>"
			aria-label="<?php echo esc_attr( $label ? $label : 'کپی' ); ?>"
			title="<?php echo esc_attr( $label ? $label : 'کپی' ); ?>"
		>
			<svg viewBox="0 0 24 24" aria-hidden="true">
				<rect x="9" y="9" width="10" height="10" rx="2"></rect>
				<path d="M7 15H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v1"></path>
			</svg>
		</button>
		<?php
		return ob_get_clean();
	}

	private function format_order_card( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}

		$order_id  = $order->get_id();
		$cache_key = $this->get_order_card_cache_key( $order_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$order_number   = $order->get_order_number();
		$customer_name  = trim( wp_strip_all_tags( $order->get_formatted_billing_full_name() ) );
		$customer_phone = $order->get_billing_phone();
		$customer_email = $order->get_billing_email();
		$status_label   = wc_get_order_status_name( $order->get_status() );
		$total          = $order->get_formatted_order_total();
		$date_created   = $order->get_date_created();
		$date_text      = $date_created ? $date_created->date_i18n( 'Y/m/d - H:i' ) : 'نامشخص';
		$item_count     = $order->get_item_count();
		$payment_method = $order->get_payment_method_title();
		$user_id        = $order->get_user_id();

		$edit_link             = admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
		$customer_profile_link = $this->get_customer_profile_link( $order );
		$customer_orders_link  = $this->get_customer_orders_link( $order );

		if ( '' === $customer_name ) {
			$customer_name = 'بدون نام';
		}

		if ( '' === $customer_phone ) {
			$customer_phone = '—';
		}

		if ( '' === $customer_email ) {
			$customer_email = '—';
		}

		if ( '' === $payment_method ) {
			$payment_method = 'نامشخص';
		}

		$user_badge = $user_id > 0 ? 'کاربر' : 'مهمان';

		ob_start();
		?>
		<div class="wqos-card">
			<div class="wqos-card-top">
				<div class="wqos-head-right">
					<div class="wqos-order-line">
						<div class="wqos-order-id">سفارش #<?php echo esc_html( $order_number ); ?></div>
						<?php echo $this->copy_button( $order_number, 'کپی شماره سفارش' ); ?>
					</div>

					<div class="wqos-meta-badges">
						<span class="wqos-badge wqos-badge-status"><?php echo esc_html( $status_label ); ?></span>
						<span class="wqos-badge wqos-badge-user"><?php echo esc_html( $user_badge ); ?></span>
					</div>
				</div>

				<div class="wqos-card-total"><?php echo wp_kses_post( $total ); ?></div>
			</div>

			<div class="wqos-grid">
				<div class="wqos-row">
					<span class="wqos-label">خریدار</span>
					<span class="wqos-value"><?php echo esc_html( $customer_name ); ?></span>
				</div>

				<div class="wqos-row">
					<span class="wqos-label">موبایل</span>
					<span class="wqos-value-wrap">
						<span class="wqos-value"><?php echo esc_html( $customer_phone ); ?></span>
						<?php echo $this->copy_button( $customer_phone, 'کپی شماره موبایل' ); ?>
					</span>
				</div>

				<div class="wqos-row">
					<span class="wqos-label">ایمیل</span>
					<span class="wqos-value-wrap">
						<span class="wqos-value"><?php echo esc_html( $customer_email ); ?></span>
						<?php echo $this->copy_button( $customer_email, 'کپی ایمیل' ); ?>
					</span>
				</div>

				<div class="wqos-row">
					<span class="wqos-label">تاریخ ثبت</span>
					<span class="wqos-value"><?php echo esc_html( $date_text ); ?></span>
				</div>

				<div class="wqos-row">
					<span class="wqos-label">تعداد آیتم</span>
					<span class="wqos-value"><?php echo esc_html( $item_count ); ?></span>
				</div>

				<div class="wqos-row">
					<span class="wqos-label">روش پرداخت</span>
					<span class="wqos-value"><?php echo esc_html( $payment_method ); ?></span>
				</div>
			</div>

			<div class="wqos-actions">
				<a href="<?php echo esc_url( $edit_link ); ?>" class="wqos-action wqos-action-primary">مشاهده سفارش</a>

				<?php if ( $customer_profile_link ) : ?>
					<a href="<?php echo esc_url( $customer_profile_link ); ?>" class="wqos-action">پروفایل کاربر</a>
				<?php endif; ?>

				<?php if ( $customer_orders_link ) : ?>
					<a href="<?php echo esc_url( $customer_orders_link ); ?>" class="wqos-action">سفارشات مشتری</a>
				<?php endif; ?>
			</div>
		</div>
		<?php

		$html = ob_get_clean();

		set_transient( $cache_key, $html, 5 * MINUTE_IN_SECONDS );

		return $html;
	}

	public function ajax_search() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => 'دسترسی غیرمجاز.',
				),
				403
			);
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$query = isset( $_POST['query'] ) ? wp_unslash( $_POST['query'] ) : '';
		$query = sanitize_text_field( $query );
		$query = $this->digits_only( $query );

		if ( '' === $query ) {
			wp_send_json_error(
				array(
					'message' => 'لطفا شماره سفارش یا شماره موبایل را وارد کنید.',
				),
				400
			);
		}

		$order_ids = array();

		if ( preg_match( '/^\d+$/', $query ) ) {
			$order_id = absint( $query );

			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );

				if ( $order ) {
					$order_ids[] = $order_id;
				}
			}
		}

		if ( empty( $order_ids ) ) {
			$order_ids = $this->get_order_ids_by_phone( $query, 5 );
		}

		if ( empty( $order_ids ) ) {
			wp_send_json_success(
				array(
					'html' => '<div class="wqos-state wqos-state-error">نتیجه‌ای پیدا نشد.</div>',
				)
			);
		}

		$html = '';

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$html .= $this->format_order_card( $order );
			}
		}

		if ( '' === $html ) {
			$html = '<div class="wqos-state wqos-state-error">نتیجه‌ای پیدا نشد.</div>';
		}

		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	public function admin_assets() {
		if ( ! $this->is_allowed() ) {
			return;
		}

		wp_register_style( 'wqos-inline', false, array(), self::VERSION );
		wp_enqueue_style( 'wqos-inline' );

		$css = "
			#wqos-trigger{
				position:fixed;
				left:20px;
				bottom:20px;
				z-index:99999;
				width:58px;
				height:58px;
				border:none;
				border-radius:18px;
				background:#6d28d9;
				color:#ffffff;
				cursor:pointer;
				display:flex;
				align-items:center;
				justify-content:center;
				box-shadow:0 14px 30px rgba(109,40,217,.24);
				transition:transform .18s ease, box-shadow .18s ease;
			}
			#wqos-trigger:hover{
				transform:translateY(-2px);
				box-shadow:0 16px 34px rgba(109,40,217,.28);
			}
			#wqos-trigger svg{
				width:22px;
				height:22px;
				fill:none;
				stroke:currentColor;
				stroke-width:2;
				stroke-linecap:round;
				stroke-linejoin:round;
			}
			#wqos-modal{
				position:fixed;
				inset:0;
				z-index:100000;
				display:none;
			}
			#wqos-modal.wqos-open{
				display:block;
			}
			.wqos-backdrop{
				position:absolute;
				inset:0;
				background:rgba(15,23,42,.42);
				backdrop-filter:blur(4px);
			}
			.wqos-panel{
				position:absolute;
				left:50%;
				top:6vh;
				transform:translateX(-50%);
				width:min(840px, calc(100vw - 28px));
				max-height:87vh;
				background:#f5f3ff;
				border:1px solid #ddd6fe;
				border-radius:18px;
				box-shadow:0 28px 70px rgba(15,23,42,.20);
				overflow:hidden;
			}
			.wqos-header{
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:12px;
				padding:18px 20px;
				border-bottom:1px solid #ddd6fe;
				background:#ede9fe;
			}
			.wqos-title-wrap{
				display:flex;
				flex-direction:column;
				gap:4px;
			}
			.wqos-title{
				font-size:16px;
				font-weight:800;
				color:#3b0764;
				line-height:1.4;
			}
			.wqos-subtitle{
				font-size:12px;
				color:#6b7280;
				line-height:1.7;
			}
			.wqos-close{
				width:40px;
				height:40px;
				border:none;
				border-radius:14px;
				background:#ffffff;
				color:#6d28d9;
				cursor:pointer;
				font-size:20px;
				line-height:1;
				box-shadow:none;
				transition:background .18s ease, transform .18s ease;
			}
			.wqos-close:hover{
				background:#f3f4f6;
				transform:scale(1.03);
			}
			.wqos-body{
				padding:18px;
				background:#f5f3ff;
			}
			.wqos-search-box{
				background:#ede9fe;
				border:1px solid #ddd6fe;
				border-radius:18px;
				padding:13px;
				margin-bottom:16px;
			}
			.wqos-search-wrap{
				display:flex;
				gap:10px;
				align-items:center;
			}
			.wqos-input-wrap{
				position:relative;
				flex:1;
			}
			.wqos-input{
				width:100%;
				height:50px;
				padding:0 16px;
				border:1px solid #c4b5fd;
				border-radius:15px;
				background:#ffffff;
				font-size:14px;
				color:#111827;
				outline:none;
				box-shadow:none;
				direction:ltr;
				text-align:left;
				transition:border-color .18s ease, box-shadow .18s ease;
			}
			.wqos-input::placeholder{
				color:#6b7280;
			}
			.wqos-input:focus{
				border-color:#8b5cf6;
				box-shadow:0 0 0 4px rgba(139,92,246,.10);
			}
			.wqos-btn{
				height:50px;
				padding:0 20px;
				border:none;
				border-radius:15px;
				background:#6d28d9;
				color:#ffffff;
				cursor:pointer;
				font-size:13px;
				font-weight:800;
				white-space:nowrap;
				transition:transform .18s ease, opacity .18s ease;
			}
			.wqos-btn:hover{
				transform:translateY(-1px);
			}
			.wqos-help{
				margin-top:10px;
				font-size:12px;
				color:#6b7280;
				line-height:1.8;
			}
			.wqos-results{
				display:flex;
				flex-direction:column;
				gap:12px;
				max-height:56vh;
				overflow:auto;
				padding:2px;
			}
			.wqos-card{
				background:#ffffff;
				border:1px solid #e5e7eb;
				border-radius:18px;
				padding:16px;
				box-shadow:0 4px 14px rgba(15,23,42,.04);
			}
			.wqos-card-top{
				display:flex;
				align-items:flex-start;
				justify-content:space-between;
				gap:14px;
				margin-bottom:14px;
			}
			.wqos-head-right{
				display:flex;
				flex-direction:column;
				gap:8px;
				min-width:0;
			}
			.wqos-order-line{
				display:flex;
				align-items:center;
				gap:8px;
				flex-wrap:wrap;
			}
			.wqos-order-id{
				font-size:15px;
				font-weight:800;
				color:#4c1d95;
			}
			.wqos-meta-badges{
				display:flex;
				flex-wrap:wrap;
				gap:8px;
			}
			.wqos-badge{
				display:inline-flex;
				align-items:center;
				min-height:29px;
				padding:0 10px;
				border-radius:999px;
				font-size:12px;
				font-weight:700;
			}
			.wqos-badge-status{
				background:#f5f3ff;
				color:#5b21b6;
				border:1px solid #ddd6fe;
			}
			.wqos-badge-user{
				background:#f9fafb;
				color:#4b5563;
				border:1px solid #e5e7eb;
			}
			.wqos-card-total{
				font-size:14px;
				font-weight:800;
				color:#166534;
				background:#f0fdf4;
				border:1px solid #bbf7d0;
				padding:10px 13px;
				border-radius:14px;
				white-space:nowrap;
			}
			.wqos-grid{
				display:grid;
				grid-template-columns:repeat(2, minmax(0,1fr));
				gap:12px 16px;
			}
			.wqos-row{
				display:flex;
				flex-direction:column;
				gap:5px;
				min-width:0;
				padding:6px 12px;
				background:#fafafa;
				border:1px solid #eeeeee;
				border-radius:14px;
			}
			.wqos-label{
				font-size:11px;
				color:#6b7280;
				line-height:1.7;
			}
			.wqos-value-wrap{
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:8px;
				min-width:0;
			}
			.wqos-value{
				font-size:13px;
				font-weight:700;
				color:#111827;
				line-height:1.8;
				word-break:break-word;
				min-width:0;
			}
			.wqos-copy-btn{
				flex:0 0 auto;
				width:30px;
				height:30px;
				display:inline-flex;
				align-items:center;
				justify-content:center;
				border:none;
				border-radius:10px;
				background:#f5f3ff;
				color:#6d28d9;
				cursor:pointer;
				transition:transform .18s ease, background .18s ease;
			}
			.wqos-copy-btn:hover{
				transform:translateY(-1px);
				background:#ede9fe;
			}
			.wqos-copy-btn svg{
				width:16px;
				height:16px;
				fill:none;
				stroke:currentColor;
				stroke-width:2;
				stroke-linecap:round;
				stroke-linejoin:round;
			}
			.wqos-actions{
				display:flex;
				flex-wrap:wrap;
				gap:10px;
				margin-top:15px;
			}
			.wqos-action{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				min-height:40px;
				padding:0 14px;
				border-radius:13px;
				text-decoration:none;
				font-size:12px;
				font-weight:800;
				background:#ffffff;
				color:#374151;
				border:1px solid #e5e7eb;
				transition:transform .18s ease, background .18s ease;
			}
			.wqos-action:hover{
				transform:translateY(-1px);
				background:#f9fafb;
			}
			.wqos-action-primary{
				background:#6d28d9;
				color:#ffffff;
				border-color:#6d28d9;
			}
			.wqos-action-primary:hover{
				color:#ffffff;
				background:#5b21b6;
			}
			.wqos-state{
				background:#ffffff;
				border:1px solid #e5e7eb;
				border-radius:18px;
				padding:20px;
				font-size:13px;
				color:#4b5563;
				line-height:1.9;
				text-align:center;
			}
			.wqos-state-error{
				color:#dc2626;
				font-weight:800;
				border-color:#fecaca;
				background:#fff7f7;
			}
			.wqos-toast{
				position:fixed;
				left:24px;
				bottom:92px;
				z-index:100001;
				background:#111827;
				color:#ffffff;
				padding:10px 14px;
				border-radius:14px;
				font-size:12px;
				font-weight:700;
				box-shadow:0 16px 34px rgba(15,23,42,.24);
				opacity:0;
				transform:translateY(8px);
				pointer-events:none;
				transition:all .2s ease;
			}
			.wqos-toast.wqos-show{
				opacity:1;
				transform:translateY(0);
			}
			@media (max-width: 782px){
				.wqos-panel{
					top:3vh;
					width:calc(100vw - 16px);
					max-height:92vh;
					border-radius:18px;
				}
				.wqos-search-wrap{
					flex-direction:column;
					align-items:stretch;
				}
				.wqos-btn{
					width:100%;
				}
				.wqos-grid{
					grid-template-columns:1fr;
				}
				.wqos-card-top{
					flex-direction:column;
				}
				#wqos-trigger{
					left:14px;
					bottom:14px;
					width:54px;
					height:54px;
					border-radius:16px;
				}
				.wqos-toast{
					left:14px;
					right:14px;
					bottom:78px;
					text-align:center;
				}
			}
		";

		wp_add_inline_style( 'wqos-inline', $css );

		wp_register_script( 'wqos-inline', '', array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( 'wqos-inline' );

		$data = array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
			'action'       => self::AJAX_ACTION,
			'copy_success' => 'کپی شد',
			'copy_failed'  => 'کپی انجام نشد',
		);

		wp_add_inline_script(
			'wqos-inline',
			'window.wqosQuickSearch = ' . wp_json_encode( $data ) . ';',
			'before'
		);

		$js = <<<'JS'
jQuery(function($){
	var modal   = $('#wqos-modal');
	var input   = $('#wqos-input');
	var results = $('#wqos-results');
	var xhr     = null;

	function normalizeDigits(str){
		if(!str){ return ''; }

		var persian = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9'};
		var arabic  = {'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};

		return String(str)
			.replace(/[۰-۹]/g, function(w){ return persian[w] || w; })
			.replace(/[٠-٩]/g, function(w){ return arabic[w] || w; });
	}

	function digitsOnly(str){
		str = normalizeDigits(str);
		return str.replace(/\D+/g, '');
	}

	function sanitizeInputValue(){
		var current = input.val();
		var cleaned = digitsOnly(current);

		if(current !== cleaned){
			input.val(cleaned);
		}
	}

	function openModal(){
		modal.addClass('wqos-open');
		setTimeout(function(){
			input.trigger('focus');
		}, 60);
	}

	function closeModal(){
		modal.removeClass('wqos-open');
	}

	function renderLoading(){
		results.html('<div class="wqos-state">در حال جستجو...</div>');
	}

	function renderError(message){
		results.html('<div class="wqos-state wqos-state-error">' + message + '</div>');
	}

	function doSearch(){
		sanitizeInputValue();

		var query = $.trim(input.val());

		if(!query){
			renderError('لطفا شماره سفارش یا شماره موبایل را وارد کنید.');
			return;
		}

		if(xhr && xhr.readyState !== 4){
			xhr.abort();
		}

		renderLoading();

		xhr = $.ajax({
			url: window.wqosQuickSearch.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: window.wqosQuickSearch.action,
				nonce: window.wqosQuickSearch.nonce,
				query: query
			}
		}).done(function(response){
			if(response && response.success && response.data && response.data.html){
				results.html(response.data.html);
			}else if(response && response.data && response.data.message){
				renderError(response.data.message);
			}else{
				renderError('خطا در دریافت نتیجه.');
			}
		}).fail(function(){
			renderError('خطا در ارتباط با سرور.');
		});
	}

	function ensureToast(){
		var toast = $('#wqos-toast');

		if(!toast.length){
			$('body').append('<div id="wqos-toast" class="wqos-toast"></div>');
			toast = $('#wqos-toast');
		}

		return toast;
	}

	function showToast(message){
		var toast = ensureToast();

		toast.text(message).addClass('wqos-show');

		clearTimeout(window.wqosToastTimer);

		window.wqosToastTimer = setTimeout(function(){
			toast.removeClass('wqos-show');
		}, 1600);
	}

	async function copyText(text){
		try{
			if(navigator.clipboard && window.isSecureContext){
				await navigator.clipboard.writeText(text);
				return true;
			}
		}catch(e){}

		try{
			var temp = document.createElement('textarea');
			temp.value = text;
			temp.setAttribute('readonly', '');
			temp.style.position = 'fixed';
			temp.style.opacity = '0';

			document.body.appendChild(temp);

			temp.focus();
			temp.select();

			var ok = document.execCommand('copy');

			document.body.removeChild(temp);

			return !!ok;
		}catch(e){
			return false;
		}
	}

	$(document).on('click', '#wqos-trigger', function(e){
		e.preventDefault();
		openModal();
	});

	$(document).on('click', '.wqos-close, .wqos-backdrop', function(){
		closeModal();
	});

	$(document).on('click', '#wqos-submit', function(e){
		e.preventDefault();
		doSearch();
	});

	$(document).on('keydown', function(e){
		if(e.key === 'Escape' && modal.hasClass('wqos-open')){
			closeModal();
		}
	});

	input.on('input paste keyup', function(){
		sanitizeInputValue();
	});

	input.on('keypress', function(e){
		var ch = String.fromCharCode(e.which || e.keyCode);

		if(!/[0-9۰-۹٠-٩]/.test(ch) && e.which !== 0 && e.which !== 8){
			e.preventDefault();
		}
	});

	input.on('keydown', function(e){
		if(e.key === 'Enter'){
			e.preventDefault();
			doSearch();
		}
	});

	input.on('paste', function(){
		setTimeout(function(){
			sanitizeInputValue();
		}, 0);
	});

	$(document).on('click', '.wqos-copy-btn', async function(e){
		e.preventDefault();

		var btn = $(this);
		var value = btn.attr('data-copy') || '';

		if(!value){
			showToast(window.wqosQuickSearch.copy_failed || 'کپی انجام نشد');
			return;
		}

		var ok = await copyText(value);

		if(ok){
			showToast(window.wqosQuickSearch.copy_success || 'کپی شد');
		}else{
			showToast(window.wqosQuickSearch.copy_failed || 'کپی انجام نشد');
		}
	});
});
JS;

		wp_add_inline_script( 'wqos-inline', $js );
	}

	public function render_ui() {
		if ( ! $this->is_allowed() ) {
			return;
		}
		?>
		<button id="wqos-trigger" type="button" aria-label="جستجوی سریع سفارشات">
			<svg viewBox="0 0 24 24" aria-hidden="true">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="M20 20L16.65 16.65"></path>
			</svg>
		</button>

		<div id="wqos-modal" aria-hidden="true">
			<div class="wqos-backdrop"></div>

			<div class="wqos-panel" role="dialog" aria-modal="true" aria-label="جستجوی سریع سفارشات">
				<div class="wqos-header">
					<div class="wqos-title-wrap">
						<div class="wqos-title">جستجوی سریع سفارشات</div>
						<div class="wqos-subtitle">جستجو با شماره سفارش یا شماره موبایل</div>
					</div>

					<button type="button" class="wqos-close" aria-label="بستن">×</button>
				</div>

				<div class="wqos-body">
					<div class="wqos-search-box">
						<div class="wqos-search-wrap">
							<div class="wqos-input-wrap">
								<input
									type="text"
									id="wqos-input"
									class="wqos-input"
									placeholder="شماره سفارش و یا شماره موبایل را وارد کنید"
									autocomplete="off"
									inputmode="numeric"
									dir="ltr"
								/>
							</div>

							<button type="button" id="wqos-submit" class="wqos-btn">جستجو</button>
						</div>

						<div class="wqos-help">
							فقط عدد وارد کنید. اعداد فارسی و عربی خودکار به انگلیسی تبدیل می‌شوند.
						</div>
					</div>

					<div id="wqos-results" class="wqos-results">
						<div class="wqos-state">برای شروع، شماره سفارش یا شماره موبایل را وارد کنید.</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

new WQOS_Quick_Order_Search();
