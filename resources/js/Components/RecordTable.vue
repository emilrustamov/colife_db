<script setup>
import { computed } from 'vue';
import { rtTableFluid, rtTableScroll } from './recordTableClasses';

const props = defineProps({
    variant: {
        type: String,
        default: 'scroll',
        validator: (v) => ['scroll', 'fluid'].includes(v),
    },
});

const tableClass = computed(() => (props.variant === 'fluid' ? rtTableFluid : rtTableScroll));
</script>

<template>
    <table :class="tableClass">
        <slot />
    </table>
</template>
