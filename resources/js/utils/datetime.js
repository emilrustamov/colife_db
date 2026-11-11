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

    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
        const date = new Date(`${raw.replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(raw)) {
        const date = new Date(`${raw}Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? null : date;
};

/**
 * @param {unknown} value
 * @param {string} [locale='ru']
 * @returns {string}
 */
export const formatDateTime = (value, locale = 'ru') => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = toDateObject(value);
    if (date === null) {
        return String(value);
    }

    return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
};
