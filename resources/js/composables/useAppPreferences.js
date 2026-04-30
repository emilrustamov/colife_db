import { ref } from 'vue';

const getPrefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const getInitialTheme = () => {
    const stored = localStorage.getItem('theme');
    if (stored === 'dark' || stored === 'light') {
        return stored;
    }

    return getPrefersDark() ? 'dark' : 'light';
};

const theme = ref(getInitialTheme());
const locale = ref('ru');

const applyThemeClass = (value) => {
    theme.value = value === 'dark' ? 'dark' : 'light';
    document.documentElement.classList.toggle('dark', theme.value === 'dark');
};

const setTheme = (value) => {
    applyThemeClass(value);
    localStorage.setItem('theme', theme.value);
};

const initTheme = () => {
    const stored = localStorage.getItem('theme');

    if (stored === 'dark' || stored === 'light') {
        applyThemeClass(stored);
        return;
    }

    applyThemeClass(getPrefersDark() ? 'dark' : 'light');
};

const toggleTheme = () => {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
};

const initLocale = () => {
    const stored = localStorage.getItem('locale');
    locale.value = stored === 'en' ? 'en' : 'ru';
};

const setLocale = (value) => {
    locale.value = value === 'en' ? 'en' : 'ru';
    localStorage.setItem('locale', locale.value);
};

const toggleLocale = () => {
    setLocale(locale.value === 'ru' ? 'en' : 'ru');
};

const t = (messages, key) => {
    return messages?.[locale.value]?.[key] ?? messages?.ru?.[key] ?? key;
};

export const useAppPreferences = () => {
    return {
        theme,
        locale,
        initTheme,
        initLocale,
        toggleTheme,
        setLocale,
        toggleLocale,
        t,
    };
};
