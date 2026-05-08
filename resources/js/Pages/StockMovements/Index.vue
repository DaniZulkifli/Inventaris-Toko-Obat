<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DetailModal from '@/Components/UI/DetailModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head } from '@inertiajs/vue3';
import { Eye } from 'lucide-vue-next';

const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const detailTarget = ref(null);
const showDetailModal = ref(false);
const filterForm = ref({
    search: props.filters.search ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    medicine_id: props.filters.medicine_id ?? '',
    batch_id: props.filters.batch_id ?? '',
    movement_type: props.filters.movement_type ?? '',
    created_by: props.filters.created_by ?? '',
    reference_type: props.filters.reference_type ?? '',
});

const columns = [
    { key: 'medicine', label: 'Obat' },
    { key: 'batch_number', label: 'Batch' },
    { key: 'movement_type', label: 'Tipe' },
    { key: 'reference_label', label: 'Referensi' },
    { key: 'quantity_in', label: 'Masuk', align: 'right' },
    { key: 'quantity_out', label: 'Keluar', align: 'right' },
    { key: 'stock_after', label: 'Stok Akhir', align: 'right' },
    { key: 'created_at', label: 'Waktu' },
    { key: 'actions', label: '', align: 'right' },
];

const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value))
    : '-';

const movementTextTone = (movementType) => {
    if (['purchase_in', 'adjustment_in', 'cancel_usage'].includes(movementType)) {
        return 'text-emerald-700';
    }

    if (['sale_out', 'usage_out', 'adjustment_out'].includes(movementType)) {
        return 'text-red-700';
    }

    return 'text-slate-700';
};

useRealtimeFilters(filterForm, () => route('stock-movements.index'));

const openDetail = (movement) => {
    detailTarget.value = movement;
    showDetailModal.value = true;
};
</script>

<template>
    <Head title="Mutasi Stok" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <DataTable
                :columns="columns"
                :rows="movements.data"
                empty-title="Belum ada mutasi stok"
                empty-description="Mutasi akan muncul setelah stok awal, pembelian, penjualan, pemakaian, atau penyesuaian tercatat."
            >
                <template #filters>
                    <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-[minmax(13rem,1.2fr)_11rem_11rem_12rem_12rem_12rem_12rem]" @submit.prevent>
                        <FormInput id="movement_search" v-model="filterForm.search" label="Pencarian" placeholder="Obat, batch, referensi" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                        <SelectInput id="movement_medicine" v-model="filterForm.medicine_id" label="Obat" :options="options.medicines" placeholder="Semua obat" />
                        <SelectInput id="movement_batch" v-model="filterForm.batch_id" label="Batch" :options="options.batches" placeholder="Semua batch" />
                        <SelectInput id="movement_type" v-model="filterForm.movement_type" label="Tipe Mutasi" :options="options.movement_types" placeholder="Semua tipe" />
                        <SelectInput id="created_by" v-model="filterForm.created_by" label="Pengguna" :options="options.creators" placeholder="Semua pengguna" />
                        <SelectInput id="reference_type" v-model="filterForm.reference_type" label="Referensi" :options="options.reference_types" placeholder="Semua referensi" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'medicine'">
                        <div class="font-semibold text-slate-950">{{ row.medicine }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.medicine_code }}</div>
                    </template>
                    <template v-else-if="column.key === 'batch_number'">
                        <div class="font-semibold text-slate-900">{{ value ?? '-' }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.batch_expiry_date ?? 'Tanpa kedaluwarsa' }}</div>
                    </template>
                    <template v-else-if="column.key === 'movement_type'">
                        <StatusBadge :status="row.movement_type" :label="row.movement_label" />
                    </template>
                    <template v-else-if="column.key === 'quantity_in'">
                        <span :class="Number(value) > 0 ? 'font-semibold text-emerald-700' : 'text-slate-400'">{{ Number(value) > 0 ? formatQuantity(value) : '-' }}</span>
                    </template>
                    <template v-else-if="column.key === 'quantity_out'">
                        <span :class="Number(value) > 0 ? 'font-semibold text-red-700' : 'text-slate-400'">{{ Number(value) > 0 ? formatQuantity(value) : '-' }}</span>
                    </template>
                    <template v-else-if="column.key === 'stock_after'">{{ formatQuantity(value) }}</template>
                    <template v-else-if="column.key === 'created_at'">
                        <div class="text-slate-700">{{ formatDateTime(value) }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.created_by ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <IconButton label="Detail mutasi stok" @click="openDetail(row)">
                            <Eye class="h-4 w-4" />
                        </IconButton>
                    </template>
                    <template v-else>{{ value ?? '-' }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="movements" />
                </template>
            </DataTable>
        </div>

        <DetailModal :show="showDetailModal" :title="detailTarget?.movement_label ?? 'Detail Mutasi Stok'" max-width="3xl" @close="showDetailModal = false">
            <div v-if="detailTarget" class="space-y-5">
                <div class="grid gap-4 text-sm md:grid-cols-3">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Obat</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ detailTarget.medicine }}</div>
                        <div class="text-xs text-slate-500">{{ detailTarget.medicine_code }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Batch</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ detailTarget.batch_number ?? '-' }}</div>
                        <div class="text-xs text-slate-500">{{ detailTarget.batch_expiry_date ?? 'Tanpa kedaluwarsa' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tipe</div>
                        <div class="mt-1"><StatusBadge :status="detailTarget.movement_type" :label="detailTarget.movement_label" /></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Referensi</div>
                        <div class="mt-1 text-slate-700">{{ detailTarget.reference_label }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Pengguna</div>
                        <div class="mt-1 text-slate-700">{{ detailTarget.created_by ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Waktu</div>
                        <div class="mt-1 text-slate-700">{{ formatDateTime(detailTarget.created_at) }}</div>
                    </div>
                </div>

                <div class="grid gap-3 text-sm md:grid-cols-5">
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Masuk</div>
                        <div class="mt-1 text-lg font-semibold text-emerald-700">{{ formatQuantity(detailTarget.quantity_in) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Keluar</div>
                        <div class="mt-1 text-lg font-semibold text-red-700">{{ formatQuantity(detailTarget.quantity_out) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Sebelum</div>
                        <div class="mt-1 text-lg font-semibold text-slate-950">{{ formatQuantity(detailTarget.stock_before) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Sesudah</div>
                        <div class="mt-1 text-lg font-semibold text-slate-950">{{ formatQuantity(detailTarget.stock_after) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Harga Satuan</div>
                        <div class="mt-1 text-lg font-semibold text-slate-950">{{ formatCurrency(detailTarget.unit_cost_snapshot) }}</div>
                    </div>
                </div>

                <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-600 whitespace-pre-line">
                    {{ detailTarget.description ?? '-' }}
                </div>
            </div>
        </DetailModal>
    </AuthenticatedLayout>
</template>
