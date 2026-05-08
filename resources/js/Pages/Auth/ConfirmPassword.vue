<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Konfirmasi Kata Sandi" />

        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-slate-950">Konfirmasi Kata Sandi</h1>
            <p class="mt-1 text-sm text-slate-500">Masukkan password untuk melanjutkan.</p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="Kata Sandi" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-5 flex justify-end">
                <PrimaryButton :loading="form.processing">
                    Konfirmasi
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
