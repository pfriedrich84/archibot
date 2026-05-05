<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Entity approvals',
                href: '/tags',
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';

    type EntityApproval = {
        id: number;
        type: string;
        name: string;
        status: string;
        paperless_id: number | null;
        source_review_suggestion_id: number | null;
        sync_status: string | null;
        sync_worker_job_id: number | null;
        created_at: string | null;
    };

    let {
        segment,
        title,
        isAdmin,
        pending,
        approved,
        rejected,
    }: {
        segment: string;
        type: string;
        title: string;
        isAdmin: boolean;
        pending: EntityApproval[];
        approved: EntityApproval[];
        rejected: EntityApproval[];
    } = $props();

    const actionUrl = (entity: EntityApproval, action: string) =>
        `/${segment}/entity-approvals/${entity.id}/${action}`;
</script>

<AppHead {title} />

<div class="space-y-6">
    <Heading
        {title}
        description="Laravel-owned approval state for AI-proposed Paperless entities. Approvals create the Paperless entity with the current admin's token; retroactive application remains a Python worker follow-up."
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
                            From review #{entity.source_review_suggestion_id}
                        {:else}
                            Imported proposal
                        {/if}
                        {#if entity.sync_status}
                            · Python sync: {entity.sync_status}
                            {#if entity.sync_worker_job_id}
                                via worker #{entity.sync_worker_job_id}
                            {/if}
                        {/if}
                    </div>
                </div>
                {#if isAdmin}
                    <div class="flex gap-2">
                        <Form
                            method="post"
                            action={actionUrl(entity, 'approve')}
                        >
                            {#snippet children({ processing })}
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
                        >
                            {#snippet children({ processing })}
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
            <div class="border-b p-4 text-sm last:border-b-0">
                <span class="font-medium">{entity.name}</span>
                <span class="text-muted-foreground">
                    · Paperless #{entity.paperless_id ?? '—'} · Python sync {entity.sync_status ??
                        '—'}</span
                >
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
                    >
                        {#snippet children({ processing })}
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
