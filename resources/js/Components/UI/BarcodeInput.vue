<script setup>
import { ScanLine } from 'lucide-vue-next';

defineProps({
    id: { type: String, required: true },
    label: { type: String, default: 'Barcode' },
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
    help: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="block text-sm font-semibold text-slate-700">
            {{ label }} <span v-if="required" class="text-red-600">*</span>
        </label>
        <div class="relative mt-1">
            <ScanLine class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
                :id="id"
                type="text"
                :value="modelValue"
                :required="required"
                :disabled="disabled"
                v-bind="$attrs"
                class="block min-h-10 w-full rounded-md border-slate-300 pl-10 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500"
                :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': error }"
                @input="emit('update:modelValue', $event.target.value)"
            />
        </div>
        <p v-if="help && !error" class="mt-1 text-xs text-slate-500">{{ help }}</p>
        <p v-if="error" class="mt-1 text-xs font-medium text-red-600">{{ error }}</p>
    </div>
</template>
