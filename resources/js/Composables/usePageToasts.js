import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const toastTitles = {
    success: 'Berhasil',
    error: 'Gagal',
    warning: 'Perhatian',
    info: 'Info',
    status: 'Info',
};

const normalizeMessage = (message) => {
    if (!message) {
        return '';
    }

    if (Array.isArray(message)) {
        return message.filter(Boolean).join(' ');
    }

    if (typeof message === 'object') {
        return message.text ?? message.message ?? '';
    }

    return String(message);
};

export function usePageToasts() {
    const page = usePage();
    const messages = ref([]);
    let nextId = 1;

    const flash = computed(() => page.props.flash ?? {});
    const errors = computed(() => page.props.errors ?? {});

    const removeToast = (id) => {
        messages.value = messages.value.filter((message) => message.id !== id);
    };

    const addToast = (type, text, title = null) => {
        const normalizedText = normalizeMessage(text);

        if (!normalizedText) {
            return;
        }

        const id = nextId;
        nextId += 1;

        messages.value = [
            ...messages.value,
            {
                id,
                type: type === 'status' ? 'info' : type,
                title: title ?? toastTitles[type] ?? toastTitles.info,
                text: normalizedText,
            },
        ];

        window.setTimeout(() => removeToast(id), 4500);
    };

    watch(
        flash,
        (value) => {
            const primaryTypes = ['success', 'error', 'warning', 'info'];

            primaryTypes.forEach((type) => {
                addToast(type, value?.[type]);
            });

            if (!primaryTypes.some((type) => value?.[type])) {
                addToast('status', value?.status);
            }
        },
        { deep: true, immediate: true },
    );

    watch(
        errors,
        (value) => {
            if (Object.keys(value ?? {}).length > 0) {
                addToast('error', 'Aksi gagal. Periksa kembali input atau aturan data yang dipilih.');
            }
        },
        { deep: true, immediate: true },
    );

    return {
        messages,
        addToast,
        removeToast,
    };
}
