<script setup>
import { computed } from 'vue';
import Sidebar from '../Pages/Directories/components/Sidebar.vue';
import { useAdminShell } from '../composables/useAdminShell';

const props = defineProps({
    activeKey: {
        type: String,
        default: null,
    },
});

const activeKeyRef = computed(() => props.activeKey);

const {
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
} = useAdminShell({ activeKey: activeKeyRef });
</script>

<template>
    <div class="ui-shell">
        <div class="ui-frame">
            <Sidebar
                :menu="menu"
                :visible-top-level-menu="visibleTopLevelMenu"
                :visible-contacts-children="visibleContactsChildren"
                :contacts-expanded="contactsExpanded"
                :is-contacts-child-selected="isContactsChildSelected"
                :selected-key="selectedKey"
                :hidden-keys="hiddenKeys"
                :settings-open="settingsOpen"
                :inertia-page="inertiaPage"
                :locale="locale"
                :theme="theme"
                :t="t"
                :messages="messages"
                :translate-menu-title="translateMenuTitle"
                :set-settings-open="setSettingsOpen"
                :set-item-visible="onSetItemVisible"
                :select-directory="selectDirectory"
                :toggle-locale="toggleLocale"
                :toggle-theme="toggleTheme"
                :logout="logout"
            />
            <div class="ui-page ui-scroll min-h-0 overflow-y-auto lg:!h-full">
                <div class="ui-enter space-y-4">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
