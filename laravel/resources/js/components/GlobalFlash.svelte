<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import FlashMessages from '@/components/FlashMessages.svelte';

    const flash = $derived(
        page.props.flash as
            { success?: string | null; error?: string | null } | undefined,
    );
</script>

<FlashMessages success={flash?.success} error={flash?.error} />
