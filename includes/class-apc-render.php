<?php
defined( 'ABSPATH' ) || exit;

class APC_Render {

    public static function init(): void {
        add_shortcode( 'apc_slider', [ __CLASS__, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
    }

    public static function register_assets(): void {
        wp_register_style( 'apc-front', APC_URL . 'assets/css/front.css', [], APC_VERSION );
        wp_register_script( 'apc-front', APC_URL . 'assets/js/front.js', [], APC_VERSION, true );

        // سکشن هیرو: استایل باید در <head> بیاید نه فوتر، وگرنه صفحه اول بدون استایل رندر می‌شود و می‌پرد
        $post    = get_post();
        $early   = is_front_page() || ( $post && has_shortcode( (string) $post->post_content, 'apc_slider' ) );
        if ( apply_filters( 'apc_enqueue_early', $early ) ) {
            wp_enqueue_style( 'apc-front' );
        }
    }

    /** شورتکد یا عدد را به شورتکد معتبر [asp id=N] تبدیل می‌کند */
    private static function normalize_shortcode( string $code ): string {
        $code = trim( $code );
        return ctype_digit( $code ) ? '[asp id=' . $code . ']' : $code;
    }

    /** ردیف‌های یک جدول رندرشده را به آرایهٔ کارت تبدیل می‌کند */
    private static function parse_rows( string $html ): array {
        if ( stripos( $html, '<table' ) === false ) return [];
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xp = new DOMXPath( $dom );

        $head_price_idx = null;
        foreach ( $xp->query( '(//tr[th])[1]/th' ) as $i => $th ) {
            if ( mb_strpos( $th->textContent, 'قیمت' ) !== false ) { $head_price_idx = $i; break; }
        }

        $rows = [];
        foreach ( $xp->query( '//tr[td]' ) as $tr ) {
            $tds = $xp->query( './td[not(contains(@class,"ahp-col-action"))]', $tr );
            if ( ! $tds->length ) continue;

            $desc  = $xp->query( './td[@data-col="desc"]', $tr )->item( 0 );
            $price = $xp->query( './td[@data-col="price"]', $tr )->item( 0 );
            if ( ! $price && $head_price_idx !== null ) $price = $tds->item( $head_price_idx );
            if ( ! $price ) $price = $tds->item( $tds->length - 1 );

            // دادهٔ دکمهٔ «افزودن به پیش‌فاکتور» (افزونهٔ Ahan Proforma) برای استفادهٔ مجدد
            $btn  = $xp->query( './/button[@data-ahp-action="add-to-proforma"]', $tr )->item( 0 );
            $cart = [];
            if ( $btn instanceof DOMElement ) {
                foreach ( $btn->attributes as $a ) {
                    if ( str_starts_with( $a->name, 'data-ahp-' ) ) $cart[ $a->name ] = $a->value;
                }
            }

            $text = static fn( $n ) => $n ? trim( preg_replace( '/\s+/u', ' ', $n->textContent ) ) : '';
            $size  = $text( $xp->query( './td[@data-col="code-size"]', $tr )->item( 0 ) );
            $thick = $text( $xp->query( './td[@data-col="code-thick"]', $tr )->item( 0 ) );
            $parts = array_filter( [
                $size  !== '' ? 'سایز: ' . $size : '',
                $thick !== '' ? 'ضخامت: ' . $thick : '',
            ] );

            // همهٔ سلول‌های ردیف (جز قیمت) برای نمایش در ادمین، تا ردیف‌ها از هم قابل تشخیص باشند
            $cells = [];
            foreach ( $tds as $td ) {
                if ( $td === $price ) continue;
                $t = $text( $td );
                if ( $t !== '' ) $cells[] = $t;
            }

            $rows[] = [
                'cells' => $cells,
                'desc'  => $text( $desc ),
                'size'  => $size,
                'parts' => $parts,
                'first' => $text( $tds->item( 0 ) ),
                'price' => $text( $price ),
                'cart'  => $cart,
            ];
        }
        return $rows;
    }

    /** فقط ردیف‌هایی که قیمتشان عدد مثبت است (نه «تماس بگیرید») */
    private static function priced_rows( array $rows ): array {
        return array_values( array_filter( $rows, static function ( $r ) {
            $digits = strtr( $r['price'], [ '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ] );
            return mb_strpos( $r['price'], 'تماس' ) === false && preg_match( '/[1-9]/', $digits );
        } ) );
    }

    /** ردیف‌های یک جدول با کلید پایدار (از روی متن ردیف، نه قیمت) — برای انتخاب در ادمین و خواندن زنده‌ی قیمت */
    public static function table_rows( int $id ): array {
        if ( ! $id || ! shortcode_exists( 'asp' ) ) return [];
        $out  = [];
        $seen = [];
        foreach ( self::parse_rows( do_shortcode( '[asp id=' . $id . ']' ) ) as $r ) {
            $label = $r['size'] !== '' ? $r['size'] : ( $r['desc'] !== '' ? $r['desc'] : $r['first'] );
            $hash  = md5( $r['size'] . '|' . $r['desc'] . '|' . $r['first'] );
            $seen[ $hash ] = ( $seen[ $hash ] ?? -1 ) + 1;
            $out[] = [
                'key'    => $hash . '-' . $seen[ $hash ],
                'label'  => $label,
                'cells'  => $r['cells'],
                'price'  => $r['price'],
                'priced' => (bool) self::priced_rows( [ $r ] ),
            ];
        }
        return $out;
    }

    /** ردیف‌های تیک‌خوردهٔ نوار فروش ویژه با قیمت زنده */
    private static function sale_items( array $s ): array {
        $keys   = (array) $s['sale_keys'];
        $manual = (array) $s['sale_prices'];
        if ( ! $keys ) return [];
        $items = [];
        foreach ( self::table_rows( (int) $s['sale_table'] ) as $r ) {
            if ( ! in_array( $r['key'], $keys, true ) ) continue;
            $price = (string) ( $manual[ $r['key'] ] ?? '' );
            if ( $price === '' && $r['priced'] ) $price = $r['price'];
            if ( $price !== '' ) $items[] = [ 'size' => $r['label'], 'price' => $price ];
        }
        return array_slice( $items, 0, max( 1, (int) $s['sale_max'] ) );
    }

    private static function sale_bar( array $s, array $items ): void {
        ?>
        <aside class="apc-sale" aria-label="<?php echo esc_attr( $s['sale_title'] ?: 'فروش ویژه' ); ?>">
            <span class="apc-sale__pct" aria-hidden="true">%</span>
            <div class="apc-sale__head">
                <strong class="apc-sale__tag">فروش ویژه</strong>
                <?php if ( $s['sale_title'] !== '' ) : ?>
                    <p class="apc-sale__title"><?php echo esc_html( $s['sale_title'] ); ?><svg width="10" height="19" viewBox="0 0 10 19" fill="#fff" aria-hidden="true"><path d="M10 0 0 9.3 10 18.7z"/></svg></p>
                <?php endif; ?>
            </div>
            <ul class="apc-sale__list">
                <?php foreach ( $items as $it ) : ?>
                    <li>
                        <span class="apc-sale__size"><?php echo esc_html( $it['size'] ); ?></span>
                        <span class="apc-sale__price"><b><?php echo esc_html( $it['price'] ); ?></b><small>تومان</small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( $s['sale_url'] !== '' ) : ?>
                <a class="apc-sale__cta" href="<?php echo esc_url( $s['sale_url'], [ 'http', 'https', 'tel', 'mailto' ] ); ?>"><?php echo esc_html( $s['sale_btn'] ); ?></a>
            <?php endif; ?>
            <span class="apc-sale__pct" aria-hidden="true">%</span>
        </aside>
        <?php
    }

    private static function cards( array $s ): array {
        if ( ! shortcode_exists( 'asp' ) ) return [];
        $cards = [];
        foreach ( $s['items'] as $it ) {
            $rows = self::parse_rows( do_shortcode( self::normalize_shortcode( $it['shortcode'] ) ) );
            // ردیف‌هایی که قیمتشان عدد نیست (مثلاً «تماس بگیرید») نمایش داده نمی‌شوند
            $rows = self::priced_rows( $rows );
            if ( ! $rows ) continue;
            $max = (int) ( $it['max_rows'] ?? 0 );
            if ( $max > 0 ) $rows = array_slice( $rows, 0, $max );
            // عنوان: اگر desc بود فقط همان؛ وگرنه توضیح دستی + (سایز، و فقط اگر سایز نبود ضخامت)
            $fallback = trim( $it['fallback'] ?? '' );
            foreach ( $rows as &$row ) {
                if ( $row['desc'] !== '' ) {
                    $segments = [ $row['desc'] ];
                } else {
                    $segments = $fallback !== '' ? [ $fallback ] : [];
                    if ( $row['parts'] ) $segments[] = reset( $row['parts'] );
                }
                $row['name'] = $segments ? implode( ' — ', $segments ) : $row['first'];
                // توضیح سبد پیش‌فاکتور هم همین عنوان باشد (جست‌وجوی تیتر صفحه برای کارت معنا ندارد)
                if ( $row['desc'] === '' && $row['cart'] ) {
                    $row['cart']['data-ahp-description'] = $row['name'];
                    unset( $row['cart']['data-ahp-desc-fallback'] );
                }
            }
            unset( $row );
            $cards[] = [ 'rows' => $rows, 'badge' => $it['title'], 'image' => $it['image'], 'url' => $it['url'] ];
        }
        return $cards;
    }

    public static function shortcode(): string {
        $s     = APC_Admin::get();
        $cards = self::cards( $s );
        if ( ! $cards ) {
            return current_user_can( 'manage_options' ) ? '<p>[apc_slider] هیچ جدولی تنظیم نشده یا افزونهٔ Ahan Price Tables فعال نیست.</p>' : '';
        }

        wp_enqueue_style( 'apc-front' );
        wp_enqueue_script( 'apc-front' );

        $sale  = $s['sale_on'] ? self::sale_items( $s ) : [];
        $pages = array_chunk( $cards, max( 1, (int) $s['per_view'] ) );
        $img   = static fn( $id ) => $id ? wp_get_attachment_image_url( (int) $id, 'full' ) : '';

        ob_start(); ?>
        <section class="apc<?php echo $sale ? ' apc--sale' : ''; ?>" dir="rtl"
                 data-per="<?php echo (int) $s['per_view']; ?>"
                 data-per-tab="<?php echo (int) $s['per_view_tab']; ?>"
                 data-per-mob="<?php echo (int) $s['per_view_mob']; ?>"
                 data-autoplay="<?php echo (int) $s['autoplay']; ?>"
                 data-row-interval="<?php echo (int) $s['row_interval']; ?>">
            <?php if ( $u = $img( $s['decor_left'] ) ) : ?><img class="apc-decor apc-decor--left" src="<?php echo esc_url( $u ); ?>" alt=""><?php endif; ?>
            <?php if ( $s['show_clock'] ) : ?>
            <svg class="apc-clock" viewBox="0 0 200 200" data-secs="<?php echo (int) wp_date( 'G' ) * 3600 + (int) wp_date( 'i' ) * 60 + (int) wp_date( 's' ); ?>" aria-hidden="true">
                <circle cx="100" cy="100" r="98" fill="#8E2A49"/>
                <circle cx="100" cy="100" r="98" fill="none" stroke="#A8486A" stroke-width="2"/>
                <?php for ( $i = 0; $i < 60; $i++ ) :
                    $a = deg2rad( $i * 6 ); $big = $i % 5 === 0; $r1 = $big ? 84 : 91; ?>
                    <line x1="<?php echo round( 100 + 94 * sin( $a ), 2 ); ?>" y1="<?php echo round( 100 - 94 * cos( $a ), 2 ); ?>"
                          x2="<?php echo round( 100 + $r1 * sin( $a ), 2 ); ?>" y2="<?php echo round( 100 - $r1 * cos( $a ), 2 ); ?>"
                          stroke="#D9A3B5" stroke-width="<?php echo $big ? 2.5 : 1; ?>" stroke-linecap="round"/>
                <?php endfor; ?>
                <line class="apc-h" x1="100" y1="108" x2="100" y2="55" stroke="#E8B8C6" stroke-width="5" stroke-linecap="round"/>
                <line class="apc-m" x1="100" y1="112" x2="100" y2="30" stroke="#E8B8C6" stroke-width="3.5" stroke-linecap="round"/>
                <line class="apc-s" x1="100" y1="118" x2="100" y2="22" stroke="#C0243F" stroke-width="1.6" stroke-linecap="round"/>
                <circle cx="100" cy="100" r="5" fill="#C0243F"/>
            </svg>
            <?php endif; ?>

            <div class="apc-inner">
                <header class="apc-head">
                    <h1 class="apc-eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></h1>
                    <div class="apc-badge">
                        <strong><?php echo esc_html( $s['title'] ); ?></strong>
                        <span><?php echo esc_html( $s['subtitle'] ); ?></span>
                    </div>
                </header>

                <div class="apc-slider">
                    <div class="apc-stage">
                        <?php foreach ( $pages as $pi => $page ) : ?>
                            <div class="apc-page<?php echo $pi === 0 ? ' is-active' : ''; ?>">
                                <?php foreach ( $page as $c ) self::card( $c, $s, $img, $pi === 0 ); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="apc-nav">
                        <button type="button" class="apc-prev" aria-label="قبلی">&lsaquo;</button>
                        <div class="apc-dots"></div>
                        <button type="button" class="apc-next" aria-label="بعدی">&rsaquo;</button>
                    </div>
                </div>
            </div>
            <?php if ( $sale ) self::sale_bar( $s, $sale ); ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function card( array $c, array $s, callable $img, bool $eager = false ): void {
        $src = $img( $c['image'] );
        ?>
        <article class="apc-card">
            <div class="apc-card__media"><?php if ( $src ) : ?><img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( $c['badge'] ); ?>" width="172" height="170" decoding="async" <?php echo $eager ? 'fetchpriority="high"' : 'loading="lazy"'; ?>><?php endif; ?></div>
            <div class="apc-card__body">
                <?php if ( $c['badge'] ) : ?><span class="apc-chip"><?php echo esc_html( $c['badge'] ); ?></span><?php endif; ?>
                <div class="apc-rows">
                    <?php foreach ( $c['rows'] as $ri => $r ) : ?>
                        <?php if ( 1 === $ri ) : // باقی ردیف‌ها در DOM نیستند؛ front.js هنگام ورق‌زدن یکی‌یکی وارد می‌کند ?><template class="apc-row-pool"><?php endif; ?>
                        <div class="apc-row<?php echo $ri === 0 ? ' is-active' : ''; ?>">
                            <div class="apc-card__title" title="<?php echo esc_attr( $r['name'] ); ?>"><?php echo esc_html( $r['name'] ); ?></div>
                            <div class="apc-price">
                                <span class="apc-price__label"><i></i><?php echo esc_html( $s['price_label'] ); ?></span>
                                <span class="apc-price__val"><?php echo esc_html( $r['price'] ); ?></span>
                            </div>
                            <div class="apc-actions">
                                <?php if ( $r['cart'] ) : ?>
                                    <button type="button" class="apc-cart" data-ahp-action="add-to-proforma"
                                        <?php foreach ( $r['cart'] as $k => $v ) {
                                            if ( $k === 'data-ahp-action' ) continue;
                                            printf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
                                        } ?> aria-label="افزودن به پیش‌فاکتور">
                                        <span class="ahp-btn-icon"><?php echo self::cart_add_svg(); ?></span>
                                    </button>
                                <?php elseif ( $c['url'] ) : ?>
                                    <a class="apc-cart" href="<?php echo esc_url( $c['url'] ); ?>" aria-label="مشاهده"><?php echo self::bag_svg(); ?></a>
                                <?php endif; ?>
                                <?php if ( $c['url'] ) : ?>
                                    <a class="apc-list" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( trim( $s['list_label'] . ' ' . $c['badge'] ) ); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( count( $c['rows'] ) > 1 ) : ?></template><?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }

    /** همان آیکن «افزودن به پیش‌فاکتور» افزونهٔ Ahan Proforma (JS آن آیکن را با تیک جایگزین و برمی‌گرداند) */
    private static function cart_add_svg(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.3" fill="currentColor"/><circle cx="16.5" cy="20" r="1.3" fill="currentColor"/><path d="M15 2v5M12.5 4.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }

    private static function bag_svg(): string {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5E0520" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 7.5V7a4.5 4.5 0 0 1 9 0v.5"/><path d="M3.5 9h17l-1.4 9.2a2 2 0 0 1-2 1.8H6.9a2 2 0 0 1-2-1.8L3.5 9z"/><path d="M9 13v2M15 13v2M12 13v2"/></svg>';
    }
}
