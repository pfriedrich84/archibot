<script lang="ts">
    let {
        title,
        description = '',
        variant = 'default',
    }: {
        title: string;
        description?: string;
        variant?: 'default' | 'small';
    } = $props();
</script>

<header class={variant === 'small' ? '' : 'register-heading'}>
    {#if variant === 'small'}
        <h2 class="mb-1 text-base font-semibold tracking-tight">{title}</h2>
    {:else}
        <h1
            class="text-balance text-2xl font-semibold tracking-[-0.025em] sm:text-3xl"
        >
            {title}
        </h1>
    {/if}
    {#if description}
        <p class="max-w-3xl text-sm leading-6 text-muted-foreground">
            {description}
        </p>
    {/if}
</header>
