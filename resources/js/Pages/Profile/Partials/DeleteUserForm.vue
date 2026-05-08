<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    if (form.processing) {
        return;
    }

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    if (form.processing) {
        return;
    }

    confirmingUserDeletion.value = false;

    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-base font-semibold text-red-700">Hapus Akun</h2>
        </header>

        <DangerButton @click="confirmUserDeletion">Hapus Akun</DangerButton>

        <Modal :show="confirmingUserDeletion" :closeable="!form.processing" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-950">
                    Hapus akun ini?
                </h2>

                <p class="mt-2 text-sm text-slate-600">
                    Masukkan kata sandi untuk mengonfirmasi penghapusan permanen.
                </p>

                <div class="mt-6">
                    <InputLabel for="password" value="Kata Sandi" class="sr-only" />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="Kata Sandi"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton :disabled="form.processing" @click="closeModal">Batal</SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :loading="form.processing"
                        @click="deleteUser"
                    >
                        Hapus Akun
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
