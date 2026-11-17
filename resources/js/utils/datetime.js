/**
 * @param {unknown} value
 * @returns {boolean}
 */
export const isDateOnlyString = (value) =>
    typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value.trim());

/**
 * @param {unknown} value
 * @returns {boolean}
 */
export const isDateTimeString = (value) => {
    if (typeof value !== 'string') {
        return false;
    }

    const raw = value.trim();

    return /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/.test(raw);
};

/**
 * @param {number} value
 * @returns {string}
 */
const pad2 = (value) => String(value).padStart(2, '0');

/**
 * @param {Date} date
 * @returns {string}
 */
const formatDateParts = (date) => `${pad2(date.getDate())}.${pad2(date.getMonth() + 1)}.${date.getFullYear()}`;

/**
 * @param {Date} date
 * @returns {string}
 */
const formatTimeParts = (date) =>
    `${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(date.getSeconds())}`;

/**
 * @param {unknown} value
 * @returns {Date|null}
 */
export const toDateObject = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const raw = String(value).trim();
    if (raw === '') {
        return null;
    }

    // Date-only: keep calendar day in the browser timezone (no UTC shift).
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        const date = new Date(`${raw}T00:00:00`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    // Naive DB datetimes are UTC; Date local getters then show the user's browser timezone.
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?(?:\.\d+)?$/.test(raw)) {
        const normalized = raw.split(':').length === 2 ? `${raw}:00` : raw;
        const date = new Date(`${normalized.replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?$/.test(raw)) {
        const date = new Date(`${raw}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? null : date;
};

/**
 * @param {unknown} value
 * @param {string} [_locale='ru']
 * @returns {string}
 */
export const formatDate = (value, _locale = 'ru') => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return formatDateParts(date);
};

/**
 * @param {unknown} value
 * @param {string} [_locale='ru']
 * @returns {string}
 */
export const formatDateTime = (value, _locale = 'ru') => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return `${formatDateParts(date)} ${formatTimeParts(date)}`;
};

/**
 * Format value when it looks like a date/datetime; otherwise return null.
 *
 * @param {unknown} value
 * @param {string} [locale='ru']
 * @returns {string|null}
 */
export const formatIfDate = (value, locale = 'ru') => {
    if (isDateOnlyString(value)) {
        return formatDate(value, locale);
    }

    if (isDateTimeString(value)) {
        return formatDateTime(value, locale);
    }

    return null;
};
