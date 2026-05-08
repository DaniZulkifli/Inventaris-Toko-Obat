<script setup>
const props = defineProps({
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    error: {
        type: String,
        default: '',
    },
    help: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="block text-sm font-semibold text-slate-700">
            {{ label }} <span v-if="required" class="text-red-600">*</span>
        </label>
        <input
            :id="id"
            :type="type"
            :value="modelValue"
            :required="required"
            :disabled="disabled"
            v-bind="$attrs"
            class="mt-1 block min-h-10 w-full rounded-md border-slate-300 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500"
            :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': error }"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <p v-if="help && !error" class="mt-1 text-xs text-slate-500">{{ help }}</p>
        <p v-if="error" class="mt-1 text-xs font-medium text-red-600">{{ error }}</p>
    </div>
</template>
