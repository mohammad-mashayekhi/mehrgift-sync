<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'wp_ajax_mehrgift_fetch_all', array( __CLASS__, 'ajax_fetch_all' ) );
		add_action( 'wp_ajax_mehrgift_process_local_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_mehrgift_sync_single', array( __CLASS__, 'ajax_sync_single' ) );
		add_action( 'wp_ajax_mehrgift_refresh_registry', array( __CLASS__, 'ajax_refresh_registry' ) );
		add_action( 'wp_ajax_mehrgift_sync_start', array( __CLASS__, 'ajax_sync_start' ) );
		add_action( 'wp_ajax_mehrgift_sync_stop', array( __CLASS__, 'ajax_sync_stop' ) );
		add_action( 'wp_ajax_mehrgift_sync_status', array( __CLASS__, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_mehrgift_cron_start', array( __CLASS__, 'ajax_cron_start' ) );
		add_action( 'wp_ajax_mehrgift_pipeline_start', array( __CLASS__, 'ajax_pipeline_start' ) );
		add_action( 'wp_ajax_mehrgift_sync_selected', array( __CLASS__, 'ajax_sync_selected' ) );
		add_action( 'wp_ajax_mehrgift_apply_preset', array( __CLASS__, 'ajax_apply_preset' ) );
	}

	public static function menu() {
		add_menu_page(
			'همگام‌ساز مهرگیفت',
			'مهرگیفت',
			'manage_options',
			'mehrgift-sync',
			array( __CLASS__, 'render_page' ),
			'dashicons-update',
			60
		);
	}

	public static function assets( $hook ) {
		if ( strpos( $hook, 'mehrgift-sync' ) === false ) {
			return;
		}

		Mehrgift_Job::release_lock();

		wp_enqueue_style( 'mehrgift-admin', MEHRGIFT_SYNC_URL . 'assets/admin.css', array(), MEHRGIFT_SYNC_VERSION );
		wp_enqueue_script( 'mehrgift-admin', MEHRGIFT_SYNC_URL . 'assets/admin.js', array( 'jquery' ), MEHRGIFT_SYNC_VERSION, true );
		$cache_total = 0;
		if ( file_exists( Mehrgift_Settings::data_file_path() ) ) {
			$cached = Mehrgift_Api_Client::load_local_cache();
			if ( ! is_wp_error( $cached ) ) {
				$cache_total = count( $cached );
			}
		}

		wp_localize_script( 'mehrgift-admin', 'MehrgiftSync', array(
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'mehrgift_sync' ),
			'batchNoImages'   => (int) Mehrgift_Settings::get( 'batch_no_images' ),
			'batchWithImages' => (int) Mehrgift_Settings::get( 'batch_with_images' ),
			'batchDetails'    => (int) Mehrgift_Settings::get( 'batch_details' ),
			'batchPrice'      => (int) Mehrgift_Settings::get( 'batch_price' ),
			'batchPauseSec'   => (int) Mehrgift_Settings::get( 'batch_pause_sec' ),
			'batchPausePrice' => (int) Mehrgift_Settings::get( 'batch_pause_price' ),
			'cacheTotal'      => $cache_total,
		) );
	}

	public static function render_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sync';
		?>
		<div class="wrap mehrgift-wrap" dir="rtl">
			<h1>همگام‌ساز مهرگیفت</h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mehrgift-sync&tab=sync' ) ); ?>" class="nav-tab <?php echo 'sync' === $tab ? 'nav-tab-active' : ''; ?>">همگام‌سازی</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mehrgift-sync&tab=products' ) ); ?>" class="nav-tab <?php echo 'products' === $tab ? 'nav-tab-active' : ''; ?>">محصولات</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mehrgift-sync&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">تنظیمات</a>
			</nav>
			<div class="mehrgift-tab-content">
				<?php
				if ( 'products' === $tab ) {
					self::render_products_tab();
				} elseif ( 'settings' === $tab ) {
					self::render_settings_tab();
				} else {
					self::render_sync_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function render_sync_tab() {
		$cache_exists = file_exists( Mehrgift_Settings::data_file_path() );
		$stats        = Mehrgift_Sync_Registry::get_stats();
		?>
		<div class="mehrgift-card">
			<h2>همگام‌سازی گروهی</h2>
			<p>ابتدا لیست محصولات را یک‌بار از API دانلود کنید، سپس پردازش دسته‌ای را آغاز کنید. پردازش مستقیم از مرورگر است — نیازی به WP-Cron نیست.</p>
			<ul class="mg-dash-stats mg-sync-stats">
				<li>قیمت OK: <?php echo (int) ( $stats['price_ok'] ?? 0 ); ?></li>
				<li>جزئیات OK: <?php echo (int) ( $stats['details_ok'] ?? 0 ); ?></li>
				<li>عکس OK: <?php echo (int) ( $stats['images_ok'] ?? 0 ); ?></li>
				<li>بدون WC: <?php echo (int) ( $stats['no_wc'] ?? 0 ); ?></li>
			</ul>

			<div class="mehrgift-steps">
				<button type="button" id="mg-fetch-btn" class="button button-secondary button-hero">۱. دانلود لیست محصولات</button>
				<span id="mg-fetch-status" class="mg-status"><?php echo $cache_exists ? 'کش موجود است.' : 'منتظر دانلود...'; ?></span>
			</div>

			<div id="mg-sync-area" class="<?php echo $cache_exists ? '' : 'is-disabled'; ?>">
				<p>
					<label><input type="checkbox" id="mg-with-images" value="1"> دانلود و کراپ تصاویر (کندتر)</label>
				</p>
				<p>
					<label>نوع همگام‌سازی:
						<select id="mg-bulk-mode">
							<option value="full">کامل (همه چیز)</option>
							<option value="price">فقط قیمت و موجودی</option>
							<option value="details">فقط جزئیات و مشخصات</option>
							<option value="images">فقط تصاویر</option>
						</select>
					</label>
				</p>
				<button type="button" id="mg-sync-btn" class="button button-primary button-hero">۲. شروع پردازش</button>
				<button type="button" id="mg-stop-btn" class="button" disabled>توقف</button>
			</div>

			<div class="mg-progress-wrap">
				<div id="mg-progress-bar" class="mg-progress-bar"></div>
				<span id="mg-progress-text" class="mg-progress-text">0%</span>
			</div>
			<textarea id="mg-log" class="mg-log" readonly></textarea>
		</div>
		<?php
	}

	private static function render_products_tab() {
		if ( isset( $_POST['mehrgift_save_settings'] ) ) {
			// no-op here.
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$list   = Mehrgift_Sync_Registry::list_products( array(
			'search'   => $search,
			'status'   => $status,
			'paged'    => $paged,
			'per_page' => 25,
		) );
		?>
		<div class="mehrgift-card">
			<div class="mg-toolbar">
				<form method="get">
					<input type="hidden" name="page" value="mehrgift-sync">
					<input type="hidden" name="tab" value="products">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو عنوان، بارکد، شناسه...">
					<select name="status">
						<option value="">همه وضعیت‌ها</option>
						<?php foreach ( array( 'synced' => 'سینک شده', 'fetched' => 'دریافت شده', 'error' => 'خطا', 'pending' => 'در انتظار' ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button">فیلتر</button>
				</form>
				<button type="button" id="mg-refresh-registry" class="button">بازخوانی از کش</button>
			</div>

			<div class="notice notice-info inline">
				<p><strong>تفاوت دو کد:</strong> «کد کالا» همان عدد قرمز روی سایت مهرگیفت است (مثل <code dir="ltr">120836</code>). «شناسه URL» عدد داخل آدرس است (مثل <code dir="ltr">product-108004</code> → <code dir="ltr">108004</code>).</p>
			</div>

			<div class="mg-bulk-actions">
				<label><input type="checkbox" id="mg-select-all"> انتخاب همه</label>
				<button type="button" class="button mg-sync-selected" data-action="price">سینک قیمت انتخاب‌شده</button>
				<button type="button" class="button mg-sync-selected" data-action="details">سینک جزئیات انتخاب‌شده</button>
				<button type="button" class="button mg-sync-selected" data-action="images">سینک عکس انتخاب‌شده</button>
				<button type="button" class="button mg-sync-selected" data-action="full">سینک کامل انتخاب‌شده</button>
				<button type="button" id="mg-stop-btn" class="button" disabled>توقف</button>
			</div>
			<div id="mg-products-progress" class="mg-products-progress" style="display:none;">
				<div class="mg-progress-wrap">
					<div id="mg-progress-bar" class="mg-progress-bar"></div>
					<span id="mg-progress-text" class="mg-progress-text">0%</span>
				</div>
				<textarea id="mg-log" class="mg-log" readonly></textarea>
			</div>

			<table class="widefat striped mg-products-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="mg-select-all-head"></th>
						<th>کد کالا <span class="mg-th-hint">(مهرگیفت)</span></th>
						<th>شناسه URL <span class="mg-th-hint">(product-XXX)</span></th>
						<th>WC #</th>
						<th>محصول</th>
						<th>قیمت</th>
						<th>جزئیات</th>
						<th>عکس</th>
						<th>آخرین قیمت</th>
						<th>آخرین جزئیات</th>
						<th>آخرین عکس</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $list['items'] ) ) : ?>
					<tr><td colspan="12">محصولی ثبت نشده. از تب همگام‌سازی شروع کنید.</td></tr>
				<?php else : ?>
					<?php foreach ( $list['items'] as $row ) : ?>
						<tr data-remote-id="<?php echo esc_attr( $row['remote_id'] ); ?>">
							<th class="check-column"><input type="checkbox" class="mg-row-check" value="<?php echo esc_attr( $row['remote_id'] ); ?>"></th>
							<td><code class="mg-kala-code" dir="ltr"><?php echo esc_html( $row['barcode'] ?: '—' ); ?></code></td>
							<td><code dir="ltr"><?php echo esc_html( $row['remote_id'] ); ?></code></td>
							<td><?php echo $row['product_id'] ? '#' . (int) $row['product_id'] : '—'; ?></td>
							<td>
								<?php if ( $row['product_id'] ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $row['product_id'] ) ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['title'] ); ?>
								<?php endif; ?>
							</td>
							<td><span class="mg-dim mg-dim-<?php echo esc_attr( $row['price_status'] ?? 'pending' ); ?>"><?php echo esc_html( Mehrgift_Sync_Registry::status_badge( $row['price_status'] ?? 'pending' ) ); ?></span></td>
							<td><span class="mg-dim mg-dim-<?php echo esc_attr( $row['details_status'] ?? 'pending' ); ?>"><?php echo esc_html( Mehrgift_Sync_Registry::status_badge( $row['details_status'] ?? 'pending' ) ); ?></span></td>
							<td><span class="mg-dim mg-dim-<?php echo esc_attr( $row['images_status'] ?? 'pending' ); ?>"><?php echo esc_html( Mehrgift_Sync_Registry::status_badge( $row['images_status'] ?? 'pending' ) ); ?></span></td>
							<td><?php echo esc_html( $row['last_price_sync'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $row['last_details_sync'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $row['last_images_sync'] ?: '—' ); ?></td>
							<td class="mg-actions">
								<button type="button" class="button button-small mg-sync-one" data-mode="full">کامل</button>
								<button type="button" class="button button-small mg-sync-one" data-mode="price">قیمت</button>
								<button type="button" class="button button-small mg-sync-one" data-mode="images">عکس</button>
								<button type="button" class="button button-small mg-sync-one" data-mode="details">جزئیات</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $list['pages'] > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $list['pages'],
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_settings_tab() {
		if ( isset( $_POST['mehrgift_refresh_categories'] ) && check_admin_referer( 'mehrgift_settings' ) ) {
			$catalog = Mehrgift_Categories::refresh_catalog();
			if ( is_wp_error( $catalog ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $catalog->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'لیست دسته‌ها دریافت شد (%d دسته).', count( $catalog ) ) ) . '</p></div>';
			}
		}
		if ( isset( $_POST['mehrgift_save_settings'] ) && check_admin_referer( 'mehrgift_settings' ) ) {
			@set_time_limit( 300 );
			Mehrgift_Settings::save( wp_unslash( $_POST ) );
			$saved = Mehrgift_Settings::get();
			if ( empty( $saved['use_background'] ) ) {
				Mehrgift_Activator::cleanup_legacy_jobs();
				Mehrgift_Job::release_lock();
			}
			if ( class_exists( 'Mehrgift_Scheduler' ) ) {
				Mehrgift_Scheduler::maybe_schedule();
			}
			$indexed = Mehrgift_Categories::rebuild_index();
			if ( is_wp_error( $indexed ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( 'تنظیمات ذخیره شد، اما شمارش محصولات دسته‌های حذف‌شده ناموفق بود. لیست قبلی حفظ شد. ' . $indexed->get_error_message() ) . '</p></div>';
			} elseif ( empty( $saved['excluded_categories'] ) ) {
				echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد. هیچ دسته‌ای حذف نشده است.</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
					'تنظیمات ذخیره شد. %d محصول از دسته‌های انتخاب‌شده وارد همگام‌سازی نمی‌شوند. محصولات قبلی فروشگاه تغییر نمی‌کنند. برای هم‌خوان شدن کش، یک‌بار لیست محصولات را دوباره دانلود کنید.',
					(int) $indexed
				) ) . '</p></div>';
			}
		}
		$s = Mehrgift_Settings::get();
		?>
		<div class="mehrgift-card">
			<form method="post">
				<?php wp_nonce_field( 'mehrgift_settings' ); ?>
				<table class="form-table">
					<tr>
						<th>تخفیف فروش</th>
						<td>
							<input type="number" name="discount_threshold" value="<?php echo esc_attr( $s['discount_threshold'] ); ?>" min="0" step="1000"> تومان و بیشتر:
							<input type="number" name="discount_percent_above" value="<?php echo esc_attr( $s['discount_percent_above'] ); ?>" min="0" max="99"> %
							<br>
							کمتر از این مبلغ:
							<input type="number" name="discount_percent_below" value="<?php echo esc_attr( $s['discount_percent_below'] ); ?>" min="0" max="99"> %
							<p class="description">قیمت عادی همان قیمت مهرگیفت است. برای هر تنوع، تخفیف از روی قیمت همان تنوع حساب می‌شود. مبلغ مرز خودش شامل درصد بالاتر است.</p>
						</td>
					</tr>
					<tr>
						<th>کراپ متقارن از هر ۴ طرف</th>
						<td><input type="number" name="crop_percent" value="<?php echo esc_attr( $s['crop_percent'] ); ?>" min="0" max="40" step="0.5"> % — برای حذف لوگو مهرگیفت</td>
					</tr>
					<tr>
						<th>اندازه خروجی تصویر</th>
						<td><input type="number" name="output_size" value="<?php echo esc_attr( $s['output_size'] ); ?>" min="400" max="2000"> px (مربع)</td>
					</tr>
					<tr>
						<th>کیفیت JPEG</th>
						<td><input type="number" name="jpeg_quality" value="<?php echo esc_attr( $s['jpeg_quality'] ); ?>" min="60" max="100"></td>
					</tr>
					<tr>
						<th>تعداد در هر دسته (بدون عکس)</th>
						<td><input type="number" name="batch_no_images" value="<?php echo esc_attr( $s['batch_no_images'] ); ?>"></td>
					</tr>
					<tr>
						<th>تعداد در هر دسته (با عکس)</th>
						<td><input type="number" name="batch_with_images" value="<?php echo esc_attr( $s['batch_with_images'] ); ?>" min="1" max="5"> <span class="description">پیشنهاد: ۱</span></td>
					</tr>
					<tr>
						<th>تعداد در هر دسته (جزئیات)</th>
						<td><input type="number" name="batch_details" value="<?php echo esc_attr( $s['batch_details'] ); ?>" min="1" max="10"> <span class="description">پیشنهاد: ۲</span></td>
					</tr>
					<tr>
						<th>تعداد در هر دسته (قیمت)</th>
						<td><input type="number" name="batch_price" value="<?php echo esc_attr( $s['batch_price'] ); ?>" min="1" max="100"></td>
					</tr>
					<tr>
						<th>تعداد هسته CPU</th>
						<td>
							<select name="server_cores" id="mg-server-cores">
								<?php for ( $c = 1; $c <= 4; $c++ ) : ?>
									<option value="<?php echo $c; ?>" <?php selected( (int) ( $s['server_cores'] ?? 3 ), $c ); ?>><?php echo $c; ?> هسته</option>
								<?php endfor; ?>
							</select>
							<button type="button" class="button" id="mg-apply-preset">اعمال پیش‌تنظیم سرعت</button>
							<p class="description">برای سرور ۳ هسته: پروفایل VPS3 — قیمت یک‌جا، جزئیات ۳تایی، مکث ۲ ثانیه.</p>
						</td>
					</tr>
					<tr>
						<th>فشار CPU / پروفایل</th>
						<td>
							<select name="cpu_throttle">
								<option value="low" <?php selected( $s['cpu_throttle'] ?? 'vps3', 'low' ); ?>>کم (~۲۰٪) — هاست اشتراکی</option>
								<option value="medium" <?php selected( $s['cpu_throttle'] ?? 'vps3', 'medium' ); ?>>متوسط (~۵۰٪)</option>
								<option value="vps3" <?php selected( $s['cpu_throttle'] ?? 'vps3', 'vps3' ); ?>>VPS ۳ هسته (پیشنهاد شما)</option>
								<option value="high" <?php selected( $s['cpu_throttle'] ?? 'vps3', 'high' ); ?>>حداکثر سرعت</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>مکث بین دسته‌ها (ثانیه)</th>
						<td><input type="number" name="batch_pause_sec" value="<?php echo esc_attr( $s['batch_pause_sec'] ); ?>" min="2" max="60"> — بین هر درخواست AJAX برای جزئیات و عکس</td>
					</tr>
					<tr>
						<th>مکث بین دسته‌های قیمت (ثانیه)</th>
						<td><input type="number" name="batch_pause_price" value="<?php echo esc_attr( $s['batch_pause_price'] ); ?>" min="0" max="10"> <span class="description">پیشنهاد: ۱</span></td>
					</tr>
					<tr>
						<th>همگام‌سازی خودکار شبانه (اختیاری)</th>
						<td>
							<label><input type="checkbox" name="auto_sync_enabled" value="1" <?php checked( $s['auto_sync_enabled'] ?? 0 ); ?>> فعال</label>
							ساعت شروع:
							<input type="number" name="daily_sync_hour" value="<?php echo esc_attr( $s['daily_sync_hour'] ?? 0 ); ?>" min="0" max="23"> (۰ = نیمه‌شب)
							<p class="description">نیاز به WP-Cron فعال روی سرور دارد. پیش‌فرض: خاموش.</p>
						</td>
					</tr>
					<tr>
						<th>پردازش پس‌زمینه WP-Cron (پیشرفته)</th>
						<td>
							<label><input type="checkbox" name="use_background" value="1" <?php checked( $s['use_background'] ); ?>> فعال</label>
							<p class="description">معمولاً لازم نیست. همگام‌سازی عادی مستقیم از مرورگر (AJAX) انجام می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th>Scrape مشخصات در sync جزئیات</th>
						<td><label><input type="checkbox" name="scrape_in_details" value="1" <?php checked( $s['scrape_in_details'] ); ?>> فعال (سنگین — هر محصول یک درخواست به مهرگیفت)</label></td>
					</tr>
					<tr>
						<th>تأخیر بین scrapeها (ثانیه)</th>
						<td><input type="number" name="scrape_delay_sec" value="<?php echo esc_attr( $s['scrape_delay_sec'] ); ?>" min="1" max="10"></td>
					</tr>
					<tr>
						<th>دریافت مشخصات در sync گروهی (قدیمی)</th>
						<td><label><input type="checkbox" name="fetch_specs_bulk" value="1" <?php checked( $s['fetch_specs_bulk'] ); ?>> فعال</label></td>
					</tr>
					<tr>
						<th>نمایش تب مشخصات فنی در محصول</th>
						<td><label><input type="checkbox" name="show_specs_tab" value="1" <?php checked( $s['show_specs_tab'] ); ?>> فعال</label></td>
					</tr>
					<tr>
						<th>نمایش کد کالا در صفحه محصول</th>
						<td><label><input type="checkbox" name="show_kala_code" value="1" <?php checked( $s['show_kala_code'] ); ?>> فعال</label></td>
					</tr>
					<tr>
						<th>دسته‌های حذف‌شده از مبدا</th>
						<td>
							<?php self::render_excluded_categories( $s ); ?>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="mehrgift_save_settings" class="button button-primary">ذخیره تنظیمات</button></p>
			</form>
			<form id="mg-refresh-categories" method="post" class="mg-refresh-categories-form">
				<?php wp_nonce_field( 'mehrgift_settings' ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_excluded_categories( array $settings ) {
		$catalog  = Mehrgift_Categories::catalog();
		$selected = Mehrgift_Settings::sanitize_category_slug_list( $settings['excluded_categories'] ?? array() );
		$summary  = Mehrgift_Categories::index_summary();
		?>
		<p class="description">محصولات این دسته‌ها از مهرگیفت گرفته نمی‌شوند و در این سایت ساخته یا به‌روز نمی‌شوند. محصولات قبلی فروشگاه دست نمی‌خورند. با انتخاب یک دستهٔ مادر، زیردسته‌هایش هم حذف می‌شوند.</p>
		<?php if ( empty( $catalog ) ) : ?>
			<p>ابتدا لیست دسته‌ها را از مهرگیفت دریافت کنید.</p>
		<?php else : ?>
			<?php self::render_category_branch( self::category_children_map( $catalog ), '', $selected ); ?>
		<?php endif; ?>
		<p><button type="submit" name="mehrgift_refresh_categories" form="mg-refresh-categories" class="button">دریافت لیست دسته‌ها از مهرگیفت</button></p>
		<?php if ( $summary['built_at'] ) : ?>
			<p class="description">
				<?php
				echo esc_html( sprintf( 'آخرین شمارش: %d محصول (%s).', (int) $summary['count'], $summary['built_at'] ) );
				if ( $summary['stale'] ) {
					echo ' لیست قدیمی است. تنظیمات را ذخیره کنید تا دوباره شمارش شود.';
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function category_children_map( array $catalog ) {
		$known     = array();
		$by_parent = array();
		foreach ( $catalog as $cat ) {
			if ( ! empty( $cat['slug'] ) ) {
				$known[ $cat['slug'] ] = true;
			}
		}
		foreach ( $catalog as $cat ) {
			if ( empty( $cat['slug'] ) ) {
				continue;
			}
			$parent = (string) ( $cat['parent'] ?? '' );
			if ( '' === $parent || empty( $known[ $parent ] ) ) {
				$parent = '';
			}
			$by_parent[ $parent ][] = $cat;
		}
		return $by_parent;
	}

	private static function render_category_branch( array $by_parent, $parent, array $selected ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}
		echo '<ul class="mg-cat-tree">';
		foreach ( $by_parent[ $parent ] as $cat ) {
			$slug = $cat['slug'];
			echo '<li><label><input type="checkbox" class="mg-excluded-cat" name="excluded_categories[]" value="' . esc_attr( $slug ) . '" data-slug="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $selected, true ), true, false ) . '> ' . esc_html( $cat['title'] ) . '</label>';
			self::render_category_branch( $by_parent, $slug, $selected );
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function ajax_fetch_all() {
		self::verify_ajax();
		set_time_limit( 300 );

		$products = Mehrgift_Api_Client::fetch_all_products();
		if ( is_wp_error( $products ) ) {
			wp_send_json_error( $products->get_error_message() );
		}

		$indexed = Mehrgift_Categories::rebuild_index();
		if ( is_wp_error( $indexed ) ) {
			wp_send_json_error( $indexed->get_error_message() );
		}

		$saved = Mehrgift_Api_Client::save_local_cache( $products );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( $saved->get_error_message() );
		}

		$filtered = Mehrgift_Api_Client::load_local_cache();
		if ( is_wp_error( $filtered ) ) {
			wp_send_json_error( $filtered->get_error_message() );
		}

		foreach ( $filtered as $item ) {
			Mehrgift_Sync_Registry::mark_fetch( (int) $item['id'], $item );
		}

		wp_send_json_success( array(
			'count'    => count( $filtered ),
			'excluded' => max( 0, count( $products ) - count( $filtered ) ),
			'total'    => count( $products ),
		) );
	}

	public static function ajax_process_batch() {
		self::verify_ajax();
		wp_suspend_cache_addition( true );

		$offset      = (int) ( $_POST['offset'] ?? 0 );
		$with_images = ! empty( $_POST['with_images'] );
		$mode        = sanitize_key( $_POST['mode'] ?? 'full' );
		$image_mode  = $with_images || 'images' === $mode;
		$limit       = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : Mehrgift_Settings::get_batch_limit( $mode, $with_images );

		set_time_limit( 300 );
		$indexed = Mehrgift_Categories::ensure_index();
		if ( is_wp_error( $indexed ) ) {
			wp_send_json_error( $indexed->get_error_message() );
		}
		set_time_limit( $image_mode ? 300 : 120 );

		$products = Mehrgift_Api_Client::load_local_cache();
		if ( is_wp_error( $products ) ) {
			wp_send_json_error( $products->get_error_message() );
		}

		$filter_ids = array();
		if ( ! empty( $_POST['remote_ids'] ) && is_array( $_POST['remote_ids'] ) ) {
			$filter_ids = array_map( 'intval', $_POST['remote_ids'] );
			$filter_ids = array_values( array_filter( $filter_ids ) );
		}
		if ( $filter_ids ) {
			$lookup = array_flip( $filter_ids );
			$products = array_values( array_filter( $products, function ( $item ) use ( $lookup ) {
				return isset( $lookup[ (int) ( $item['id'] ?? 0 ) ] );
			} ) );
		}

		$total = count( $products );
		$batch = array_slice( $products, $offset, $limit );

		if ( empty( $batch ) ) {
			wp_send_json_success( array(
				'processed'   => 0,
				'advanced'    => 0,
				'done'        => true,
				'total'       => $total,
				'next_offset' => $offset,
				'logs'        => 'پایان لیست.',
			) );
		}

		if ( Mehrgift_Settings::is_heavy_mode( $mode, $with_images ) ) {
			$GLOBALS['mehrgift_throttle'] = true;
		}
		Mehrgift_Product_Sync::begin_performance_mode();
		$GLOBALS['mehrgift_force_specs'] = (bool) Mehrgift_Settings::get( 'scrape_in_details' );
		$result = Mehrgift_Product_Sync::sync_batch( $batch, $mode, $image_mode );
		Mehrgift_Product_Sync::end_performance_mode();
		unset( $GLOBALS['mehrgift_throttle'] );

		$next = $offset + (int) $result['advanced'];

		wp_send_json_success( array_merge( $result, array(
			'total'       => $total,
			'next_offset' => $next,
			'done'        => $next >= $total,
		) ) );
	}

	public static function ajax_sync_start() {
		self::verify_ajax();
		wp_clear_scheduled_hook( Mehrgift_Cron::HOOK );
		Mehrgift_Job::release_lock();
		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		Mehrgift_Job::save_state( array(
			'offset'      => $offset,
			'stopped'     => false,
			'running'     => true,
			'background'  => false,
			'started_at'  => current_time( 'mysql' ),
		) );
		wp_send_json_success( Mehrgift_Job::get_state() );
	}

	public static function ajax_sync_stop() {
		self::verify_ajax();
		wp_clear_scheduled_hook( Mehrgift_Cron::HOOK );
		Mehrgift_Job::release_lock();
		delete_option( Mehrgift_Job::STATE_KEY );
		wp_send_json_success( array( 'stopped' => true ) );
	}

	public static function ajax_cron_start() {
		self::verify_ajax();
		$mode        = sanitize_key( $_POST['mode'] ?? 'full' );
		$with_images = ! empty( $_POST['with_images'] );
		$offset      = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$result = Mehrgift_Cron::start( $mode, $with_images, $offset );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( Mehrgift_Job::get_state() );
	}

	public static function ajax_sync_status() {
		self::verify_ajax();
		$state   = Mehrgift_Job::get_state();
		$stats   = Mehrgift_Sync_Registry::get_stats();
		$metrics = Mehrgift_Pipeline::server_metrics();
		wp_send_json_success( array(
			'locked'   => (bool) get_transient( Mehrgift_Job::LOCK_KEY ),
			'state'    => $state,
			'log'      => Mehrgift_Cron::tail_log( 80 ),
			'cron'     => (bool) wp_next_scheduled( Mehrgift_Cron::HOOK ),
			'progress' => Mehrgift_Pipeline::progress_percent( $state ),
			'phase'    => Mehrgift_Pipeline::phase_label( $state['phase'] ?? '' ),
			'stats'    => $stats,
			'metrics'  => $metrics,
			'next_run' => Mehrgift_Scheduler::next_run(),
		) );
	}

	public static function ajax_pipeline_start() {
		self::verify_ajax();
		$result = Mehrgift_Cron::start_pipeline( array( 'trigger' => 'manual' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( Mehrgift_Job::get_state() );
	}

	public static function ajax_apply_preset() {
		self::verify_ajax();
		$cores = max( 1, min( 4, (int) ( $_POST['cores'] ?? 3 ) ) );
		$s     = Mehrgift_Settings::apply_preset_for_cores( $cores );
		wp_send_json_success( array(
			'message' => 'پیش‌تنظیم ' . $cores . ' هسته اعمال شد.',
			'settings' => $s,
		) );
	}

	public static function ajax_sync_selected() {
		self::verify_ajax();
		wp_send_json_error( 'از دکمه‌های سینک در جدول محصولات استفاده کنید — پردازش مستقیم AJAX است.' );
	}

	public static function ajax_sync_single() {
		self::verify_ajax();
		set_time_limit( 120 );

		$remote_id = (int) ( $_POST['remote_id'] ?? 0 );
		$mode      = sanitize_key( $_POST['mode'] ?? 'full' );

		$indexed = Mehrgift_Categories::ensure_index();
		if ( is_wp_error( $indexed ) ) {
			wp_send_json_error( $indexed->get_error_message() );
		}
		if ( Mehrgift_Categories::is_excluded( $remote_id ) ) {
			wp_send_json_error( 'این محصول در دسته حذف‌شده است.' );
		}

		$item = Mehrgift_Api_Client::find_in_cache( $remote_id );
		if ( is_wp_error( $item ) ) {
			$all = Mehrgift_Api_Client::fetch_all_products();
			if ( ! is_wp_error( $all ) ) {
				Mehrgift_Api_Client::save_local_cache( $all );
				$item = Mehrgift_Api_Client::find_in_cache( $remote_id );
			}
		}
		if ( is_wp_error( $item ) ) {
			wp_send_json_error( $item->get_error_message() );
		}

		delete_transient( 'mehrgift_specs_' . $remote_id );
		$GLOBALS['mehrgift_force_specs'] = true;
		$with_images = ( 'images' === $mode || 'full' === $mode );
		$result      = Mehrgift_Product_Sync::sync_item( $item, $mode, $with_images );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	public static function ajax_refresh_registry() {
		self::verify_ajax();
		$products = Mehrgift_Api_Client::load_local_cache();
		if ( is_wp_error( $products ) ) {
			wp_send_json_error( $products->get_error_message() );
		}
		$count = 0;
		foreach ( $products as $item ) {
			Mehrgift_Sync_Registry::mark_fetch( (int) $item['id'], $item );
			$count++;
		}
		wp_send_json_success( array( 'count' => $count ) );
	}

	private static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}
		check_ajax_referer( 'mehrgift_sync', 'nonce' );
	}
}
