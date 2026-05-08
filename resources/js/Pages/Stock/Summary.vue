<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head } from '@inertiajs/vue3';
import { CalendarClock, Layers3, PackageSearch } from 'lucide-vue-next';

const props = defineProps({
    medicines: { type: Object, required: true },
    batches: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const filterForm = ref({
    tab: props.filters.tab ?? 'medicines',
    search: props.filters.search ?? '',
    stock_status: props.filters.stock_status ?? 'all',
    batch_status: props.filters.batch_status ?? '',
    category_id: props.filters.category_id ?? '',
    medicine_id: props.filters.medicine_id ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    expiry_from: props.filters.expiry_from ?? '',
    expiry_to: props.filters.expiry_to ?? '',
});

const stockColumns = [
    { key: 'name', label: 'Obat' },
    { key: 'category', label: 'Kategori' },
    { key: 'saleable_stock', label: 'Stok Jual', align: 'right' },
    { key: 'minimum_stock', label: 'Min', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'selling_price', label: 'Harga Jual', align: 'right' },
];

const batchColumns = [
    { key: 'medicine', label: 'Obat' },
    { key: 'batch_number', label: 'Batch' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'current_stock', label: 'Stok', align: 'right' },
    { key: 'expiry_date', label: 'Kedaluwarsa' },
    { key: 'status', label: 'Status Batch' },
    { key: 'expiry_state', label: 'Status Kedaluwarsa' },
];

const currentTab = computed(() => filterForm.value.tab);

const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '-';

useRealtimeFilters(filterForm, () => route('stock.summary'));

const switchTab = (tab) => {
    filterForm.value.tab = tab;
};
</script>

<template>
    <Head title="Monitoring Stok dan Batch" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <div class="inline-flex w-full rounded-md border border-slate-200 bg-white p-1 shadow-sm sm:w-auto">
                    <button
                        type="button"
                        class="inline-flex min-h-9 flex-1 items-center justify-center gap-2 rounded px-3 text-sm font-semibold transition sm:flex-none"
                        :class="currentTab === 'medicines' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                        @click="switchTab('medicines')"
                    >
                        <PackageSearch class="h-4 w-4" />
                        Stok Obat
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-9 flex-1 items-center justify-center gap-2 rounded px-3 text-sm font-semibold transition sm:flex-none"
                        :class="currentTab === 'batches' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                        @click="switchTab('batches')"
                    >
                        <Layers3 class="h-4 w-4" />
                        Batch
                    </button>
                </div>
            </div>

            <DataTable
                v-if="currentTab === 'medicines'"
                :columns="stockColumns"
                :rows="medicines.data"
                empty-title="Belum ada stok obat"
                empty-description="Data stok akan tampil sesuai filter yang dipilih."
            >
                <template #filters>
                    <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-[minmax(13rem,1.3fr)_11rem_12rem_12rem_12rem]" @submit.prevent>
                        <FormInput id="stock_search" v-model="filterForm.search" label="Pencarian" placeholder="Kode, nama, generik" />
                        <SelectInput id="stock_status" v-model="filterForm.stock_status" label="Status Stok" :options="options.stock_statuses" />
                        <SelectInput id="stock_category" v-model="filterForm.category_id" label="Kategori" :options="options.categories" placeholder="Semua kategori" />
                        <SelectInput id="stock_medicine" v-model="filterForm.medicine_id" label="Obat" :options="options.medicines" placeholder="Semua obat" />
                        <SelectInput id="stock_supplier" v-model="filterForm.supplier_id" label="Supplier" :options="options.suppliers" placeholder="Semua supplier" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'name'">
                        <div class="font-semibold text-slate-950">{{ row.name }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.code }} / {{ row.unit ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'saleable_stock'">
                        <span class="font-semibold text-slate-950">{{ formatQuantity(value) }}</span>
                    </template>
                    <template v-else-if="column.key === 'minimum_stock'">{{ formatQuantity(value) }}</template>
                    <template v-else-if="column.key === 'status'">
                        <StatusBadge :status="row.status" :label="row.status_label" />
                    </template>
                    <template v-else-if="column.key === 'selling_price'">{{ formatCurrency(value) }}</template>
                    <template v-else>{{ value ?? '-' }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="medicines" />
                </template>
            </DataTable>

            <DataTable
                v-else
                :columns="batchColumns"
                :rows="batches.data"
                empty-title="Belum ada batch"
                empty-description="Batch akan tampil sesuai filter yang dipilih."
            >
                <template #filters>
                    <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-[minmax(13rem,1.2fr)_11rem_12rem_12rem_12rem_11rem_11rem]" @submit.prevent>
                        <FormInput id="batch_search" v-model="filterForm.search" label="Pencarian" placeholder="Obat atau batch" />
                        <SelectInput id="batch_status" v-model="filterForm.batch_status" label="Status Batch" :options="options.batch_statuses" placeholder="Semua status" />
                        <SelectInput id="batch_category" v-model="filterForm.category_id" label="Kategori" :options="options.categories" placeholder="Semua kategori" />
                        <SelectInput id="batch_medicine" v-model="filterForm.medicine_id" label="Obat" :options="options.medicines" placeholder="Semua obat" />
                        <SelectInput id="batch_supplier" v-model="filterForm.supplier_id" label="Supplier" :options="options.suppliers" placeholder="Semua supplier" />
                        <DateInput id="expiry_from" v-model="filterForm.expiry_from" label="Kedaluwarsa Dari" />
                        <DateInput id="expiry_to" v-model="filterForm.expiry_to" label="Kedaluwarsa Sampai" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'medicine'">
                        <div class="font-semibold text-slate-950">{{ row.medicine }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.medicine_code }} / {{ row.category ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'batch_number'">
                        <div class="font-semibold text-slate-900">{{ value }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.unit ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'current_stock'">{{ formatQuantity(value) }}</template>
                    <template v-else-if="column.key === 'expiry_date'">
                        <span class="inline-flex items-center gap-1">
                            <CalendarClock class="h-4 w-4 text-slate-400" />
                            {{ formatDate(value) }}
                        </span>
                    </template>
                    <template v-else-if="column.key === 'status'">
                        <StatusBadge :status="row.status" :label="row.status_label" />
                    </template>
                    <template v-else-if="column.key === 'expiry_state'">
                        <StatusBadge :status="row.expiry_state" :label="row.expiry_label" />
                    </template>
                    <template v-else>{{ value ?? '-' }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="batches" />
                </template>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
