<?php
defined( 'ABSPATH' ) || exit;

class APC_Admin {

    const MAX_ITEMS = 12;
    const MIN_ITEMS = 4;

    public static function defaults(): array {
        return [
            'eyebrow'      => 'آهن‌شهر پاسارگاد',
            'title'        => 'قیمت لحظه‌ای',
            'subtitle'     => 'محصـولات پرفروش',
            'price_label'  => 'قیمت لحظه‌ای (ریال)',
            'list_label'   => 'لیست قیمت',
            'decor_left'   => 0,
            'show_clock'   => 1,
            'per_view'     => 4,
            'per_view_tab' => 2,
            'per_view_mob' => 1,
            'row_interval' => 4,
            'autoplay'     => 0,
            'items'        => [],
            // نوار «فروش ویژه» (موقتی؛ با تاگل خاموش می‌شود و ساعت به جای قبلی برمی‌گردد)
            'sale_on'      => 0,
            'sale_title'   => '',
            'sale_table'   => 0,
            'sale_max'     => 5,
            'sale_keys'    => [],
            'sale_prices'  => [],
            'sale_btn'     => 'تماس بگیرید',
            'sale_url'     => '',
        ];
    }

    public static function get(): array {
        $opt = get_option( APC_OPTION, [] );
        return wp_parse_args( is_array( $opt ) ? $opt : [], self::defaults() );
    }

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'wp_ajax_apc_sale_rows', [ __CLASS__, 'ajax_sale_rows' ] );
    }

    public static function menu(): void {
        add_menu_page( 'کارت‌های قیمت', 'کارت‌های قیمت', 'manage_options', 'apc-settings', [ __CLASS__, 'page' ], 'dashicons-slides', 58 );
    }

    public static function register(): void {
        register_setting( 'apc_group', APC_OPTION, [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
    }

    public static function assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_apc-settings' ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'apc-admin', APC_URL . 'assets/css/admin.css', [], APC_VERSION );
        wp_enqueue_script( 'apc-admin', APC_URL . 'assets/js/admin.js', [ 'jquery', 'jquery-ui-sortable' ], APC_VERSION, true );
        wp_localize_script( 'apc-admin', 'APC_ADMIN', [
            'max'   => self::MAX_ITEMS,
            'nonce' => wp_create_nonce( 'apc_sale_rows' ),
        ] );
    }

    /** جدول‌های افزونهٔ Ahan Price Tables (وابستگی نرم؛ بدون افزونه فهرست خالی است) */
    private static function price_tables(): array {
        return class_exists( 'APT_DB' ) ? APT_DB::get_all_tables() : [];
    }

    /** ردیف‌های یک جدول برای انتخاب در نوار فروش ویژه */
    public static function ajax_sale_rows(): void {
        check_ajax_referer( 'apc_sale_rows', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );
        $rows = [];
        foreach ( APC_Render::table_rows( absint( $_POST['table'] ?? 0 ) ) as $r ) {
            $rows[] = [ 'key' => $r['key'], 'label' => $r['label'], 'cells' => $r['cells'], 'price' => $r['price'], 'priced' => $r['priced'] ];
        }
        wp_send_json_success( $rows );
    }

    public static function sanitize( $in ): array {
        $d   = self::defaults();
        $in  = is_array( $in ) ? $in : [];
        $out = [];
        foreach ( [ 'eyebrow', 'title', 'subtitle', 'price_label', 'list_label' ] as $k ) {
            $out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ?? $d[ $k ] ) );
        }
        $out['decor_left']   = absint( $in['decor_left'] ?? 0 );
        $out['show_clock']   = ! empty( $in['show_clock'] ) ? 1 : 0;
        $out['per_view']     = max( 1, min( 6, absint( $in['per_view'] ?? 4 ) ) );
        $out['per_view_tab'] = max( 1, min( 6, absint( $in['per_view_tab'] ?? 2 ) ) );
        $out['per_view_mob'] = max( 1, min( 3, absint( $in['per_view_mob'] ?? 1 ) ) );
        $out['row_interval'] = max( 0, min( 60, absint( $in['row_interval'] ?? 4 ) ) );
        $out['autoplay']     = max( 0, min( 60, absint( $in['autoplay'] ?? 0 ) ) );

        $items = [];
        foreach ( (array) ( $in['items'] ?? [] ) as $row ) {
            $code = trim( sanitize_text_field( wp_unslash( (string) ( $row['shortcode'] ?? '' ) ) ) );
            if ( $code === '' ) continue;
            $items[] = [
                'image'     => absint( $row['image'] ?? 0 ),
                'title'     => sanitize_text_field( wp_unslash( $row['title'] ?? '' ) ),
                'shortcode' => $code,
                'fallback'  => sanitize_text_field( wp_unslash( $row['fallback'] ?? '' ) ),
                'max_rows'  => absint( $row['max_rows'] ?? 0 ),
                'url'       => esc_url_raw( $row['url'] ?? '' ),
            ];
            if ( count( $items ) >= self::MAX_ITEMS ) break;
        }
        $out['items'] = $items;

        $out['sale_on']    = ! empty( $in['sale_on'] ) ? 1 : 0;
        $out['sale_title'] = sanitize_text_field( wp_unslash( $in['sale_title'] ?? '' ) );
        $out['sale_table'] = absint( $in['sale_table'] ?? 0 );
        $out['sale_max']   = max( 1, min( 12, absint( $in['sale_max'] ?? 5 ) ) );
        $keys = array_filter( (array) ( $in['sale_keys'] ?? [] ), static fn( $k ) => is_string( $k ) && preg_match( '/^[a-f0-9]{32}-\d+$/', $k ) );
        $out['sale_keys']  = $out['sale_table'] ? array_slice( array_values( array_unique( $keys ) ), 0, $out['sale_max'] ) : [];
        // قیمت دستی هر ردیف انتخاب‌شده (جایگزین قیمت جدول یا «تماس بگیرید»)
        $out['sale_prices'] = [];
        foreach ( $out['sale_keys'] as $k ) {
            $v = sanitize_text_field( wp_unslash( $in['sale_prices'][ $k ] ?? '' ) );
            if ( $v !== '' ) $out['sale_prices'][ $k ] = $v;
        }
        $out['sale_btn']   = sanitize_text_field( wp_unslash( $in['sale_btn'] ?? $d['sale_btn'] ) );
        $out['sale_url']   = esc_url_raw( wp_unslash( $in['sale_url'] ?? '' ), [ 'http', 'https', 'tel', 'mailto' ] );
        return $out;
    }

    public static function page(): void {
        $s      = self::get();
        $items  = $s['items'] ?: [ [] ];
        $n      = APC_OPTION;
        $tables = self::price_tables();
        ?>
        <div class="wrap apc-admin" dir="rtl">
            <h1>کارت‌های قیمت لحظه‌ای</h1>
            <p class="apc-lead">شورتکد نمایش: <code>[apc_slider]</code></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'apc_group' ); ?>

                <section class="apc-box">
                    <h2>متن‌های سکشن</h2>
                    <div class="apc-grid">
                        <?php
                        self::field( $n, 'eyebrow', 'عنوان کوچک', $s );
                        self::field( $n, 'title', 'عنوان اصلی', $s );
                        self::field( $n, 'subtitle', 'زیرعنوان', $s );
                        self::field( $n, 'price_label', 'برچسب کنار قیمت', $s );
                        self::field( $n, 'list_label', 'پیشوند دکمه لیست قیمت', $s );
                        ?>
                    </div>
                    <div class="apc-grid">
                        <div class="apc-f"><span class="apc-l">تصویر تزئینی چپ (چرتکه)</span><?php self::media( "{$n}[decor_left]", (int) $s['decor_left'] ); ?></div>
                        <div class="apc-f"><span class="apc-l">ساعت آنالوگ (زمان محلی سایت)</span>
                            <label class="apc-switch"><input type="checkbox" name="<?php echo $n; ?>[show_clock]" value="1" <?php checked( $s['show_clock'] ); ?>><i></i> نمایش</label>
                            <span class="apc-hint">منطقهٔ زمانی از تنظیمات وردپرس (تنظیمات ← عمومی) خوانده می‌شود.</span></div>
                    </div>
                </section>

                <section class="apc-box">
                    <h2>اسلایدر</h2>
                    <div class="apc-grid apc-grid--4">
                        <div class="apc-f"><span class="apc-l">کارت در هر اسلاید (دسکتاپ)</span><input type="number" min="1" max="6" name="<?php echo $n; ?>[per_view]" value="<?php echo (int) $s['per_view']; ?>"></div>
                        <div class="apc-f"><span class="apc-l">تبلت</span><input type="number" min="1" max="6" name="<?php echo $n; ?>[per_view_tab]" value="<?php echo (int) $s['per_view_tab']; ?>"></div>
                        <div class="apc-f"><span class="apc-l">موبایل</span><input type="number" min="1" max="3" name="<?php echo $n; ?>[per_view_mob]" value="<?php echo (int) $s['per_view_mob']; ?>"></div>
                        <div class="apc-f"><span class="apc-l">تغییر ردیف داخل کارت (ثانیه)</span><input type="number" min="0" max="60" name="<?php echo $n; ?>[row_interval]" value="<?php echo (int) $s['row_interval']; ?>"><span class="apc-hint">۰ = خاموش</span></div>
                        <div class="apc-f"><span class="apc-l">اسلاید خودکار گروه کارت‌ها (ثانیه)</span><input type="number" min="0" max="60" name="<?php echo $n; ?>[autoplay]" value="<?php echo (int) $s['autoplay']; ?>"><span class="apc-hint">۰ = خاموش</span></div>
                    </div>
                </section>

                <section class="apc-box">
                    <div class="apc-box__head">
                        <h2>نوار فروش ویژه <small>(موقتی)</small></h2>
                        <label class="apc-switch"><input type="checkbox" name="<?php echo $n; ?>[sale_on]" value="1" <?php checked( $s['sale_on'] ); ?>><i></i> روشن</label>
                    </div>
                    <p class="apc-hint">با خاموش‌کردن، نوار حذف می‌شود و ساعت آنالوگ خودکار به جای قبلی برمی‌گردد.</p>
                    <div class="apc-grid">
                        <?php
                        self::field( $n, 'sale_title', 'عنوان محصول (مثلاً لوله مانیسمان کاوه)', $s );
                        self::field( $n, 'sale_btn', 'متن دکمه', $s );
                        ?>
                        <div class="apc-f"><span class="apc-l">لینک دکمه</span><input type="text" dir="ltr" placeholder="tel:02100000000" name="<?php echo $n; ?>[sale_url]" value="<?php echo esc_attr( $s['sale_url'] ); ?>"></div>
                        <div class="apc-f"><span class="apc-l">حداکثر تعداد ردیف در نوار</span><input type="number" id="apc-sale-max" min="1" max="12" name="<?php echo $n; ?>[sale_max]" value="<?php echo (int) $s['sale_max']; ?>"></div>
                    </div>

                    <div class="apc-picker">
                        <div class="apc-picker__tables">
                            <span class="apc-l">۱. جدول قیمت</span>
                            <input type="search" id="apc-sale-search" placeholder="جست‌وجوی جدول (نام یا شماره)…" autocomplete="off">
                            <select id="apc-sale-table" name="<?php echo $n; ?>[sale_table]" size="10">
                                <option value="0">— بدون جدول —</option>
                                <?php foreach ( $tables as $t ) : ?>
                                    <option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) $s['sale_table'], (int) $t['id'] ); ?>><?php echo esc_html( $t['name'] . ' (#' . $t['id'] . ')' ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="apc-picker__rows">
                            <div class="apc-picker__bar">
                                <span class="apc-l">۲. ردیف‌های نوار</span>
                                <input type="search" id="apc-rows-search" placeholder="جست‌وجو در ردیف‌ها…" autocomplete="off">
                                <span class="apc-count" id="apc-sale-count"></span>
                            </div>
                            <div id="apc-sale-rows" data-selected="<?php echo esc_attr( wp_json_encode( array_values( (array) $s['sale_keys'] ) ) ); ?>" data-prices="<?php echo esc_attr( wp_json_encode( (object) (array) $s['sale_prices'] ) ); ?>">
                                <p class="apc-empty">ابتدا یک جدول انتخاب کنید.</p>
                            </div>
                            <p class="apc-hint">برای هر ردیف تیک‌خورده می‌توانید «قیمت دستی» (تومان) بنویسید؛ خالی = قیمت زنده‌ی جدول. ردیف «تماس بگیرید» با قیمت دستی در نوار نمایش داده می‌شود.</p>
                        </div>
                    </div>
                </section>

                <section class="apc-box">
                    <h2>جدول‌ها (کارت‌ها)</h2>
                    <p class="apc-hint">
                        هر ردیف یک جدول است. شورتکد را به شکل <code>[asp id=12]</code> یا فقط عدد <code>12</code> وارد کنید.
                        هر جدول یک کارت است و ردیف‌هایش داخل همان کارت با افکت فید یکی‌یکی نمایش داده می‌شوند. حداقل <?php echo self::MIN_ITEMS; ?> و حداکثر <?php echo self::MAX_ITEMS; ?> جدول.
                    </p>
                    <div id="apc-items">
                        <?php foreach ( $items as $i => $it ) self::item_row( (string) $i, $it ); ?>
                    </div>
                    <p><button type="button" class="button" id="apc-add">+ افزودن جدول</button></p>
                    <script type="text/template" id="apc-tpl"><?php self::item_row( '__i__', [] ); ?></script>
                </section>

                <?php submit_button( 'ذخیرهٔ تنظیمات' ); ?>
            </form>
        </div>
        <?php
    }

    private static function field( string $n, string $k, string $label, array $s ): void {
        printf( '<label class="apc-f"><span class="apc-l">%s</span><input type="text" name="%s[%s]" value="%s"></label>', esc_html( $label ), esc_attr( $n ), esc_attr( $k ), esc_attr( $s[ $k ] ) );
    }

    private static function media( string $name, int $id ): void {
        $src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
        ?>
        <div class="apc-media">
            <img src="<?php echo esc_url( $src ); ?>" <?php echo $src ? '' : 'style="display:none"'; ?>>
            <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $id; ?>">
            <button type="button" class="button apc-pick">انتخاب تصویر</button>
            <button type="button" class="button-link apc-clear">حذف</button>
        </div>
        <?php
    }

    private static function item_row( string $i, array $it ): void {
        $n = APC_OPTION . "[items][$i]";
        ?>
        <div class="apc-item">
            <span class="apc-handle dashicons dashicons-menu"></span>
            <?php self::media( "{$n}[image]", (int) ( $it['image'] ?? 0 ) ); ?>
            <div class="apc-fields">
                <label>عنوان (نشان بالای کارت، مثلاً تیرآهن)<input type="text" name="<?php echo $n; ?>[title]" value="<?php echo esc_attr( $it['title'] ?? '' ); ?>"></label>
                <label>شورتکد جدول<input type="text" dir="ltr" placeholder="[asp id=12]" name="<?php echo $n; ?>[shortcode]" value="<?php echo esc_attr( $it['shortcode'] ?? '' ); ?>"></label>
                <label>لینک صفحهٔ لیست قیمت<input type="url" dir="ltr" name="<?php echo $n; ?>[url]" value="<?php echo esc_attr( $it['url'] ?? '' ); ?>"></label>
                <label>توضیح محصول (desc) — فقط وقتی ستون desc جدول خالی است؛ مستقل از عنوان کارت<input type="text" name="<?php echo $n; ?>[fallback]" value="<?php echo esc_attr( $it['fallback'] ?? '' ); ?>"></label>
                <label>حداکثر ردیف در کارت (۰ = همه)<input type="number" min="0" name="<?php echo $n; ?>[max_rows]" value="<?php echo (int) ( $it['max_rows'] ?? 0 ); ?>"></label>
            </div>
            <button type="button" class="button-link-delete apc-remove">حذف</button>
        </div>
        <?php
    }
}
