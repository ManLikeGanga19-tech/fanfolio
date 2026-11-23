// Auth carousel (canvas boards AuthLogin / AuthSignup, note "Auth carousel behaviour"):
// advances every 6 s, pauses on hover and focus, never auto-plays with reduced motion,
// and the dots are buttons. Bundled by Vite (strict CSP: no inline scripts).

const INTERVAL = 6000;

export const initAuthCarousels = () => {
    document.querySelectorAll('[data-carousel]').forEach((root) => {
        const images = [...root.querySelectorAll('[data-slide-image]')];
        const texts = [...root.querySelectorAll('[data-slide-text]')];
        const dots = [...root.querySelectorAll('[data-slide-dot]')];
        const live = root.querySelector('[data-slide-copy]');
        if (images.length < 2) {
            return;
        }

        let current = dots.findIndex((dot) => dot.getAttribute('aria-current') === 'true');
        current = current < 0 ? 0 : current;
        let timer = null;
        let paused = false;

        const show = (index) => {
            current = (index + images.length) % images.length;
            images.forEach((img, i) => {
                img.classList.toggle('opacity-100', i === current);
                img.classList.toggle('opacity-0', i !== current);
                if (i === current) {
                    img.removeAttribute('loading');
                }
            });
            texts.forEach((text, i) => {
                text.classList.toggle('hidden', i !== current);
                text.toggleAttribute('aria-hidden', i !== current);
            });
            dots.forEach((dot, i) => {
                if (i === current) {
                    dot.setAttribute('aria-current', 'true');
                } else {
                    dot.removeAttribute('aria-current');
                }
                dot.firstElementChild?.classList.toggle('bg-brand', i === current);
                dot.firstElementChild?.classList.toggle('bg-border-strong', i !== current);
            });
        };

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const stop = () => {
            clearInterval(timer);
            timer = null;
        };
        const start = () => {
            stop();
            if (!reduced.matches && !paused) {
                timer = setInterval(() => show(current + 1), INTERVAL);
            }
        };

        dots.forEach((dot, i) =>
            dot.addEventListener('click', () => {
                // A chosen slide is announced; auto-advance is not (it would interrupt).
                live?.setAttribute('aria-live', 'polite');
                show(i);
                start();
            }),
        );

        const pause = () => {
            paused = true;
            stop();
        };
        const resume = () => {
            paused = false;
            start();
        };
        root.addEventListener('mouseenter', pause);
        root.addEventListener('mouseleave', resume);
        root.addEventListener('focusin', pause);
        root.addEventListener('focusout', (event) => {
            if (!root.contains(event.relatedTarget)) {
                resume();
            }
        });
        reduced.addEventListener('change', start);

        start();
    });
};
