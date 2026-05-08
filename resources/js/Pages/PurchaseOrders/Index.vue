<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DateInput from '@/Components/UI/DateInput.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
import DetailModal from '@/Components/UI/DetailModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import Modal from '@/Components/Modal.vue';
import { useRealtimeFilters } from '@/Composables/useRealtimeFilters';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Eye, Pencil, Plus, RotateCcw, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    purchaseOrders: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const blankItem = () => ({
    medicine_id: '',
    batch_number: '',
    expiry_date: '',
    quantity: 1,
    unit_cost: 0,
});

const today = () => new Date().toISOString().slice(0, 10);
const mode = ref('create');
const selected = ref(null);
const detailTarget = ref(null);
const deleteTarget = ref(null);
const receiveTarget = ref(null);
const showDetailModal = ref(false);
const showDeleteModal = ref(false);
const showReceiveModal = ref(false);
const receiveProcessing = ref(false);
const deleteProcessing = ref(false);

const filterForm = ref({
    search: props.filters.search ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    status: props.filters.status ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

const form = useForm({
    supplier_id: '',
    order_date: today(),
    discount: 0,
    notes: '',
    items: [blankItem()],
});

const supplierOptions = computed(() => props.options.suppliers.map((item) => ({
    value: item.id,
    label: `${item.name}${item.is_active ? '' : ' (nonaktif)'}`,
})));
const activeSupplierOptions = computed(() => props.options.active_suppliers.map((item) => ({
    value: item.id,
    label: item.name,
})));
const medicineOptions = computed(() => props.options.medicines.map((item) => ({
    value: item.id,
    label: item.label,
})));
const medicineMap = computed(() => new Map(props.options.medicines.map((item) => [String(item.id), item])));
const noExpiryClassifications = computed(() => props.options.no_expiry_classifications ?? []);

const columns = [
    { key: 'code', label: 'Kode PO', sortable: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'order_date', label: 'Tanggal' },
    { key: 'status', label: 'Status' },
    { key: 'total_amount', label: 'Total', align: 'right' },
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
const itemSubtotal = (item) => Number(item.quantity || 0) * Number(item.unit_cost || 0);
const formSubtotal = computed(() => form.items.reduce((total, item) => total + itemSubtotal(item), 0));
const formTotal = computed(() => Math.max(formSubtotal.value - Number(form.discount || 0), 0));
const statusLabel = (status) => props.options.statuses.find((item) => item.value === status)?.label ?? status;

const itemError = (index, key) => form.errors[`items.${index}.${key}`] ?? '';
const requiresExpiry = (item) => {
    const medicine = medicineMap.value.get(String(item.medicine_id));

    if (!medicine) {
        return true;
    }

    return !noExpiryClassifications.value.includes(medicine.classification);
};

const resetForm = () => {
    selected.value = null;
    mode.value = 'create';
    form.clearErrors();
    form.supplier_id = '';
    form.order_date = today();
    form.discount = 0;
    form.notes = '';
    form.items = [blankItem()];
};

const openCreate = () => {
    resetForm();
};

const openEdit = (purchaseOrder) => {
    mode.value = 'edit';
    selected.value = purchaseOrder;
    form.clearErrors();
    form.supplier_id = purchaseOrder.supplier_id;
    form.order_date = purchaseOrder.order_date;
    form.discount = purchaseOrder.discount ?? 0;
    form.notes = purchaseOrder.notes ?? '';
    form.items = purchaseOrder.items.map((item) => ({
        medicine_id: item.medicine_id,
        batch_number: item.batch_number ?? '',
        expiry_date: item.expiry_date ?? '',
        quantity: item.quantity,
        unit_cost: item.unit_cost,
    }));
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const applyMedicineDefault = (item, medicineId) => {
    const medicine = medicineMap.value.get(String(medicineId));

    if (medicine && (item.unit_cost === '' || Number(item.unit_cost) === 0)) {
        item.unit_cost = medicine.default_purchase_price ?? 0;
    }
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
        ? form.post(route('purchase-orders.store'), options)
        : form.patch(route('purchase-orders.update', selected.value.id), options);
};

useRealtimeFilters(filterForm, () => route('purchase-orders.index'));

const openDetail = (purchaseOrder) => {
    detailTarget.value = purchaseOrder;
    showDetailModal.value = true;
};

const confirmDelete = (purchaseOrder) => {
    deleteTarget.value = purchaseOrder;
    showDeleteModal.value = true;
};

const destroy = () => {
    deleteProcessing.value = true;
    router.delete(route('purchase-orders.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};

const confirmReceive = (purchaseOrder) => {
    receiveTarget.value = purchaseOrder;
    showReceiveModal.value = true;
};

const receive = () => {
    receiveProcessing.value = true;
    router.post(route('purchase-orders.receive', receiveTarget.value.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            receiveProcessing.value = false;
            showReceiveModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="Pesanan Pembelian" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <UiButton variant="secondary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Pesanan Baru
                </UiButton>
            </div>

            <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">
                            {{ mode === 'create' ? 'Form Pesanan Pembelian' : `Ubah ${selected?.code}` }}
                        </h3>
                        <p class="mt-1 text-sm text-slate-500">Total dihitung ulang di backend saat disimpan</p>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-right text-sm">
                        <div>
                            <div class="text-xs text-slate-500">Subtotal</div>
                            <div class="font-semibold text-slate-950">{{ formatCurrency(formSubtotal) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Diskon</div>
                            <div class="font-semibold text-slate-950">{{ formatCurrency(form.discount) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Total</div>
                            <div class="font-semibold text-emerald-700">{{ formatCurrency(formTotal) }}</div>
                        </div>
                    </div>
                </div>

                <form class="space-y-5 p-4" @submit.prevent="submit">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_12rem_12rem]">
                        <SelectInput id="supplier_id" v-model="form.supplier_id" label="Supplier Aktif" :options="activeSupplierOptions" required :error="form.errors.supplier_id" />
                        <DateInput id="order_date" v-model="form.order_date" label="Tanggal Pesanan" required :error="form.errors.order_date" />
                        <CurrencyInput id="discount" v-model="form.discount" label="Diskon" :error="form.errors.discount" />
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-slate-800">Item Pembelian</h4>
                            <UiButton size="sm" variant="secondary" @click="addItem">
                                <Plus class="h-4 w-4" />
                                Tambah Item
                            </UiButton>
                        </div>
                        <p v-if="form.errors.items" class="text-xs font-medium text-red-600">{{ form.errors.items }}</p>

                        <div v-for="(item, index) in form.items" :key="index" class="rounded-md border border-slate-200 p-3">
                            <div class="grid gap-3 xl:grid-cols-[minmax(14rem,1.6fr)_minmax(10rem,1fr)_10rem_8rem_10rem_9rem_auto]">
                                <SelectInput
                                    :id="`items_${index}_medicine`"
                                    v-model="item.medicine_id"
                                    label="Obat"
                                    :options="medicineOptions"
                                    required
                                    :error="itemError(index, 'medicine_id')"
                                    @update:model-value="applyMedicineDefault(item, $event)"
                                />
                                <FormInput
                                    :id="`items_${index}_batch`"
                                    v-model="item.batch_number"
                                    label="Nomor Batch"
                                    help="Kosongkan untuk nomor AUTO"
                                    :error="itemError(index, 'batch_number')"
                                />
                                <DateInput
                                    :id="`items_${index}_expiry`"
                                    v-model="item.expiry_date"
                                    label="Tanggal Kedaluwarsa"
                                    :required="requiresExpiry(item)"
                                    :error="itemError(index, 'expiry_date')"
                                />
                                <NumberInput
                                    :id="`items_${index}_quantity`"
                                    v-model="item.quantity"
                                    label="Jumlah"
                                    required
                                    min="0.001"
                                    step="0.001"
                                    :error="itemError(index, 'quantity')"
                                />
                                <CurrencyInput
                                    :id="`items_${index}_unit_cost`"
                                    v-model="item.unit_cost"
                                    label="Harga Satuan"
                                    required
                                    :error="itemError(index, 'unit_cost')"
                                />
                                <div>
                                    <div class="block text-sm font-semibold text-slate-700">Subtotal</div>
                                    <div class="mt-1 flex min-h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-900">
                                        {{ formatCurrency(itemSubtotal(item)) }}
                                    </div>
                                </div>
                                <div class="flex items-end justify-end">
                                    <IconButton label="Hapus item" variant="danger" :disabled="form.items.length === 1" @click="removeItem(index)">
                                        <X class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <TextareaInput id="notes" v-model="form.notes" label="Catatan" :error="form.errors.notes" />

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

            <DataTable :columns="columns" :rows="purchaseOrders.data" empty-title="Belum ada pesanan pembelian">
                <template #filters>
                    <form class="grid gap-3 xl:grid-cols-[1fr_14rem_11rem_11rem_11rem]" @submit.prevent>
                        <FormInput id="search" v-model="filterForm.search" label="Kode PO" placeholder="Kode PO atau supplier" />
                        <SelectInput id="filter_supplier" v-model="filterForm.supplier_id" label="Supplier" :options="supplierOptions" placeholder="Semua supplier" />
                        <SelectInput id="filter_status" v-model="filterForm.status" label="Status" :options="options.statuses" placeholder="Semua status" />
                        <DateInput id="date_from" v-model="filterForm.date_from" label="Dari" />
                        <DateInput id="date_to" v-model="filterForm.date_to" label="Sampai" />
                    </form>
                </template>

                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'code'">
                        <div class="font-semibold text-slate-950">{{ row.code }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.items_count }} item - dibuat oleh {{ row.created_by ?? '-' }}</div>
                    </template>
                    <template v-else-if="column.key === 'supplier'">
                        <div class="font-medium text-slate-900">{{ row.supplier }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ row.supplier_is_active ? 'Aktif' : 'Nonaktif' }}</div>
                    </template>
                    <template v-else-if="column.key === 'order_date'">
                        <div>{{ formatDate(row.order_date) }}</div>
                        <div v-if="row.received_date" class="mt-1 text-xs text-slate-500">Terima {{ formatDate(row.received_date) }}</div>
                    </template>
                    <template v-else-if="column.key === 'status'">
                        <StatusBadge :status="value" :label="statusLabel(value)" />
                    </template>
                    <template v-else-if="column.key === 'total_amount'">
                        <div class="font-semibold text-slate-950">{{ formatCurrency(value) }}</div>
                        <div v-if="Number(row.discount) > 0" class="mt-1 text-xs text-slate-500">Diskon {{ formatCurrency(row.discount) }}</div>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Detail pesanan pembelian" @click="openDetail(row)">
                                <Eye class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Ubah draft" :disabled="!row.can_edit" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Terima stok" variant="primary" :disabled="!row.can_receive" @click="confirmReceive(row)">
                                <CheckCircle2 class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus draft" variant="danger" :disabled="!row.can_delete" @click="confirmDelete(row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value }}</template>
                </template>

                <template #pagination>
                    <Pagination :meta="purchaseOrders" />
                </template>
            </DataTable>
        </div>

        <DetailModal :show="showDetailModal" :title="detailTarget?.code ?? 'Detail Pesanan Pembelian'" max-width="4xl" @close="showDetailModal = false">
            <div v-if="detailTarget" class="space-y-5">
                <div class="grid gap-3 text-sm md:grid-cols-3">
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Supplier</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ detailTarget.supplier }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Status</div>
                        <div class="mt-1"><StatusBadge :status="detailTarget.status" :label="statusLabel(detailTarget.status)" /></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Total</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ formatCurrency(detailTarget.total_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tanggal Pesanan</div>
                        <div class="mt-1 text-slate-700">{{ formatDate(detailTarget.order_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Tanggal Terima</div>
                        <div class="mt-1 text-slate-700">{{ formatDate(detailTarget.received_date) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Penerima</div>
                        <div class="mt-1 text-slate-700">{{ detailTarget.received_by ?? '-' }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Obat</th>
                                <th class="px-4 py-3">Batch</th>
                                <th class="px-4 py-3">Kedaluwarsa</th>
                                <th class="px-4 py-3 text-right">Jumlah</th>
                                <th class="px-4 py-3 text-right">Harga Satuan</th>
                                <th class="px-4 py-3 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in detailTarget.items" :key="item.id">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">{{ item.medicine }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ item.medicine_code }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ item.batch_number }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ formatDate(item.expiry_date) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatQuantity(item.quantity) }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(item.unit_cost) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-950">{{ formatCurrency(item.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="detailTarget.notes" class="rounded-md bg-slate-50 p-3 text-sm text-slate-600">
                    {{ detailTarget.notes }}
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

        <Modal :show="showReceiveModal" max-width="md" :closeable="!receiveProcessing" @close="showReceiveModal = false">
            <div class="px-6 py-5">
                <div class="flex gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-emerald-50 text-emerald-700">
                        <CheckCircle2 class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Terima pesanan pembelian?</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Stok untuk <span class="font-semibold text-slate-950">{{ receiveTarget?.code }}</span> akan bertambah dan mutasi stok dibuat.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <UiButton variant="secondary" :disabled="receiveProcessing" @click="showReceiveModal = false">Batal</UiButton>
                    <UiButton :loading="receiveProcessing" @click="receive">Terima Stok</UiButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
