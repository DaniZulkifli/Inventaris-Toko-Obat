<script setup>
import FormInput from './FormInput.vue';

defineProps({
    id: { type: String, required: true },
    label: { type: String, default: '' },
    modelValue: { type: [String, Number], default: '' },
    error: { type: String, default: '' },
    help: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <FormInput
        :id="id"
        type="date"
        :label="label"
        :model-value="modelValue"
        :error="error"
        :help="help"
        :required="required"
        :disabled="disabled"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>
