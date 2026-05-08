<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import DeleteConfirmationModal from '@/Components/UI/DeleteConfirmationModal.vue';
import FloatingFormModal from '@/Components/UI/FloatingFormModal.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    references: { type: Object, required: true },
});

const tabs = [
    { key: 'categories', label: 'Kategori', singular: 'Kategori' },
    { key: 'units', label: 'Satuan', singular: 'Satuan' },
    { key: 'dosage_forms', label: 'Bentuk Sediaan', singular: 'Bentuk Sediaan' },
];
const activeTab = ref('categories');
const mode = ref('create');
const selected = ref(null);
const showFormModal = ref(false);
const showDeleteModal = ref(false);
const deleteProcessing = ref(false);

const form = useForm({
    name: '',
    symbol: '',
    description: '',
});

const activeMeta = computed(() => tabs.find((tab) => tab.key === activeTab.value));
const rows = computed(() => props.references[activeTab.value] ?? []);
const columns = computed(() => activeTab.value === 'units'
    ? [
        { key: 'name', label: 'Nama', sortable: true },
        { key: 'symbol', label: 'Simbol' },
        { key: 'medicines_count', label: 'Dipakai Obat' },
        { key: 'actions', label: '', align: 'right' },
    ]
    : [
        { key: 'name', label: 'Nama', sortable: true },
        { key: 'description', label: 'Deskripsi' },
        { key: 'medicines_count', label: 'Dipakai Obat' },
        { key: 'actions', label: '', align: 'right' },
    ]);

const openCreate = () => {
    mode.value = 'create';
    selected.value = null;
    form.reset();
    form.clearErrors();
    showFormModal.value = true;
};

const openEdit = (row) => {
    mode.value = 'edit';
    selected.value = row;
    form.clearErrors();
    form.name = row.name;
    form.symbol = row.symbol ?? '';
    form.description = row.description ?? '';
    showFormModal.value = true;
};

const submit = () => {
    const payload = activeTab.value === 'units'
        ? { name: form.name, symbol: form.symbol }
        : { name: form.name, description: form.description };
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showFormModal.value = false;
            form.reset();
        },
    };

    if (mode.value === 'create') {
        form.transform(() => payload).post(route('references.store', activeTab.value), options);
    } else {
        form.transform(() => payload).patch(route('references.update', [activeTab.value, selected.value.id]), options);
    }
};

const confirmDelete = (row) => {
    selected.value = row;
    showDeleteModal.value = true;
};

const destroy = () => {
    deleteProcessing.value = true;

    router.delete(route('references.destroy', [activeTab.value, selected.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            showDeleteModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="Referensi Obat" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex justify-end">
                <UiButton @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Tambah {{ activeMeta.singular }}
                </UiButton>
            </div>

            <div class="inline-flex rounded-md border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition"
                    :class="activeTab === tab.key ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700'"
                    @click="activeTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <DataTable :columns="columns" :rows="rows" empty-title="Belum ada referensi">
                <template #cell="{ row, column, value }">
                    <template v-if="column.key === 'actions'">
                        <div class="flex justify-end gap-2">
                            <IconButton label="Ubah referensi" @click="openEdit(row)">
                                <Pencil class="h-4 w-4" />
                            </IconButton>
                            <IconButton label="Hapus referensi" variant="danger" :disabled="row.medicines_count > 0" @click="confirmDelete(row)">
                                <Trash2 class="h-4 w-4" />
                            </IconButton>
                        </div>
                    </template>
                    <template v-else>{{ value ?? '-' }}</template>
                </template>
            </DataTable>
        </div>

        <FloatingFormModal
            :show="showFormModal"
            :title="`${mode === 'create' ? 'Tambah' : 'Ubah'} ${activeMeta.singular}`"
            :processing="form.processing"
            @close="showFormModal = false"
            @submit="submit"
        >
            <div class="space-y-4">
                <FormInput id="name" v-model="form.name" label="Nama" required :error="form.errors.name" />
                <FormInput v-if="activeTab === 'units'" id="symbol" v-model="form.symbol" label="Simbol" required :error="form.errors.symbol" />
                <TextareaInput v-else id="description" v-model="form.description" label="Deskripsi" :error="form.errors.description" />
            </div>
        </FloatingFormModal>

        <DeleteConfirmationModal
            :show="showDeleteModal"
            :item-name="selected?.name"
            :processing="deleteProcessing"
            @close="showDeleteModal = false"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
