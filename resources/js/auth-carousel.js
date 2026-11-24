// Auth carousel (canvas boards AuthLogin / AuthSignup, note "Auth carousel behaviour"):
// advances every 6 s, pauses on hover and focus, never auto-plays with reduced motion,
// and the dots are buttons. Animated with Motion (motion.dev): the photo crossfades with a
// slow zoom, the copy rises in, and the active dot fills over the 6 s. Motion animates
// through the Web Animations API, so nothing writes a style attribute (strict CSP).

import { animate, stagger } from 'motion';

const INTERVAL = 6000;
const EASE = [0.22, 1, 0.36, 1];

export const initAuthCarousels = () => {
    document.querySelectorAll('[data-carousel]').forEach((root) => {
        const images = [...root.querySelectorAll('[data-slide-image]')];
        const texts = [...root.querySelectorAll('[data-slide-text]')];
        const dots = [...root.querySelectorAll('[data-slide-dot]')];
        const fills = dots.map((dot) => dot.querySelector('[data-dot-fill]'));
        const live = root.querySelector('[data-slide-copy]');
        if (images.length < 2) {
            return;
        }

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let current = Math.max(0, dots.findIndex((dot) => dot.getAttribute('aria-current') === 'true'));
        let timer = null;
        let paused = false;
        let progress = null;

        // Hand the server-rendered state over to Motion: from here it owns opacity and transforms.
        images.forEach((img, i) => {
            img.classList.remove('opacity-0', 'opacity-100', 'transition-opacity', 'duration-700');
            animate(img, { opacity: i === current ? 1 : 0 }, { duration: 0 });
        });
        fills.forEach((fill, i) => {
            if (fill) {
                fill.classList.remove('hidden');
                animate(fill, { scaleX: i === current ? 1 : 0 }, { duration: 0 });
            }
        });

        // The active dot fills over the interval while the carousel plays; full when it is still.
        const fillDot = (index) => {
            progress?.stop();
            progress = null;
            fills.forEach((fill, i) => {
                if (fill && i !== index) {
                    animate(fill, { scaleX: 0 }, { duration: 0 });
                }
            });
            const fill = fills[index];
            if (!fill) {
                return;
            }
            if (reduced.matches || paused) {
                animate(fill, { scaleX: 1 }, { duration: 0 });
                return;
            }
            progress = animate(fill, { scaleX: [0, 1] }, { duration: INTERVAL / 1000, ease: 'linear' });
        };

        const zoom = (img) => animate(img, { scale: [1.08, 1] }, { duration: INTERVAL / 1000 + 1, ease: 'easeOut' });

        const show = (index) => {
            const previous = current;
            current = (index + images.length) % images.length;
            const still = reduced.matches;

            images.forEach((img, i) => {
                if (i === current) {
                    img.removeAttribute('loading');
                    animate(img, { opacity: 1 }, { duration: still ? 0 : 0.9, ease: 'easeOut' });
                    if (!still) {
                        zoom(img);
                    }
                } else if (i === previous || still) {
                    animate(img, { opacity: 0 }, { duration: still ? 0 : 0.9, ease: 'easeOut' });
                }
            });

            texts.forEach((text, i) => {
                text.classList.toggle('hidden', i !== current);
                text.toggleAttribute('aria-hidden', i !== current);
            });
            if (!still) {
                animate(texts[current].children, { opacity: [0, 1], y: [18, 0] }, { duration: 0.6, delay: stagger(0.08), ease: EASE });
            }

            dots.forEach((dot, i) => {
                if (i === current) {
                    dot.setAttribute('aria-current', 'true');
                } else {
                    dot.removeAttribute('aria-current');
                }
            });
        };

        const stop = () => {
            clearInterval(timer);
            timer = null;
        };
        const start = () => {
            stop();
            if (!reduced.matches && !paused) {
                timer = setInterval(() => {
                    show(current + 1);
                    fillDot(current);
                }, INTERVAL);
            }
            fillDot(current);
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
            progress?.pause();
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

        if (!reduced.matches) {
            zoom(images[current]);
        }
        start();
    });
};
