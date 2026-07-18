<script lang="ts">
    let {
        success = null,
        error = null,
    }: { success?: string | null; error?: string | null } = $props();
</script>

{#if success}
    <div
        class="mx-4 mt-4 rounded-md border border-green-600/40 bg-green-600/10 p-3 text-sm text-green-800 dark:text-green-300"
        role="status"
        aria-live="polite"
        tabindex="-1"
    >
        {success}
    </div>
{/if}
{#if error}
    <div
        class="mx-4 mt-4 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
        role="alert"
        aria-live="assertive"
        tabindex="-1"
    >
        {error}
    </div>
{/if}
