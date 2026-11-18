<script setup>
import IndexPage from './Index.vue';

defineProps({
    directories: {
        type: Array,
        default: () => [],
    },
    initialDirectoryKey: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <IndexPage :directories="directories" :initial-directory-key="initialDirectoryKey" />
</template>
