<script lang="ts">
    import type { Snippet } from 'svelte';

    let {
        children,
        onsubmit,
        action = '',
        method = 'post',
        ...rest
    }: {
        children: Snippet<
            [
                {
                    processing: boolean;
                    errors: Record<string, string>;
                    recentlySuccessful: boolean;
                },
            ]
        >;
        onsubmit?: (event: SubmitEvent) => void;
        action?: string;
        method?: string;
        [key: string]: unknown;
    } = $props();

    let processing = $state(false);

    function submit(event: SubmitEvent) {
        onsubmit?.(event);

        if (event.defaultPrevented || processing) {
            return;
        }

        processing = true;
        window.dispatchEvent(
            new CustomEvent('inertia-test-submit', {
                detail: { action, method },
            }),
        );
    }
</script>

<form {action} {method} data-inertia-test="true" onsubmit={submit} {...rest}>
    {@render children({ processing, errors: {}, recentlySuccessful: false })}
</form>
