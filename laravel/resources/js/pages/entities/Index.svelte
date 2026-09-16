<script module lang="ts">
    import { index as entitiesIndex } from '@/routes/entities';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Entity approvals',
                href: entitiesIndex({ segment: 'tags' }),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { csrfToken } from '@/lib/csrf';
    import { paperlessLabel } from '@/lib/paperless';
    import {
        approve as approveEntity,
        reject as rejectEntity,
        unblacklist as unblacklistEntity,
    } from '@/routes/entities';
    import {
        approve as approveMasterDataCase,
        reject as rejectMasterDataCase,
        unblacklist as unblacklistMasterDataCase,
    } from '@/routes/master-data-cases';

    type EntityApproval = {
        id: number;
        type: string;
        name: string;
        status: string;
        paperless_id: number | null;
        source_review_suggestion_id: number | null;
        sync_status: string | null;
        created_at: string | null;
    };

    let {
        segment,
        title,
        isAdmin,
        decisionSource,
        pending,
        approved,
        rejected,
    }: {
        segment: string;
        type: string;
        title: string;
        isAdmin: boolean;
        decisionSource: 'entity_approvals' | 'master_data_cases';
        pending: EntityApproval[];
        approved: EntityApproval[];
        rejected: EntityApproval[];
    } = $props();

    type ApprovalAction = 'approve' | 'reject' | 'unblacklist';

    const actionUrl = (entity: EntityApproval, action: ApprovalAction) => {
        if (decisionSource === 'master_data_cases') {
            const routes = {
                approve: approveMasterDataCase,
                reject: rejectMasterDataCase,
                unblacklist: unblacklistMasterDataCase,
            };

            return routes[action]({
                segment,
                paperlessMasterDataCase: entity.id,
            }).url;
        }

        const routes = {
            approve: approveEntity,
            reject: rejectEntity,
            unblacklist: unblacklistEntity,
        };

        return routes[action]({ segment, entityApproval: entity.id }).url;
    };
</script>

<AppHead {title} />

<div class="space-y-6">
    <Heading
        {title}
        description="ArchiBot approval state for AI-proposed Paperless entities. Approvals use the current admin's Paperless token and the durable Laravel/PostgreSQL application path."
    />

    {#if !isAdmin}
        <div
            class="rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground"
        >
            Approval actions are restricted to Paperless administrators. You can
            still inspect the current entity approval state.
        </div>
    {/if}

    <div class="grid gap-4 md:grid-cols-3">
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Pending</div>
            <div class="text-3xl font-semibold">{pending.length}</div>
        </section>
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Approved</div>
            <div class="text-3xl font-semibold">{approved.length}</div>
        </section>
        <section class="rounded-xl border p-4">
            <div class="text-sm text-muted-foreground">Blocked</div>
            <div class="text-3xl font-semibold">{rejected.length}</div>
        </section>
    </div>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-medium">Pending approval</div>
        {#each pending as entity (entity.id)}
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-4 text-sm last:border-b-0"
            >
                <div>
                    <div class="font-medium">{entity.name}</div>
                    <div class="text-xs text-muted-foreground">
                        {#if entity.source_review_suggestion_id}
                            From review suggestion {entity.source_review_suggestion_id}
                        {:else}
                            Imported proposal
                        {/if}
                        {#if entity.sync_status}
                            · Application: {entity.sync_status}
                        {/if}
                    </div>
                </div>
                {#if isAdmin}
                    <div class="flex gap-2">
                        <Form
                            method="post"
                            action={actionUrl(entity, 'approve')}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        `Approve “${entity.name}” for the whitelist and queue its Paperless application?`,
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken()}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}>Approve</Button
                                >
                            {/snippet}
                        </Form>
                        <Form
                            method="post"
                            action={actionUrl(entity, 'reject')}
                            onsubmit={(event) => {
                                if (
                                    !confirm(
                                        `Reject and block “${entity.name}”? It will not be applied to Paperless.`,
                                    )
                                ) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken()}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}>Reject</Button
                                >
                            {/snippet}
                        </Form>
                    </div>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No pending entities.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-medium">Approved</div>
        {#each approved as entity (entity.id)}
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-4 text-sm last:border-b-0"
            >
                <div>
                    <span class="font-medium">{entity.name}</span>
                    <span class="text-muted-foreground">
                        · Paperless entity {paperlessLabel(
                            entity.paperless_id,
                            entity.name,
                        )} · Application
                        {entity.sync_status ?? '—'}</span
                    >
                </div>
                {#if isAdmin && entity.sync_status === 'failed'}
                    <Form method="post" action={actionUrl(entity, 'approve')}>
                        {#snippet children({ processing })}
                            <input
                                type="hidden"
                                name="_token"
                                value={csrfToken()}
                            />
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}>Retry application</Button
                            >
                        {/snippet}
                    </Form>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No approved entities yet.
            </div>
        {/each}
    </section>

    <section class="rounded-xl border">
        <div class="border-b px-4 py-3 font-medium">Blocked / rejected</div>
        {#each rejected as entity (entity.id)}
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-4 text-sm last:border-b-0"
            >
                <span class="font-medium">{entity.name}</span>
                {#if isAdmin}
                    <Form
                        method="post"
                        action={actionUrl(entity, 'unblacklist')}
                        onsubmit={(event) => {
                            if (
                                !confirm(
                                    `Remove “${entity.name}” from the blocklist? Future suggestions may propose it again.`,
                                )
                            ) {
                                event.preventDefault();
                            }
                        }}
                    >
                        {#snippet children({ processing })}
                            <input
                                type="hidden"
                                name="_token"
                                value={csrfToken()}
                            />
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}>Unblacklist</Button
                            >
                        {/snippet}
                    </Form>
                {/if}
            </div>
        {:else}
            <div class="p-8 text-center text-muted-foreground">
                No blocked entities.
            </div>
        {/each}
    </section>
</div>
