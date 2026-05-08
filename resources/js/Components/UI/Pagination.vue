<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps({
    meta: {
        type: Object,
        default: () => ({
            current_page: 1,
            last_page: 1,
            from: 0,
            to: 0,
            total: 0,
            links: [],
        }),
    },
});
</script>

<template>
    <div class="flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
        <div>
            Menampilkan <span class="font-semibold text-slate-900">{{ meta.from ?? 0 }}</span>
            sampai <span class="font-semibold text-slate-900">{{ meta.to ?? 0 }}</span>
            dari <span class="font-semibold text-slate-900">{{ meta.total ?? 0 }}</span> data
        </div>

        <div class="flex items-center gap-1">
            <template v-for="link in meta.links ?? []" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-2 font-semibold"
                    :class="link.active
                        ? 'border-emerald-600 bg-emerald-600 text-white'
                        : 'border-slate-200 bg-white text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                >
                    <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                    <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                    <span v-else v-html="link.label" />
                </Link>
                <span
                    v-else
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-slate-100 bg-slate-50 px-2 text-slate-400"
                >
                    <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                    <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                    <span v-else v-html="link.label" />
                </span>
            </template>
        </div>
    </div>
</template>
