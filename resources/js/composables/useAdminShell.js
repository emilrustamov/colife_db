import { computed, onMounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useAppPreferences } from './useAppPreferences';
import { useDirectoryNavigation } from '../Pages/Directories/composables/useDirectoryNavigation';
import { directoryMessages } from '../Pages/Directories/i18n/directoryMessages';

/**
 * Shared sidebar shell for directories + OL Bot pages.
 *
 * @param {{ activeKey?: import('vue').Ref<string|null>|string|null }} [options]
 */
export const useAdminShell = (options = {}) => {
    const inertiaPage = usePage();
    const messages = directoryMessages;
    const { locale, theme, initLocale, initTheme, toggleLocale, toggleTheme, t } = useAppPreferences();

    const adminMenu = computed(() => {
        const shared = inertiaPage.props.adminMenu;
        return Array.isArray(shared) ? shared : [];
    });

    const {
        menu,
        selectedKey,
        hiddenKeys,
        settingsOpen,
        contactsExpanded,
        visibleTopLevelMenu,
        visibleContactsChildren,
        isContactsChildSelected,
        loadHiddenKeys,
        syncSelectedKey,
        selectDirectory,
        setItemVisible,
    } = useDirectoryNavigation({
        directories: adminMenu,
        router,
    });

    const activeKeyOption = options.activeKey;
    const resolvedActiveKey = computed(() => {
        if (activeKeyOption && typeof activeKeyOption === 'object' && 'value' in activeKeyOption) {
            return activeKeyOption.value;
        }

        return activeKeyOption ?? null;
    });

    /**
     * @param {string} key
     * @param {string} [fallback]
     * @returns {string}
     */
    const translateMenuTitle = (key, fallback) => {
        const translated = t(messages, key);
        return translated === key ? fallback : translated;
    };

    const logout = () => {
        router.post('/logout');
    };

    const setSettingsOpen = (value) => {
        settingsOpen.value = value;
    };

    const onSetItemVisible = (key, visible) => {
        setItemVisible(key, visible);
        syncSelectedKey();
    };

    watch(resolvedActiveKey, (value) => {
        if (value) {
            selectedKey.value = value;
            syncSelectedKey();
        }
    }, { immediate: true });

    onMounted(() => {
        initLocale();
        initTheme();
        loadHiddenKeys();

        if (resolvedActiveKey.value) {
            selectedKey.value = resolvedActiveKey.value;
        }

        syncSelectedKey();
    });

    return {
        inertiaPage,
        messages,
        locale,
        theme,
        t,
        translateMenuTitle,
        menu,
        selectedKey,
        hiddenKeys,
        settingsOpen,
        contactsExpanded,
        visibleTopLevelMenu,
        visibleContactsChildren,
        isContactsChildSelected,
        setSettingsOpen,
        onSetItemVisible,
        selectDirectory,
        toggleLocale,
        toggleTheme,
        logout,
    };
};
