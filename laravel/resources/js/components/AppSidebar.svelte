<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ClipboardCheck from 'lucide-svelte/icons/clipboard-check';
    import Database from 'lucide-svelte/icons/database';
    import FileText from 'lucide-svelte/icons/file-text';
    import FileType from 'lucide-svelte/icons/file-type';
    import FolderGit2 from 'lucide-svelte/icons/folder-git-2';
    import Inbox from 'lucide-svelte/icons/inbox';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import ScrollText from 'lucide-svelte/icons/scroll-text';
    import Settings from 'lucide-svelte/icons/settings';
    import Sigma from 'lucide-svelte/icons/sigma';
    import Tag from 'lucide-svelte/icons/tag';
    import TriangleAlert from 'lucide-svelte/icons/triangle-alert';
    import UserRound from 'lucide-svelte/icons/user-round';
    import Webhook from 'lucide-svelte/icons/webhook';
    import Workflow from 'lucide-svelte/icons/workflow';
    import Wrench from 'lucide-svelte/icons/wrench';
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
    import { index as maintenanceIndex } from '@/routes/admin/maintenance';
    import { edit as adminSettingsEdit } from '@/routes/admin/settings';
    import { index as embeddingsIndex } from '@/routes/embeddings';
    import { index as errorsIndex } from '@/routes/errors';
    import { index as inboxIndex } from '@/routes/inbox';
    import { index as masterDataCasesIndex } from '@/routes/master-data-cases';
    import { index as mcpTokensIndex } from '@/routes/mcp-tokens';
    import { index as ocrReviewsIndex } from '@/routes/ocr-reviews';
    import { index as operationsLogIndex } from '@/routes/operations-log';
    import { index as pipelineRunsIndex } from '@/routes/pipeline-runs';
    import { index as reviewIndex } from '@/routes/review';
    import { index as statsIndex } from '@/routes/stats';
    import { index as webhookDeliveriesIndex } from '@/routes/webhook-deliveries';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const user = $derived(page.props.auth.user);
    const adminToolsOpen = $derived(
        [
            '/stats',
            '/operations-log',
            '/pipeline-runs',
            '/webhook-deliveries',
            '/embeddings',
            '/errors',
            '/admin/maintenance',
            '/admin/audit-logs',
            '/admin/settings',
        ].some((path) => page.url.includes(path)),
    );

    const reviewNavItems: NavItem[] = $derived([
        {
            title: 'Today',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Review queue',
            href: reviewIndex(),
            icon: ClipboardCheck,
        },
        {
            title: 'Inbox',
            href: inboxIndex(),
            icon: Inbox,
        },
        {
            title: 'OCR review',
            href: ocrReviewsIndex(),
            icon: FileText,
        },
    ]);

    const libraryNavItems: NavItem[] = $derived([
        {
            title: 'Correspondents',
            href: masterDataCasesIndex({ segment: 'correspondents' }),
            icon: UserRound,
        },
        {
            title: 'Document types',
            href: masterDataCasesIndex({ segment: 'doctypes' }),
            icon: FileType,
        },
        {
            title: 'Tags',
            href: masterDataCasesIndex({ segment: 'tags' }),
            icon: Tag,
        },
    ]);

    const monitorNavItems: NavItem[] = $derived([
        ...(user?.is_admin
            ? [
                  {
                      title: 'Stats',
                      href: statsIndex(),
                      icon: Sigma,
                  },
                  {
                      title: 'Operations log',
                      href: operationsLogIndex(),
                      icon: Workflow,
                  },
                  {
                      title: 'Pipeline runs',
                      href: pipelineRunsIndex(),
                      icon: FolderGit2,
                  },
                  {
                      title: 'Webhooks',
                      href: webhookDeliveriesIndex(),
                      icon: Webhook,
                  },
                  {
                      title: 'Embeddings',
                      href: embeddingsIndex(),
                      icon: Database,
                  },
                  {
                      title: 'Errors',
                      href: errorsIndex(),
                      icon: TriangleAlert,
                  },
              ]
            : []),
    ]);

    const configureNavItems: NavItem[] = $derived([
        ...(user?.is_admin
            ? [
                  {
                      title: 'Maintenance',
                      href: maintenanceIndex(),
                      icon: Wrench,
                  },
                  {
                      title: 'Audit logs',
                      href: auditLogsIndex(),
                      icon: ScrollText,
                  },
                  {
                      title: 'Admin settings',
                      href: adminSettingsEdit(),
                      icon: Settings,
                  },
              ]
            : []),
        {
            title: 'MCP tokens',
            href: mcpTokensIndex(),
            icon: KeyRound,
        },
    ]);

    const build = $derived(page.props.build);
    const buildHoverText = $derived(
        build?.commit_short
            ? `Repository · current build ${build.ref ? `${build.ref}@` : ''}${build.commit_short}`
            : 'Repository · build unknown',
    );

    const footerNavItems: NavItem[] = $derived([
        {
            title: 'Repository',
            href: 'https://github.com/pfriedrich84/archibot',
            icon: FolderGit2,
            tooltip: buildHoverText,
        },
    ]);
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
        <NavMain label="Review" items={reviewNavItems} />
        <NavMain label="Library" items={libraryNavItems} />
        {#if user?.is_admin}
            <details
                class="mx-2 mt-1 border-t border-sidebar-border/80 pt-2"
                open={adminToolsOpen}
            >
                <summary
                    class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-md px-2 text-sm font-medium hover:bg-sidebar-accent group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 [&::-webkit-details-marker]:hidden"
                    title="Admin tools"
                >
                    <Wrench class="size-4 shrink-0" />
                    <span class="group-data-[collapsible=icon]:hidden"
                        >Admin tools</span
                    >
                </summary>
                <div class="-mx-2 mt-1">
                    <NavMain label="Monitor" items={monitorNavItems} />
                    <NavMain label="Configure" items={configureNavItems} />
                </div>
            </details>
        {:else}
            <NavMain label="Configure" items={configureNavItems} />
        {/if}
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
