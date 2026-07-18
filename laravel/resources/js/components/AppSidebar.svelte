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
    import type { NavItem } from '@/types';
    import { dashboard } from '@/routes';
    import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
    import { index as maintenanceIndex } from '@/routes/admin/maintenance';
    import { edit as adminSettingsEdit } from '@/routes/admin/settings';
    import { index as embeddingsIndex } from '@/routes/embeddings';
    import { index as entitiesIndex } from '@/routes/entities';
    import { index as errorsIndex } from '@/routes/errors';
    import { index as inboxIndex } from '@/routes/inbox';
    import { index as mcpTokensIndex } from '@/routes/mcp-tokens';
    import { index as ocrReviewsIndex } from '@/routes/ocr-reviews';
    import { index as operationsLogIndex } from '@/routes/operations-log';
    import { index as pipelineRunsIndex } from '@/routes/pipeline-runs';
    import { index as reviewIndex } from '@/routes/review';
    import { index as statsIndex } from '@/routes/stats';
    import { index as webhookDeliveriesIndex } from '@/routes/webhook-deliveries';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const user = $derived(page.props.auth.user);

    const platformNavItems: NavItem[] = $derived([
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Inbox',
            href: inboxIndex(),
            icon: Inbox,
        },
        {
            title: 'Review',
            href: reviewIndex(),
            icon: ClipboardCheck,
        },
        {
            title: 'OCR reviews',
            href: ocrReviewsIndex(),
            icon: FileText,
        },
    ]);

    const masterDataNavItems: NavItem[] = $derived([
        {
            title: 'Correspondents',
            href: entitiesIndex({ segment: 'correspondents' }),
            icon: UserRound,
        },
        {
            title: 'Document types',
            href: entitiesIndex({ segment: 'doctypes' }),
            icon: FileType,
        },
        {
            title: 'Tags',
            href: entitiesIndex({ segment: 'tags' }),
            icon: Tag,
        },
    ]);

    const processingNavItems: NavItem[] = $derived([
        ...(user?.is_admin
            ? [
                  {
                      title: 'Stats',
                      href: statsIndex(),
                      icon: Sigma,
                  },
                  {
                      title: 'Operations Log',
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
                  {
                      title: 'Maintenance',
                      href: maintenanceIndex(),
                      icon: Wrench,
                  },
                  {
                      title: 'Logs',
                      href: auditLogsIndex(),
                      icon: ScrollText,
                  },
              ]
            : []),
    ]);

    const settingsNavItems: NavItem[] = $derived([
        ...(user?.is_admin
            ? [
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
        <NavMain label="Platform" items={platformNavItems} />
        <NavMain label="Attributes" items={masterDataNavItems} />
        <NavMain label="Processing" items={processingNavItems} />
        <NavMain label="Settings" items={settingsNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
