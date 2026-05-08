<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Plus, RotateCcw, X } from 'lucide-vue-next';

const props = defineProps({
    options: { type: Object, required: true },
});

const blankItem = () => ({
    search: '',
    medicine_id: '',
    medicine_batch_id: '',
    quantity: 1,
});

const form = useForm({
    customer_name: 'Pelanggan Umum',
    payment_method: 'cash',
    discount: 0,
    amount_paid: 0,
    notes: '',
    items: [blankItem()],
});

const medicineMap = computed(() => new Map(props.options.medicines.map((item) => [String(item.id), item])));
const batchMap = computed(() => new Map(props.options.saleable_batches.map((item) => [String(item.id), item])));

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));
const formatQuantity = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 3,
}).format(Number(value ?? 0));
const itemError = (index, key) => form.errors[`items.${index}.${key}`] ?? '';
const selectedMedicine = (item) => medicineMap.value.get(String(item.medicine_id));
const selectedBatch = (item) => batchMap.value.get(String(item.medicine_batch_id));
const medicineOptions = (item) => {
    const query = String(item.search ?? '').toLowerCase();

    return props.options.medicines
        .filter((medicine) => {
            if (!query) {
                return true;
            }

            return [medicine.code, medicine.barcode, medicine.name, medicine.label]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(query));
        })
        .slice(0, 40)
        .map((medicine) => ({
            value: medicine.id,
            label: `${medicine.label} - stok ${formatQuantity(medicine.saleable_stock)}`,
        }));
};
const batchOptions = (item) => props.options.saleable_batches
    .filter((batch) => String(batch.medicine_id) === String(item.medicine_id))
    .map((batch) => ({
        value: batch.id,
        label: batch.label,
    }));
const unitPriceFor = (item) => Number(selectedBatch(item)?.unit_price ?? selectedMedicine(item)?.selling_price ?? 0);
const lineSubtotal = (item) => Number(item.quantity || 0) * unitPriceFor(item);
const subtotal = computed(() => form.items.reduce((total, item) => total + lineSubtotal(item), 0));
const total = computed(() => Math.max(subtotal.value - Number(form.discount || 0), 0));
const amountPaidPreview = computed(() => form.payment_method === 'cash' ? Number(form.amount_paid || 0) : total.value);
const changePreview = computed(() => form.payment_method === 'cash' ? Math.max(amountPaidPreview.value - total.value, 0) : 0);
const selectedItemCount = computed(() => form.items.filter((item) => item.medicine_id).length);

const resetForm = () => {
    form.clearErrors();
    form.customer_name = 'Pelanggan Umum';
    form.payment_method = 'cash';
    form.discount = 0;
    form.amount_paid = 0;
    form.notes = '';
    form.items = [blankItem()];
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

const selectMedicine = (item, medicineId) => {
    item.medicine_id = medicineId;
    item.medicine_batch_id = '';
    const medicine = selectedMedicine(item);

    if (medicine) {
        item.search = [medicine.code, medicine.barcode, medicine.name].filter(Boolean).join(' ');
    }
};

const submit = () => {
    form.post(route('sales.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Transaksi Baru" />

    <AuthenticatedLayout>
        <form class="space-y-6" @submit.prevent="submit">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Link
                    :href="route('sales.index')"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Kembali ke Riwayat
                </Link>

                <UiButton type="submit" :loading="form.processing">
                    <CheckCircle2 class="h-4 w-4" />
                    Simpan Penjualan
                </UiButton>
            </div>

            <div v-if="form.errors.stock" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ form.errors.stock }}
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="space-y-5">
                    <section class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <FormInput id="customer_name" v-model="form.customer_name" class="w-full" label="Pelanggan" :error="form.errors.customer_name" />
                            <UiButton variant="secondary" @click="resetForm">
                                <RotateCcw class="h-4 w-4" />
                                Atur Ulang
                            </UiButton>
                        </div>
                    </section>

                    <section class="space-y-3 rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-slate-950">Keranjang</h3>
                                <p class="mt-1 text-xs text-slate-500">{{ selectedItemCount }} item dipilih dari {{ form.items.length }} baris.</p>
                            </div>
                            <UiButton size="sm" variant="secondary" @click="addItem">
                                <Plus class="h-4 w-4" />
                                Tambah Item
                            </UiButton>
                        </div>
                        <p v-if="form.errors.items" class="text-xs font-medium text-red-600">{{ form.errors.items }}</p>

                        <div class="space-y-3">
                            <div v-for="(item, index) in form.items" :key="index" class="rounded-md border border-slate-200 bg-slate-50/60 p-3">
                                <div class="grid gap-3 xl:grid-cols-[minmax(10rem,0.9fr)_minmax(14rem,1.35fr)_minmax(11rem,1fr)_7rem_8rem_auto]">
                                    <FormInput :id="`sale_item_${index}_search`" v-model="item.search" label="Cari Obat" placeholder="Kode, barcode, nama" />
                                    <SelectInput
                                        :id="`sale_item_${index}_medicine`"
                                        :model-value="item.medicine_id"
                                        label="Obat"
                                        :options="medicineOptions(item)"
                                        required
                                        :error="itemError(index, 'medicine_id')"
                                        @update:model-value="selectMedicine(item, $event)"
                                    />
                                    <SelectInput
                                        :id="`sale_item_${index}_batch`"
                                        v-model="item.medicine_batch_id"
                                        label="Batch Manual"
                                        :options="batchOptions(item)"
                                        placeholder="FEFO otomatis"
                                        :disabled="!item.medicine_id"
                                        :error="itemError(index, 'medicine_batch_id')"
                                    />
                                    <NumberInput
                                        :id="`sale_item_${index}_quantity`"
                                        v-model="item.quantity"
                                        label="Jumlah"
                                        required
                                        min="0.001"
                                        step="0.001"
                                        :error="itemError(index, 'quantity')"
                                    />
                                    <div>
                                        <div class="block text-sm font-semibold text-slate-700">Subtotal</div>
                                        <div class="mt-1 flex min-h-10 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-900">
                                            {{ formatCurrency(lineSubtotal(item)) }}
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
                    </section>
                </div>

                <aside class="space-y-4 xl:sticky xl:top-20 xl:self-start">
                    <section class="rounded-md border border-emerald-100 bg-emerald-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-emerald-950">Ringkasan Bayar</h3>

                        <div class="mt-4 space-y-2 border-b border-emerald-100 pb-4 text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="text-emerald-800">Subtotal</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div class="flex justify-between gap-4">
                                <span class="text-emerald-800">Diskon</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(form.discount) }}</span>
                            </div>
                            <div class="flex justify-between gap-4 text-base">
                                <span class="font-semibold text-emerald-900">Total</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>

                        <div class="mt-4 space-y-4">
                            <SelectInput id="payment_method" v-model="form.payment_method" label="Metode Bayar" :options="options.payment_methods" required :error="form.errors.payment_method" />
                            <CurrencyInput id="discount" v-model="form.discount" label="Diskon" :error="form.errors.discount" />
                            <CurrencyInput v-if="form.payment_method === 'cash'" id="amount_paid" v-model="form.amount_paid" label="Uang Diterima" required :error="form.errors.amount_paid" />
                            <TextareaInput id="notes" v-model="form.notes" label="Catatan" :error="form.errors.notes" />
                        </div>

                        <div class="mt-4 space-y-2 border-t border-emerald-100 pt-4 text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="text-emerald-800">Dibayar</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(amountPaidPreview) }}</span>
                            </div>
                            <div class="flex justify-between gap-4">
                                <span class="text-emerald-800">Kembalian</span>
                                <span class="font-semibold text-emerald-950">{{ formatCurrency(changePreview) }}</span>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
