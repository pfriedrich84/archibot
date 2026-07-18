<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppContent from '@/components/AppContent.svelte';
    import AppHeader from '@/components/AppHeader.svelte';
    import AppShell from '@/components/AppShell.svelte';
    import GlobalFlash from '@/components/GlobalFlash.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import type { BreadcrumbItem } from '@/types';

    let {
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        children?: Snippet;
    } = $props();
</script>

<AppShell variant="header">
    <AppHeader {breadcrumbs} />
    <AppContent variant="header">
        <GlobalFlash />
        {@render children?.()}
    </AppContent>
    <Toaster />
</AppShell>
