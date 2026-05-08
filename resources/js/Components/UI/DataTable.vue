<script setup>
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next';
import Spinner from './Spinner.vue';

const props = defineProps({
    columns: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    sortKey: { type: String, default: '' },
    sortDirection: { type: String, default: 'asc' },
    loading: { type: Boolean, default: false },
    emptyTitle: { type: String, default: 'Belum ada data' },
    emptyDescription: { type: String, default: 'Data akan tampil setelah tersedia.' },
});

const emit = defineEmits(['sort']);

const sortColumn = (column) => {
    if (!column.sortable) {
        return;
    }

    emit('sort', {
        key: column.key,
        direction: props.sortKey === column.key && props.sortDirection === 'asc' ? 'desc' : 'asc',
    });
};
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <div v-if="$slots.filters" class="border-b border-slate-200 bg-slate-50 p-4">
            <slot name="filters" />
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500"
                            :class="{ 'text-right': column.align === 'right' }"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md text-left hover:text-emerald-700"
                                @click="sortColumn(column)"
                            >
                                <span>{{ column.label }}</span>
                                <ArrowUp v-if="sortKey === column.key && sortDirection === 'asc'" class="h-3.5 w-3.5" />
                                <ArrowDown v-else-if="sortKey === column.key" class="h-3.5 w-3.5" />
                                <ChevronsUpDown v-else class="h-3.5 w-3.5" />
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-if="loading">
                        <td :colspan="columns.length" class="px-4 py-10 text-center text-slate-500">
                            <span class="inline-flex items-center gap-2">
                                <Spinner size="sm" /> Memuat data
                            </span>
                        </td>
                    </tr>
                    <tr v-else-if="rows.length === 0">
                        <td :colspan="columns.length" class="px-4 py-10 text-center">
                            <div class="font-semibold text-slate-700">{{ emptyTitle }}</div>
                            <div class="mt-1 text-sm text-slate-500">{{ emptyDescription }}</div>
                        </td>
                    </tr>
                    <tr v-for="row in rows" v-else :key="row.id ?? JSON.stringify(row)" class="hover:bg-emerald-50/40">
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-3 text-slate-700"
                            :class="{ 'text-right': column.align === 'right' }"
                        >
                            <slot name="cell" :row="row" :column="column" :value="row[column.key]">
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="$slots.pagination" class="border-t border-slate-200 bg-white px-4 py-3">
            <slot name="pagination" />
        </div>
    </div>
</template>
