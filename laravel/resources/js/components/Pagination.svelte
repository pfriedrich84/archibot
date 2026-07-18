<script lang="ts">
    type PaginationLink = {
        url: string | null;
        label: string;
        active: boolean;
    };

    let {
        links,
        from = null,
        to = null,
        total = null,
        label = 'Pagination',
        perPage = 25,
        navigate = (url: URL) => window.location.assign(url),
    }: {
        links: PaginationLink[];
        from?: number | null;
        to?: number | null;
        total?: number | null;
        label?: string;
        perPage?: number;
        navigate?: (url: URL) => void;
    } = $props();

    const changePageSize = (size: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', size);
        url.searchParams.delete('page');
        url.searchParams.delete('webhook_page');
        navigate(url);
    };

    const text = (value: string) =>
        value.replace('&laquo;', '‹').replace('&raquo;', '›');
</script>

<nav
    class="flex flex-wrap items-center justify-between gap-3 border-t p-4 text-sm"
    aria-label={label}
>
    <div class="flex items-center gap-3 text-muted-foreground">
        {#if total !== null}
            <p>Showing {from ?? 0}–{to ?? 0} of {total}</p>
        {/if}
        <label class="flex items-center gap-2">
            <span>Page size</span>
            <select
                class="rounded-md border bg-background px-2 py-1"
                value={String(perPage)}
                onchange={(event) => changePageSize(event.currentTarget.value)}
            >
                {#each [10, 25, 50, 100] as size (size)}
                    <option value={size}>{size}</option>
                {/each}
            </select>
        </label>
    </div>
    {#if links.length > 3}<div class="flex flex-wrap gap-2">
            {#each links as link, index (`${link.label}-${link.url ?? index}`)}
                {#if link.url}
                    <a
                        class="rounded-md border px-3 py-1 hover:bg-muted"
                        class:font-semibold={link.active}
                        aria-current={link.active ? 'page' : undefined}
                        href={link.url}>{text(link.label)}</a
                    >
                {:else}
                    <span
                        class="rounded-md border px-3 py-1 opacity-50"
                        aria-disabled="true">{text(link.label)}</span
                    >
                {/if}
            {/each}
        </div>{/if}
</nav>
