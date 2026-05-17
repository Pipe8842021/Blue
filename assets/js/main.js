/* ====================================================
   BLUE THERAPY — Landing Page JS
   ==================================================== */

document.addEventListener('DOMContentLoaded', () => {

    // --- Navbar scroll ---
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        const onScroll = () => navbar.classList.toggle('scrolled', window.scrollY > 50);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // --- Mobile menu ---
    const menuBtn    = document.querySelector('.navbar__menu-btn');
    const mobileMenu = document.querySelector('.mobile-menu');
    const closeBtn   = document.querySelector('.mobile-menu__close');

    menuBtn?.addEventListener('click', () => {
        mobileMenu?.classList.add('open');
        menuBtn.setAttribute('aria-expanded', 'true');
    });

    closeBtn?.addEventListener('click', () => {
        mobileMenu?.classList.remove('open');
        menuBtn?.setAttribute('aria-expanded', 'false');
    });

    mobileMenu?.querySelectorAll('a').forEach(a =>
        a.addEventListener('click', () => {
            mobileMenu.classList.remove('open');
            menuBtn?.setAttribute('aria-expanded', 'false');
        })
    );

    // --- Smooth scroll ---
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', e => {
            const target = document.querySelector(link.getAttribute('href'));
            if (!target) return;
            e.preventDefault();
            const offset = 80;
            const top = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        });
    });

    // --- Reveal con IntersectionObserver ---
    const revealObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

    // --- Contadores animados ---
    const easeOut = t => 1 - Math.pow(1 - t, 3);

    const animateCount = (el, target, duration = 1600) => {
        const suffix = el.dataset.suffix || '';
        const start  = performance.now();
        const tick   = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            el.textContent = Math.floor(easeOut(progress) * target) + suffix;
            if (progress < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };

    const counterObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                animateCount(el, parseInt(el.dataset.count), 1800);
                counterObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });

    document.querySelectorAll('[data-count]').forEach(el => counterObserver.observe(el));

});
