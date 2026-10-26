(function () {
    'use strict';

    function init(root) {
        var stage = root.querySelector('.apc-stage');
        var dots = root.querySelector('.apc-dots');
        var nav = root.querySelector('.apc-nav');
        var cards = Array.prototype.slice.call(root.querySelectorAll('.apc-card'));
        var perDesk = +root.dataset.per || 4, perTab = +root.dataset.perTab || 2, perMob = +root.dataset.perMob || 1;
        var autoplay = (+root.dataset.autoplay || 0) * 1000;
        var pages = [], cur = 0, per = 0, timer = null;

        function perView() {
            var w = window.innerWidth;
            return w <= 600 ? perMob : (w <= 1100 ? perTab : perDesk);
        }

        function build() {
            per = perView();
            stage.innerHTML = '';
            pages = [];
            root.style.setProperty('--cols', per >= 2 ? 2 : 1);
            for (var i = 0; i < cards.length; i += per) {
                var p = document.createElement('div');
                p.className = 'apc-page';
                cards.slice(i, i + per).forEach(function (c) { p.appendChild(c); });
                stage.appendChild(p);
                pages.push(p);
            }
            dots.innerHTML = '';
            pages.forEach(function (_, i) {
                var d = document.createElement('button');
                d.type = 'button';
                d.className = 'apc-dot';
                d.setAttribute('aria-label', (i + 1) + '');
                d.appendChild(document.createElement('i')); // پرشونده: تایمر اسلاید بعدی
                d.addEventListener('click', function () { go(i, true); });
                dots.appendChild(d);
            });
            nav.hidden = pages.length < 2;
            cur = Math.min(cur, pages.length - 1);
            show();
            root.classList.add('is-ready');
        }

        // نقطهٔ فعال مثل تایمر پر می‌شود تا کاربر بداند اسلاید بعدی کی می‌آید (فقط با autoplay)
        var dotAnim = null;
        function runDot() {
            if (dotAnim) { dotAnim.cancel(); dotAnim = null; }
            var d = dots.children[cur];
            if (!d || !d.firstChild || !d.firstChild.animate || !autoplay || pages.length < 2) return;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            dotAnim = d.firstChild.animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: autoplay, easing: 'linear', fill: 'forwards' });
        }

        function show() {
            pages.forEach(function (p, i) { p.classList.toggle('is-active', i === cur); p.inert = i !== cur; });
            Array.prototype.forEach.call(dots.children, function (d, i) { d.classList.toggle('is-active', i === cur); });
            runDot();
        }

        function go(i, user) {
            cur = (i + pages.length) % pages.length;
            show();
            if (user) restart();
        }

        function restart() {
            clearInterval(timer);
            if (autoplay && pages.length > 1) timer = setInterval(function () { go(cur + 1); }, autoplay);
            runDot();
        }

        // در RTL «بعدی» سمت چپ است؛ دکمه‌ها با کلاس مشخص می‌شوند نه جهت
        root.querySelector('.apc-next').addEventListener('click', function () { go(cur + 1, true); });
        root.querySelector('.apc-prev').addEventListener('click', function () { go(cur - 1, true); });
        root.addEventListener('mouseenter', function () { clearInterval(timer); if (dotAnim) dotAnim.pause(); });
        root.addEventListener('mouseleave', restart);

        var sx = null;
        stage.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
        stage.addEventListener('touchend', function (e) {
            if (sx === null) return;
            var dx = e.changedTouches[0].clientX - sx;
            sx = null;
            if (Math.abs(dx) > 50) go(cur + (dx < 0 ? 1 : -1), true);
        });

        var rt;
        window.addEventListener('resize', function () {
            clearTimeout(rt);
            rt = setTimeout(function () { if (perView() !== per) build(); }, 150);
        });

        build();
        restart();
    }

    // ورق‌زدن ردیف‌های هر کارت با فید. فقط ردیف فعال (و هنگام فید، ردیف قبلی) در DOM است؛
    // بقیه در <template class="apc-row-pool"> می‌مانند و یکی‌یکی وارد می‌شوند (DOM سبک‌تر).
    function rows(root) {
        var every = (+root.dataset.rowInterval || 0) * 1000;
        if (!every) return;
        root.querySelectorAll(".apc-card").forEach(function (card, n) {
            var box = card.querySelector(".apc-rows");
            var pool = box && box.querySelector(".apc-row-pool");
            var first = box && box.querySelector(".apc-row");
            if (!pool || !first) return;

            var list = [first].concat(Array.prototype.slice.call(pool.content.children));
            pool.remove();
            if (list.length < 2) return;

            var i = 0, t = null;
            list.forEach(function (l, k) { l.inert = k !== 0; });

            // تایمر کارت (بدون عدد): نوار پایین کارت؛ پر شدنش = زمان تا ردیف بعدی. با WAAPI؛ بدون reflow.
            var barFg = null, anim = null;
            if (card.animate) {
                var bar = document.createElement("span");
                bar.className = "apc-timebar";
                bar.setAttribute("aria-hidden", "true");
                bar.innerHTML = '<i></i>';
                card.appendChild(bar);
                barFg = bar.firstChild;
            }
            function restartBar() {
                if (!barFg) return;
                if (anim) anim.cancel();
                anim = barFg.animate([{ transform: "scaleX(0)" }, { transform: "scaleX(1)" }], { duration: every, easing: "linear", fill: "forwards" });
            }
            function pauseBar() { if (anim) anim.pause(); }

            function step() {
                restartBar();
                var cur = list[i];
                i = (i + 1) % list.length;
                var next = list[i];
                box.appendChild(next);
                // دو فریم صبر: ردیف تازه با حالت پنهان رندر شود تا فید اجرا شود
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        next.classList.add("is-active"); next.inert = false;
                        cur.classList.remove("is-active"); cur.inert = true;
                        setTimeout(function () { if (cur !== list[i]) cur.remove(); }, 700);
                    });
                });
            }
            function start() { clearInterval(t); restartBar(); t = setInterval(step, every); }
            setTimeout(start, (n % 4) * 400);
            card.addEventListener("mouseenter", function () { clearInterval(t); pauseBar(); });
            card.addEventListener("mouseleave", start);
        });
    }

    // ساعت آنالوگ: مبنا ثانیهٔ روز به وقت محلی سایت (از سرور)، سپس با ساعت مرورگر جلو می‌رود
    function clock(svg) {
        var base = +svg.dataset.secs, t0 = Date.now();
        var h = svg.querySelector('.apc-h'), m = svg.querySelector('.apc-m'), s = svg.querySelector('.apc-s');
        function tick() {
            var t = (base + (Date.now() - t0) / 1000) % 86400;
            h.style.transform = 'rotate(' + (t / 120) % 360 + 'deg)';
            m.style.transform = 'rotate(' + (t / 10) % 360 + 'deg)';
            s.style.transform = 'rotate(' + (t * 6) % 360 + 'deg)';
        }
        tick();
        setInterval(tick, 1000);
    }

    function ready() {
        document.querySelectorAll('.apc').forEach(function (r) { init(r); rows(r); });
        document.querySelectorAll('.apc-clock').forEach(clock);
        // ارتفاع نوار فروش ویژه → ساعت درست بالای نوار می‌نشیند (بدون نوار این متغیر استفاده نمی‌شود)
        document.querySelectorAll('.apc--sale').forEach(function (root) {
            var bar = root.querySelector('.apc-sale');
            if (!bar || !('ResizeObserver' in window)) return;
            new ResizeObserver(function () { root.style.setProperty('--apc-sale-h', bar.offsetHeight + 'px'); }).observe(bar);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
