<script setup>
const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
});

const tones = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    available: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    aman: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    safe: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    normal: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    received: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    purchase_in: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    adjustment_in: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    cancel_usage: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    cash: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    draft: 'bg-amber-50 text-amber-700 ring-amber-200',
    low_stock: 'bg-amber-50 text-amber-700 ring-amber-200',
    near_expiry: 'bg-amber-50 text-amber-700 ring-amber-200',
    transfer: 'bg-sky-50 text-sky-700 ring-sky-200',
    qris: 'bg-sky-50 text-sky-700 ring-sky-200',
    'stok menipis': 'bg-amber-50 text-amber-700 ring-amber-200',
    expired: 'bg-red-50 text-red-700 ring-red-200',
    sale_out: 'bg-red-50 text-red-700 ring-red-200',
    usage_out: 'bg-red-50 text-red-700 ring-red-200',
    adjustment_out: 'bg-red-50 text-red-700 ring-red-200',
    depleted: 'bg-slate-100 text-slate-700 ring-slate-200',
    out_of_stock: 'bg-slate-100 text-slate-700 ring-slate-200',
    no_expiry: 'bg-sky-50 text-sky-700 ring-sky-200',
    opening_stock: 'bg-sky-50 text-sky-700 ring-sky-200',
    other: 'bg-slate-100 text-slate-700 ring-slate-200',
    'stok habis': 'bg-slate-100 text-slate-700 ring-slate-200',
    quarantined: 'bg-violet-50 text-violet-700 ring-violet-200',
    cancel_adjustment: 'bg-violet-50 text-violet-700 ring-violet-200',
    cancelled: 'bg-red-50 text-red-700 ring-red-200',
    inactive: 'bg-slate-100 text-slate-700 ring-slate-200',
};

const text = props.label || props.status.replaceAll('_', ' ');
</script>

<template>
    <span
        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold capitalize ring-1 ring-inset"
        :class="tones[status] ?? tones.inactive"
    >
        {{ text }}
    </span>
</template>
