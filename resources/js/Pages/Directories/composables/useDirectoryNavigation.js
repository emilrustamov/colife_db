import { computed, ref } from 'vue';

export const useDirectoryNavigation = ({ directories, router, hiddenKeysStorageKey = 'colife.directories.hiddenKeys' }) => {
    const menu = ref(directories);
    const selectedKey = ref(null);
    const hiddenKeys = ref([]);
    const settingsOpen = ref(false);
    const contactsExpanded = ref(false);
    const contactChildrenKeys = ['contact-phones', 'contact-emails'];

    const visibleMenu = computed(() => menu.value.filter((item) => !hiddenKeys.value.includes(item.key)));
    const visibleTopLevelMenu = computed(() => visibleMenu.value.filter((item) => !contactChildrenKeys.includes(item.key)));
    const visibleContactsChildren = computed(() => visibleMenu.value.filter((item) => contactChildrenKeys.includes(item.key)));
    const isContactsChildSelected = computed(() => contactChildrenKeys.includes(selectedKey.value));

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
        if (!visible.some((item) => item.key === selectedKey.value)) {
            selectedKey.value = visible[0]?.key ?? null;
        }
    };

    const selectDirectory = (key, toggleContactsExpansion = false) => {
        if (toggleContactsExpansion) {
            contactsExpanded.value = !contactsExpanded.value;
        }

        if (!key || key === selectedKey.value) {
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
        loadHiddenKeys,
        syncSelectedKey,
        selectDirectory,
        setItemVisible,
    };
};
