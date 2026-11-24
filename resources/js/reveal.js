// Scroll reveal with Motion (motion.dev): the [data-reveal] children of a [data-reveal-group]
// rise and fade in, one after another, the first time the group scrolls into view. Without
// JavaScript, or with reduced motion, everything is simply shown. Motion animates through
// the Web Animations API, so nothing writes a style attribute (strict CSP).

import { animate, inView, stagger } from 'motion';

const EASE = [0.22, 1, 0.36, 1];

export const initReveals = () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    document.querySelectorAll('[data-reveal-group]').forEach((group) => {
        const items = [...group.querySelectorAll('[data-reveal]')];
        if (items.length === 0) {
            return;
        }

        animate(items, { opacity: 0, y: 32, scale: 0.97 }, { duration: 0 });

        inView(
            group,
            () => {
                animate(items, { opacity: [0, 1], y: [32, 0], scale: [0.97, 1] }, { duration: 0.8, delay: stagger(0.1), ease: EASE });
            },
            { amount: 0.15 },
        );
    });
};
