import { computed, ref, unref } from 'vue';

export const useDirectoryNavigation = ({ directories, router, hiddenKeysStorageKey = 'colife.directories.hiddenKeys' }) => {
    const selectedKey = ref(null);
    const hiddenKeys = ref([]);
    const settingsOpen = ref(false);
    const contactsExpanded = ref(false);
    const contactChildrenKeys = ['contact-phones', 'contact-emails'];

    const menu = computed(() => {
        const source = unref(directories);
        return Array.isArray(source) ? source : [];
    });

    const visibleMenu = computed(() => menu.value.filter((item) => !hiddenKeys.value.includes(item.key)));
    const visibleTopLevelMenu = computed(() => visibleMenu.value.filter((item) => !contactChildrenKeys.includes(item.key)));
    const visibleContactsChildren = computed(() => visibleMenu.value.filter((item) => contactChildrenKeys.includes(item.key)));
    const isContactsChildSelected = computed(() => contactChildrenKeys.includes(selectedKey.value));

    /**
     * @param {string|null} key
     * @returns {{ key: string, href?: string }|null}
     */
    const findMenuItem = (key) => menu.value.find((item) => item.key === key) ?? null;

    /**
     * @param {string|null} key
     * @returns {boolean}
     */
    const isLinkItem = (key) => {
        const item = findMenuItem(key);
        return typeof item?.href === 'string' && item.href.trim() !== '';
    };

    const loadHiddenKeys = () => {
        if (typeof window === 'undefined') {
            return;
        }

        try {
            const raw = window.localStorage.getItem(hiddenKeysStorageKey);
            const parsed = raw ? JSON.parse(raw) : [];
            hiddenKeys.value = Array.isArray(parsed) ? parsed : [];
        } catch {
            hiddenKeys.value = [];
        }
    };

    const syncSelectedKey = () => {
        const visible = visibleMenu.value;
        if (visible.some((item) => item.key === selectedKey.value)) {
            return;
        }

        selectedKey.value = visible.find((item) => !item.href)?.key ?? visible[0]?.key ?? null;
    };

    /**
     * @param {string} key
     * @param {boolean} [toggleContactsExpansion]
     * @returns {void}
     */
    const selectDirectory = (key, toggleContactsExpansion = false) => {
        if (toggleContactsExpansion) {
            contactsExpanded.value = !contactsExpanded.value;
        }

        if (!key) {
            return;
        }

        const item = findMenuItem(key);
        const href = typeof item?.href === 'string' ? item.href.trim() : '';

        if (href !== '') {
            if (key === selectedKey.value && typeof window !== 'undefined') {
                const current = `${window.location.pathname}${window.location.search}`;
                if (current === href || window.location.pathname.startsWith(href)) {
                    return;
                }
            }

            selectedKey.value = key;
            router.get(href);
            return;
        }

        if (key === selectedKey.value) {
            return;
        }

        router.get(`/directories/${key}`);
    };

    const setItemVisible = (key, visible) => {
        if (visible) {
            hiddenKeys.value = hiddenKeys.value.filter((itemKey) => itemKey !== key);
        } else if (!hiddenKeys.value.includes(key)) {
            hiddenKeys.value = [...hiddenKeys.value, key];
        }

        window.localStorage.setItem(hiddenKeysStorageKey, JSON.stringify(hiddenKeys.value));
    };

    return {
        menu,
        selectedKey,
        hiddenKeys,
        settingsOpen,
        contactsExpanded,
        visibleMenu,
        visibleTopLevelMenu,
        visibleContactsChildren,
        isContactsChildSelected,
        isLinkItem,
        findMenuItem,
        loadHiddenKeys,
        syncSelectedKey,
        selectDirectory,
        setItemVisible,
    };
};
