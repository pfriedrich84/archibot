<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import ClipboardCheck from 'lucide-svelte/icons/clipboard-check';
    import FolderGit2 from 'lucide-svelte/icons/folder-git-2';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import ScrollText from 'lucide-svelte/icons/scroll-text';
    import Settings from 'lucide-svelte/icons/settings';
    import Workflow from 'lucide-svelte/icons/workflow';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';
    import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
    import { edit as adminSettingsEdit } from '@/routes/admin/settings';
    import { index as reviewIndex } from '@/routes/review';
    import { index as workerJobsIndex } from '@/routes/worker-jobs';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const user = $derived(page.props.auth.user);

    const mainNavItems: NavItem[] = $derived([
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Review',
            href: reviewIndex(),
            icon: ClipboardCheck,
        },
        {
            title: 'Worker jobs',
            href: workerJobsIndex(),
            icon: Workflow,
        },
        ...(user?.is_admin
            ? [
                  {
                      title: 'Admin settings',
                      href: adminSettingsEdit(),
                      icon: Settings,
                  },
                  {
                      title: 'Audit logs',
                      href: auditLogsIndex(),
                      icon: ScrollText,
                  },
              ]
            : []),
    ]);

    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: 'https://github.com/laravel/svelte-starter-kit',
            icon: FolderGit2,
        },
        {
            title: 'Documentation',
            href: 'https://laravel.com/docs/starter-kits#svelte',
            icon: BookOpen,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
