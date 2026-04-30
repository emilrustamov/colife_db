import { ref } from 'vue';

export const useDirectoryTable = () => {
    const tableScrollRef = ref(null);
    const showTableScrollLeft = ref(false);
    const showTableScrollRight = ref(false);
    let tableScrollRaf = null;
    let tableScrollDir = 0;
    const tableScrollSpeed = 12;

    const updateTableScrollHints = () => {
        const el = tableScrollRef.value;

        if (!el) {
            showTableScrollLeft.value = false;
            showTableScrollRight.value = false;
            return;
        }

        const { scrollLeft, scrollWidth, clientWidth } = el;
        const overflow = scrollWidth - clientWidth;

        if (overflow <= 4) {
            showTableScrollLeft.value = false;
            showTableScrollRight.value = false;
            return;
        }

        showTableScrollLeft.value = scrollLeft > 4;
        showTableScrollRight.value = scrollLeft < overflow - 4;
    };

    const stopAutoTableScroll = () => {
        tableScrollDir = 0;

        if (tableScrollRaf !== null) {
            cancelAnimationFrame(tableScrollRaf);
            tableScrollRaf = null;
        }
    };

    const tickTableScroll = () => {
        const el = tableScrollRef.value;

        if (!el || tableScrollDir === 0) {
            tableScrollRaf = null;
            return;
        }

        const max = el.scrollWidth - el.clientWidth;

        if (max <= 0) {
            stopAutoTableScroll();
            updateTableScrollHints();
            return;
        }

        if (tableScrollDir > 0) {
            el.scrollLeft = Math.min(el.scrollLeft + tableScrollSpeed, max);
            if (el.scrollLeft >= max - 1) {
                stopAutoTableScroll();
            }
        } else {
            el.scrollLeft = Math.max(el.scrollLeft - tableScrollSpeed, 0);
            if (el.scrollLeft <= 1) {
                stopAutoTableScroll();
            }
        }

        updateTableScrollHints();
        tableScrollRaf = requestAnimationFrame(tickTableScroll);
    };

    const startAutoTableScroll = (dir) => {
        stopAutoTableScroll();
        tableScrollDir = dir;
        tableScrollRaf = requestAnimationFrame(tickTableScroll);
    };

    const nudgeTableScroll = (dir) => {
        const el = tableScrollRef.value;

        if (!el) {
            return;
        }

        const max = el.scrollWidth - el.clientWidth;
        const step = Math.max(240, Math.floor(el.clientWidth * 0.45));

        if (dir > 0) {
            el.scrollLeft = Math.min(el.scrollLeft + step, max);
        } else {
            el.scrollLeft = Math.max(el.scrollLeft - step, 0);
        }

        updateTableScrollHints();
    };

    const setTableScrollRef = (el) => {
        tableScrollRef.value = el;
    };

    return {
        tableScrollRef,
        showTableScrollLeft,
        showTableScrollRight,
        updateTableScrollHints,
        stopAutoTableScroll,
        startAutoTableScroll,
        nudgeTableScroll,
        setTableScrollRef,
    };
};
