<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DetailModal from '@/Components/UI/DetailModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Eye, Pencil, Plus, RotateCcw, Undo2, X } from 'lucide-vue-next';

const props = defineProps({
    adjustments: { type: Object, required: true },
    filters: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
    canCancelApproved: { type: Boolean, default: false },
    options: { type: Object, required: true },
});

const today = () => new Date().toISOString().slice(0, 10);
const blankItem = () => ({
    medicine_batch_id: '',
    counted_stock: 0,
    notes: '',
});

const mode = ref('create');
const selected = ref(null);
const detailTarget = ref(null);
const approveTarget = ref(null);
const cancelTarget = ref(null);
const showDetailModal = ref(false);
const showApproveModal = ref(false);
const showCancelModal = ref(false);
const approveProcessing = ref(false);
const cancelForm = useForm({
    cancel_reason: '',
});

const filterForm = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

const form = useForm({
    adjustment_date: today(),
    reason: '',
    items: [blankItem()],
});

const batchMap = computed(() => new Map(props.options.batches.map((item) => [String(item.id), item])));
const columns = [
    { key: 'code', label: 'Kode', sortable: true },
    { key: 'adjustment_date', label: 'Tanggal' },
    { key: 'status', label: 'Status' },
    { key: 'items_count', label: 'Item' },
    { key: 'created_by', label: 'Dibuat Oleh' },
    { key: 'actions', label: '', align: 'right' },
];

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '-';
const statusLabel = (value) => props.options.statuses.find((item) => item.value === value)?.label ?? value;
const selectedBatch = (item) => batchMap.value.get(String(item.medicine_batch_id));
const systemStockFor = (item) => Number(selectedBatch(item)?.current_stock ?? 0);
const differenceFor = (item) => Number(item.counted_stock || 0) - systemStockFor(item);
const totalAbsoluteDifference = computed(() => form.items.reduce((total, item) => total + Math.abs(differenceFor(item)), 0));
const itemError = (index, key) => form.errors[`items.${index}.${key}`] ?? '';

const resetForm = () => {
    mode.value = 'create';
    selected.value = null;
    form.clearErrors();
    form.adjustment_date = today();
    form.reason = '';
    form.items = [blankItem()];
};

const openEdit = (adjustment) => {
    mode.value = 'edit';
    selected.value = adjustment;
    form.clearErrors();
    form.adjustment_date = adjustment.adjustment_date;
    form.reason = adjustment.reason ?? '';
    form.items = adjustment.items.map((item) => ({
        medicine_batch_id: item.medicine_batch_id,
        counted_stock: item.counted_stock,
        notes: item.notes ?? '',
    }));
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const addItem = () => {
    form.items.push(blankItem());
};

const removeItem = (index) => {
    if (form.items.length === 1) {
        return;
    }

    form.items.splice(index, 1);
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    };

    mode.value === 'create'
        ? form.post(route('stock-adjustments.store'), options)
        : form.patch(route('stock-adjustments.update', selected.value.id), options);
};

useRealtimeFilters(filterForm, () => route('stock-adjustments.index'));

const openDetail = (adjustment) => {
    detailTarget.value = adjustment;
    showDetailModal.value = true;
};

const confirmApprove = (adjustment) => {
    approveTarget.value = adjustment;
    showApproveModal.value = true;
};

const approve = () => {
    approveProcessing.value = true;
    router.post(route('stock-adjustments.approve', approveTarget.value.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            approveProcessing.value = false;
            showApproveModal.value = false;
        },
    });
};

const confirmCancel = (adjustment) => {
    cancelTarget.value = adjustment;
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancelModal.value = true;
};

const cancelApproved = () => {
    cancelForm.post(route('stock-adjustments.cancel', cancelTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Penyesuaian Stok" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <UiButton variant="secondary" @click="resetForm">
                    <RotateCcw class="h-4 w-4" />
                    Atur Ulang Form
                </UiButton>
            </div>

            <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">
                            {{ mode === 'create' ? 'Form Penyesuaian Stok' : `Ubah ${selected?.code}` }}
                        </h3>
                        <p v-if="form.errors.status || form.errors.stock" class="mt-1 text-sm font-medium text-red-600">
                            {{ form.errors.status || form.errors.stock }}
                        </p>
                    </div>
                    <div class="text-right text-sm">
                        <div class="text-xs text-slate-500">Total selisih absolut</div>
                        <div class="text-lg font-semibold text-emerald-700">{{ formatQuantity(totalAbsoluteDifference) }}</div>
                    </div>
                </div>

                <form class="space-y-5 p-4" @submit.prevent="submit">
                    <div class="grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)]">
                        <DateInput id="adjustment_date" v-model="form.adjustment_date" label="Tanggal" required :error="form.errors.adjustment_date" />
                        <TextareaInput id="reason" v-model="form.reason" label="Alasan" required :error="form.errors.reason" />
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-slate-800">Item Opname Stok</h4>
                            <UiButton size="sm" variant="secondary" @click="addItem">
                                <Plus class="h-4 w-4" />
                                Tambah Item
                            </UiButton>
                        </div>
                        <p v-if="form.errors.items" class="text-xs font-medium text-red-600">{{ form.errors.items }}</p>

                        <div v-for="(item, index) in form.items" :key="index" class="rounded-md border border-slate-200 p-3">
                            <div class="grid gap-3 xl:grid-cols-[minmax(16rem,1.6fr)_9rem_9rem_9rem_minmax(12rem,1fr)_auto]">
                                <SelectInput
                                    :id="`adjustment_item_${index}_batch`"
                                    v-model="item.medicine_batch_id"
                                    label="Batch"
                                    :options="options.batches"
                                    required
                                    :error="itemError(index, 'medicine_batch_id')"
                                />
                                <div>
                                    <div class="block text-sm font-semibold text-slate-700">Stok Sistem</div>
                                    <div class="mt-1 flex min-h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-900">
                                        {{ formatQuantity(systemStockFor(item)) }}
                                    </div>
                                </div>
                                <NumberInput
                                    :id="`adjustment_item_${index}_counted`"
                                    v-model="item.counted_stock"
                                    label="Stok Fisik"
                                    required
                                    min="0"
                                    step="0.001"
                                    :error="itemError(index, 'counted_stock')"
                                />
                                <div>
                                    <div class="block text-sm font-semibold text-slate-700">Selisih</div>
                                    <div
                                        class="mt-1 flex min-h-10 items-center rounded-md border px-3 text-sm font-semibold"
                                        :class="differenceFor(item) === 0
                                            ? 'border-slate-200 bg-slate-50 text-slate-900'
                                            : differenceFor(item) > 0
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : 'border-red-200 bg-red-50 text-red-700'"
                                    >
                                        {{ formatQuantity(differenceFor(item)) }}
                                    </div>
                                </div>
                                <FormInput :id="`adjustment_item_${index}_notes`" v-model="item.notes" label="Catatan Item" :error="itemError(index, 'notes')" />
                                <div class="flex items-end justify-end">
                                    <IconButton label="Hapus item" variant="danger" :disabled="form.items.length === 1" @click="removeItem(index)">
                                        <X class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                            <div v-if="selectedBatch(item)" class="mt-2 text-xs text-slate-500">
                                {{ selectedBatch(item).medicine_code }} - {{ selectedBatch(item).medicine }} / {{ selectedBatch(item).batch_number }} / status {{ selectedBatch(item).status }}
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                        <UiButton v-if="mode === 'edit'" variant="secondary" :disabled="form.processing" @click="resetForm">
                            <RotateCcw class="h-4 w-4" />
                            Batal Edit
                        </UiButton>
                        <UiButton type="submit" :loading="form.processing">
                            <CheckCircle2 class="h-4 w-4" />
                            {{ mode === 'create' ? 'Simpan Draft' : 'Simpan Perubahan' }}
                        </UiButton>
                    </div>
                </form>
            </section>

            <DataTable :columns="columns" :rows="adjustments.data" empty-title="Belum ada penyesuaian stok">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_12rem_11rem_11rem]" @submit.prevent>
                        <FormInput id="search" v-model="filterForm.search" label="Pencarian" placeholder="Kode, obat, batch, alasan" />
                        <SelectInput id="status_filter" v-model="filterForm.status" label="Status" :options="options.statuses" placeholder="Semua status" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'code'">
                        <div class="font-semibold text-slate-950">{{ row.code }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.items_count }} item</div>
                    </template>
                    <template v-else-if="column.key === 'adjustment_date'">{{ formatDate(value) }}</template>
                    <template v-else-if="column.key === 'status'">
                        <StatusBadge :status="value" :label="statusLabel(value)" />
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Detail penyesuaian stok" @click="openDetail(row)">
                                <Eye class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Ubah draft" :disabled="!row.can_edit" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Setujui penyesuaian" variant="primary" :disabled="!row.can_approve" @click="confirmApprove(row)">
                                <CheckCircle2 class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Batalkan yang disetujui" :disabled="!row.can_cancel" @click="confirmCancel(row)">
                                <Undo2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="adjustments" />
                </template>
            </DataTable>
        </div>

        <DetailModal :show="showDetailModal" :title="detailTarget?.code ?? 'Detail Penyesuaian Stok'" max-width="4xl" @close="showDetailModal = false">
            <div v-if="detailTarget" class="space-y-5">
                <div class="grid gap-3 text-sm md:grid-cols-4">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tanggal</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatDate(detailTarget.adjustment_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
                        <div class="mt-1"><StatusBadge :status="detailTarget.status" :label="statusLabel(detailTarget.status)" /></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Dibuat Oleh</div>
                        <div class="mt-1 text-slate-700">{{ detailTarget.created_by ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Disetujui Oleh</div>
                        <div class="mt-1 text-slate-700">{{ detailTarget.approved_by ?? '-' }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Obat</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3 text-right">Sistem</th>
                                <th class="px-4 py-3 text-right">Fisik</th>
                                <th class="px-4 py-3 text-right">Selisih</th>
                                <th class="px-4 py-3 text-right">Biaya</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in detailTarget.items" :key="item.id">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">{{ item.medicine }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ item.medicine_code }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ item.batch_number }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.system_stock) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.counted_stock) }}</td>
                                <td class="px-4 py-3 text-right font-semibold" :class="Number(item.difference) >= 0 ? 'text-emerald-700' : 'text-red-700'">{{ formatQuantity(item.difference) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(item.cost_snapshot) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-600 whitespace-pre-line">
                    {{ detailTarget.reason }}
                </div>
            </div>
        </DetailModal>

        <Modal :show="showApproveModal" max-width="md" :closeable="!approveProcessing" @close="showApproveModal = false">
            <div class="px-6 py-5">
                <h2 class="text-lg font-semibold text-slate-950">Setujui penyesuaian stok?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Selisih pada <span class="font-semibold text-slate-950">{{ approveTarget?.code }}</span> akan mengubah stok batch.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <UiButton variant="secondary" :disabled="approveProcessing" @click="showApproveModal = false">Batal</UiButton>
                    <UiButton :loading="approveProcessing" @click="approve">Setujui</UiButton>
                </div>
            </div>
        </Modal>

        <Modal :show="showCancelModal" max-width="md" :closeable="!cancelForm.processing" @close="showCancelModal = false">
            <form class="px-6 py-5" @submit.prevent="cancelApproved">
                <h2 class="text-lg font-semibold text-slate-950">Batalkan penyesuaian yang disetujui?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Mutasi dari <span class="font-semibold text-slate-950">{{ cancelTarget?.code }}</span> akan dibalik dengan pembatalan penyesuaian.
                </p>
                <TextareaInput id="cancel_reason" v-model="cancelForm.cancel_reason" class="mt-4" label="Alasan Pembatalan" required :error="cancelForm.errors.cancel_reason" />
                <div class="mt-6 flex justify-end gap-2">
                    <UiButton variant="secondary" :disabled="cancelForm.processing" @click="showCancelModal = false">Batal</UiButton>
                    <UiButton type="submit" :loading="cancelForm.processing">Batalkan Penyesuaian</UiButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
