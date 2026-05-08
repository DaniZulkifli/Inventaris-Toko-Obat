<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full border-l-4 border-emerald-500 bg-emerald-50 py-2 pe-4 ps-3 text-start text-base font-medium text-emerald-700 transition duration-150 ease-in-out focus:border-emerald-700 focus:bg-emerald-100 focus:text-emerald-800 focus:outline-none'
        : 'block w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-slate-600 transition duration-150 ease-in-out hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800 focus:border-slate-300 focus:bg-slate-50 focus:text-slate-800 focus:outline-none'
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
