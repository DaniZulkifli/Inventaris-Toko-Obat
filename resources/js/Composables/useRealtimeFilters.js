import { router } from '@inertiajs/vue3';
import { watch } from 'vue';

export function useRealtimeFilters(filters, target, options = {}) {
    const debounce = options.debounce ?? 400;
    const data = options.data ?? (() => ({ ...filters.value }));
    const canVisit = options.canVisit ?? (() => true);
    let timer = null;

    const visit = () => {
        if (!canVisit()) {
            return;
        }

        router.get(
            typeof target === 'function' ? target() : target,
            data(),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(options.visitOptions ?? {}),
            },
        );
    };

    watch(
        filters,
        () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(visit, debounce);
        },
        { deep: true },
    );

    return {
        visit,
    };
}
