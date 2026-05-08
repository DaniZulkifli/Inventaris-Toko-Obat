<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
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
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Eye, Pencil, Plus, RotateCcw, Search, Trash2, Undo2, X } from 'lucide-vue-next';

const props = defineProps({
    stockUsages: { type: Object, required: true },
    filters: { type: Object, required: true },
    canCancelCompleted: { type: Boolean, default: false },
    options: { type: Object, required: true },
});

const today = () => new Date().toISOString().slice(0, 10);
const blankItem = () => ({
    medicine_batch_id: '',
    quantity: 1,
    notes: '',
});

const mode = ref('create');
const selected = ref(null);
const detailTarget = ref(null);
const deleteTarget = ref(null);
const completeTarget = ref(null);
const cancelTarget = ref(null);
const showDetailModal = ref(false);
const showDeleteModal = ref(false);
const showCompleteModal = ref(false);
const showCancelModal = ref(false);
const deleteProcessing = ref(false);
const completeProcessing = ref(false);
const cancelForm = useForm({
    cancel_reason: '',
});

const filterForm = ref({
    search: props.filters.search ?? '',
    usage_type: props.filters.usage_type ?? '',
    status: props.filters.status ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

const form = useForm({
    usage_date: today(),
    usage_type: 'damaged',
    reason: '',
    items: [blankItem()],
});

const batchMap = computed(() => new Map(props.options.batches.map((item) => [String(item.id), item])));
const columns = [
    { key: 'code', label: 'Kode', sortable: true },
    { key: 'usage_type', label: 'Tipe' },
    { key: 'usage_date', label: 'Tanggal' },
    { key: 'status', label: 'Status' },
    { key: 'estimated_total_cost', label: 'Estimasi', align: 'right' },
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
const usageTypeLabel = (value) => props.options.usage_types.find((item) => item.value === value)?.label ?? value;
const statusLabel = (value) => props.options.statuses.find((item) => item.value === value)?.label ?? value;
const selectedBatch = (item) => batchMap.value.get(String(item.medicine_batch_id));
const itemEstimatedCost = (item) => Number(item.quantity || 0) * Number(selectedBatch(item)?.purchase_price ?? 0);
const estimatedTotal = computed(() => form.items.reduce((total, item) => total + itemEstimatedCost(item), 0));
const itemError = (index, key) => form.errors[`items.${index}.${key}`] ?? '';

const resetForm = () => {
    mode.value = 'create';
    selected.value = null;
    form.clearErrors();
    form.usage_date = today();
    form.usage_type = 'damaged';
    form.reason = '';
    form.items = [blankItem()];
};

const openEdit = (stockUsage) => {
    mode.value = 'edit';
    selected.value = stockUsage;
    form.clearErrors();
    form.usage_date = stockUsage.usage_date;
    form.usage_type = stockUsage.usage_type;
    form.reason = stockUsage.reason ?? '';
    form.items = stockUsage.items.map((item) => ({
        medicine_batch_id: item.medicine_batch_id,
        quantity: item.quantity,
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
        ? form.post(route('stock-usages.store'), options)
        : form.patch(route('stock-usages.update', selected.value.id), options);
};

const applyFilters = () => {
    router.get(route('stock-usages.index'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const openDetail = (stockUsage) => {
    detailTarget.value = stockUsage;
    showDetailModal.value = true;
};

const confirmDelete = (stockUsage) => {
    deleteTarget.value = stockUsage;
    showDeleteModal.value = true;
};

const destroy = () => {
    deleteProcessing.value = true;
    router.delete(route('stock-usages.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};

const confirmComplete = (stockUsage) => {
    completeTarget.value = stockUsage;
    showCompleteModal.value = true;
};

const complete = () => {
    completeProcessing.value = true;
    router.post(route('stock-usages.complete', completeTarget.value.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            completeProcessing.value = false;
            showCompleteModal.value = false;
        },
    });
};

const confirmCancel = (stockUsage) => {
    cancelTarget.value = stockUsage;
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancelModal.value = true;
};

const cancelCompleted = () => {
    cancelForm.post(route('stock-usages.cancel', cancelTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Stock Usage" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-950">Stock Usage</h2>
                    <p class="mt-1 text-sm text-slate-500">Draft tidak mengubah stok; stok berkurang saat completed</p>
                </div>
                <UiButton variant="secondary" @click="resetForm">
                    <RotateCcw class="h-4 w-4" />
                    Reset Form
                </UiButton>
            </div>

            <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">
                            {{ mode === 'create' ? 'Form Stock Usage' : `Ubah ${selected?.code}` }}
                        </h3>
                        <p v-if="form.errors.status || form.errors.stock" class="mt-1 text-sm font-medium text-red-600">
                            {{ form.errors.status || form.errors.stock }}
                        </p>
                    </div>
                    <div class="text-right text-sm">
                        <div class="text-xs text-slate-500">Estimasi biaya</div>
                        <div class="text-lg font-semibold text-emerald-700">{{ formatCurrency(estimatedTotal) }}</div>
                    </div>
                </div>

                <form class="space-y-5 p-4" @submit.prevent="submit">
                    <div class="grid gap-4 lg:grid-cols-[12rem_14rem_minmax(0,1fr)]">
                        <DateInput id="usage_date" v-model="form.usage_date" label="Tanggal" required :error="form.errors.usage_date" />
                        <SelectInput id="usage_type" v-model="form.usage_type" label="Tipe Usage" :options="options.usage_types" required :error="form.errors.usage_type" />
                        <TextareaInput id="reason" v-model="form.reason" label="Alasan" required :error="form.errors.reason" />
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-slate-800">Item Stok Keluar</h4>
                            <UiButton size="sm" variant="secondary" @click="addItem">
                                <Plus class="h-4 w-4" />
                                Tambah Item
                            </UiButton>
                        </div>
                        <p v-if="form.errors.items" class="text-xs font-medium text-red-600">{{ form.errors.items }}</p>

                        <div v-for="(item, index) in form.items" :key="index" class="rounded-md border border-slate-200 p-3">
                            <div class="grid gap-3 xl:grid-cols-[minmax(16rem,1.5fr)_8rem_9rem_minmax(12rem,1fr)_auto]">
                                <SelectInput
                                    :id="`usage_item_${index}_batch`"
                                    v-model="item.medicine_batch_id"
                                    label="Batch"
                                    :options="options.batches"
                                    required
                                    :error="itemError(index, 'medicine_batch_id')"
                                />
                                <NumberInput
                                    :id="`usage_item_${index}_quantity`"
                                    v-model="item.quantity"
                                    label="Qty"
                                    required
                                    min="0.001"
                                    step="0.001"
                                    :error="itemError(index, 'quantity')"
                                />
                                <div>
                                    <div class="block text-sm font-semibold text-slate-700">Estimasi</div>
                                    <div class="mt-1 flex min-h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-900">
                                        {{ formatCurrency(itemEstimatedCost(item)) }}
                                    </div>
                                </div>
                                <FormInput :id="`usage_item_${index}_notes`" v-model="item.notes" label="Catatan Item" :error="itemError(index, 'notes')" />
                                <div class="flex items-end justify-end">
                                    <IconButton label="Hapus item" variant="danger" :disabled="form.items.length === 1" @click="removeItem(index)">
                                        <X class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                            <div v-if="selectedBatch(item)" class="mt-2 text-xs text-slate-500">
                                {{ selectedBatch(item).medicine_code }} - {{ selectedBatch(item).medicine }} / stok {{ formatQuantity(selectedBatch(item).current_stock) }} / status {{ selectedBatch(item).status }}
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

            <DataTable :columns="columns" :rows="stockUsages.data" empty-title="Belum ada stock usage">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_13rem_12rem_11rem_11rem_auto]" @submit.prevent="applyFilters">
                        <FormInput id="search" v-model="filterForm.search" label="Pencarian" placeholder="Kode, obat, batch, alasan" />
                        <SelectInput id="usage_type_filter" v-model="filterForm.usage_type" label="Tipe" :options="options.usage_types" placeholder="Semua tipe" />
                        <SelectInput id="status_filter" v-model="filterForm.status" label="Status" :options="options.statuses" placeholder="Semua status" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                        <div class="flex items-end">
                            <UiButton type="submit" class="w-full">
                                <Search class="h-4 w-4" />
                                Filter
                            </UiButton>
                        </div>
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'code'">
                        <div class="font-semibold text-slate-950">{{ row.code }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.items_count }} item - {{ row.created_by ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'usage_type'">{{ usageTypeLabel(value) }}</template>
                    <template v-else-if="column.key === 'usage_date'">{{ formatDate(value) }}</template>
                    <template v-else-if="column.key === 'status'">
                        <StatusBadge :status="value" :label="statusLabel(value)" />
                    </template>
                    <template v-else-if="column.key === 'estimated_total_cost'">
                        <div class="font-semibold text-slate-950">{{ formatCurrency(value) }}</div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Detail stock usage" @click="openDetail(row)">
                                <Eye class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Ubah draft" :disabled="!row.can_edit" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Complete usage" variant="primary" :disabled="!row.can_complete" @click="confirmComplete(row)">
                                <CheckCircle2 class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Cancel completed" :disabled="!row.can_cancel" @click="confirmCancel(row)">
                                <Undo2 class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus draft" variant="danger" :disabled="!row.can_delete" @click="confirmDelete(row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="stockUsages" />
                </template>
            </DataTable>
        </div>

        <DetailModal :show="showDetailModal" :title="detailTarget?.code ?? 'Detail Stock Usage'" max-width="4xl" @close="showDetailModal = false">
            <div v-if="detailTarget" class="space-y-5">
                <div class="grid gap-3 text-sm md:grid-cols-4">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tipe</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ usageTypeLabel(detailTarget.usage_type) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tanggal</div>
                        <div class="mt-1 text-slate-700">{{ formatDate(detailTarget.usage_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
                        <div class="mt-1"><StatusBadge :status="detailTarget.status" :label="statusLabel(detailTarget.status)" /></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Estimasi Biaya</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatCurrency(detailTarget.estimated_total_cost) }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Obat</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Cost</th>
                                <th class="px-4 py-3 text-right">Estimasi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in detailTarget.items" :key="item.id">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">{{ item.medicine }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ item.medicine_code }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ item.batch_number }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.quantity) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(item.cost_snapshot) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-950">{{ formatCurrency(item.estimated_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-600 whitespace-pre-line">
                    {{ detailTarget.reason }}
                </div>
            </div>
        </DetailModal>

        <DeleteConfirmationModal
            :show="showDeleteModal"
            :item-name="deleteTarget?.code"
            :processing="deleteProcessing"
            @close="showDeleteModal = false"
            @confirm="destroy"
        />

        <Modal :show="showCompleteModal" max-width="md" :closeable="!completeProcessing" @close="showCompleteModal = false">
            <div class="px-6 py-5">
                <h2 class="text-lg font-semibold text-slate-950">Complete stock usage?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Stok batch pada <span class="font-semibold text-slate-950">{{ completeTarget?.code }}</span> akan dikurangi dan stock movement dibuat.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <UiButton variant="secondary" :disabled="completeProcessing" @click="showCompleteModal = false">Batal</UiButton>
                    <UiButton :loading="completeProcessing" @click="complete">Complete</UiButton>
                </div>
            </div>
        </Modal>

        <Modal :show="showCancelModal" max-width="md" :closeable="!cancelForm.processing" @close="showCancelModal = false">
            <form class="px-6 py-5" @submit.prevent="cancelCompleted">
                <h2 class="text-lg font-semibold text-slate-950">Cancel completed usage?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Stok dari <span class="font-semibold text-slate-950">{{ cancelTarget?.code }}</span> akan dikembalikan lewat movement cancel_usage.
                </p>
                <TextareaInput id="cancel_reason" v-model="cancelForm.cancel_reason" class="mt-4" label="Alasan Pembatalan" required :error="cancelForm.errors.cancel_reason" />
                <div class="mt-6 flex justify-end gap-2">
                    <UiButton variant="secondary" :disabled="cancelForm.processing" @click="showCancelModal = false">Batal</UiButton>
                    <UiButton type="submit" :loading="cancelForm.processing">Cancel Usage</UiButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
