<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FormInput from '@/Components/UI/FormInput.vue';
import NumberInput from '@/Components/UI/NumberInput.vue';
import TextareaInput from '@/Components/UI/TextareaInput.vue';
import UiButton from '@/Components/UI/UiButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from 'lucide-vue-next';

const props = defineProps({
    settings: { type: Object, required: true },
});

const initialValues = Object.fromEntries(
    Object.entries(props.settings).map(([key, setting]) => [key, setting.value ?? ''])
);

const form = useForm({
    settings: {
        ...initialValues,
    },
});

const previewColor = computed(() => form.settings.theme_primary_color || '#16a34a');

const submit = () => {
    form.patch(route('settings.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <form class="space-y-6" @submit.prevent="submit">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-950">Settings</h2>
                    <p class="mt-1 text-sm text-slate-500">Konfigurasi toko dan batas operasional MVP</p>
                </div>
                <UiButton type="submit" :loading="form.processing">
                    <Save class="h-4 w-4" />
                    Simpan Settings
                </UiButton>
            </div>

            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-semibold text-slate-950">Informasi Toko</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <FormInput id="store_name" v-model="form.settings.store_name" label="Nama Toko" required :error="form.errors['settings.store_name']" />
                    <FormInput id="store_phone" v-model="form.settings.store_phone" label="Telepon" :error="form.errors['settings.store_phone']" />
                    <TextareaInput id="store_address" v-model="form.settings.store_address" label="Alamat" class="lg:col-span-2" :error="form.errors['settings.store_address']" />
                    <FormInput id="timezone" v-model="form.settings.timezone" label="Timezone" required :error="form.errors['settings.timezone']" />
                    <NumberInput id="pagination_per_page" v-model="form.settings.pagination_per_page" label="Pagination per Page" required :error="form.errors['settings.pagination_per_page']" />
                </div>
            </section>

            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-semibold text-slate-950">Operasional Inventaris</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <NumberInput id="default_minimum_stock" v-model="form.settings.default_minimum_stock" label="Default Minimum Stock" required step="0.001" :error="form.errors['settings.default_minimum_stock']" />
                    <NumberInput id="expiry_warning_days" v-model="form.settings.expiry_warning_days" label="Hari Peringatan Kedaluwarsa" required :error="form.errors['settings.expiry_warning_days']" />
                    <NumberInput id="upload_max_file_size_mb" v-model="form.settings.upload_max_file_size_mb" label="Maksimal Upload Gambar (MB)" required :error="form.errors['settings.upload_max_file_size_mb']" />
                    <FormInput id="report_export_formats" v-model="form.settings.report_export_formats" label="Format Export Laporan" required :error="form.errors['settings.report_export_formats']" />
                </div>
            </section>

            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-semibold text-slate-950">Tampilan</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <FormInput id="theme_primary_color" v-model="form.settings.theme_primary_color" label="Warna Primer" required :error="form.errors['settings.theme_primary_color']" />
                    <div class="flex h-10 min-w-32 items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3">
                        <span class="h-5 w-5 rounded-md ring-1 ring-slate-200" :style="{ backgroundColor: previewColor }" />
                        <span class="text-sm font-semibold text-slate-700">{{ previewColor }}</span>
                    </div>
                </div>
            </section>
        </form>
    </AuthenticatedLayout>
</template>
