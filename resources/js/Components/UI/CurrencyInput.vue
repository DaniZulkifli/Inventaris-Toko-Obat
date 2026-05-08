<script setup>
defineProps({
    id: { type: String, required: true },
    label: { type: String, default: '' },
    modelValue: { type: [String, Number], default: '' },
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
        <div class="mt-1 flex rounded-md shadow-sm">
            <span class="inline-flex min-h-10 items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm font-semibold text-slate-600">
                Rp
            </span>
            <input
                :id="id"
                type="number"
                min="0"
                step="0.01"
                inputmode="decimal"
                :value="modelValue"
                :required="required"
                :disabled="disabled"
                v-bind="$attrs"
                class="block min-h-10 w-full rounded-l-none rounded-r-md border-slate-300 text-sm text-slate-900 focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500"
                :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': error }"
                @input="emit('update:modelValue', $event.target.value)"
            />
        </div>
        <p v-if="help && !error" class="mt-1 text-xs text-slate-500">{{ help }}</p>
        <p v-if="error" class="mt-1 text-xs font-medium text-red-600">{{ error }}</p>
    </div>
</template>
