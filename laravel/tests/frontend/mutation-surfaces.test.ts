import { fireEvent, render, screen } from '@testing-library/svelte';
import type { RenderResult } from '@testing-library/svelte';
import { describe, expect, it, vi } from 'vitest';
import Maintenance from '@/pages/admin/Maintenance.svelte';
import Settings from '@/pages/admin/Settings.svelte';
import Dashboard from '@/pages/Dashboard.svelte';
import Errors from '@/pages/diagnostics/Errors.svelte';
import Entities from '@/pages/entities/Index.svelte';
import OcrShow from '@/pages/ocr/Show.svelte';
import PipelineIndex from '@/pages/pipeline-runs/Index.svelte';
import PipelineShow from '@/pages/pipeline-runs/Show.svelte';
import ReviewIndex from '@/pages/review/Index.svelte';
import ReviewShow from '@/pages/review/Show.svelte';
import McpTokens from '@/pages/settings/McpTokens.svelte';
import Setup from '@/pages/Setup/Index.svelte';
import WebhookIndex from '@/pages/webhooks/Index.svelte';
import WebhookShow from '@/pages/webhooks/Show.svelte';

const operations = {
    summary: { total: 0, queued: 0, running: 0, retrying: 0, blocked: 0 },
    items: [],
    operations_log_url: '/operations',
};

const paginator = (data: unknown[]) => ({
    data,
    links: [],
    from: data.length ? 1 : null,
    to: data.length,
    total: data.length,
    current_page: 1,
    last_page: 1,
    per_page: 25,
});

function installCsrfMeta(): HTMLMetaElement {
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'csrf-test-token';
    document.head.append(meta);

    return meta;
}

function expectCsrfOnPostForms(view: RenderResult, expected: number): void {
    const forms = view.container.querySelectorAll<HTMLFormElement>(
        'form[method="post"]',
    );
    expect(forms).toHaveLength(expected);

    for (const form of forms) {
        expect(
            form.querySelector<HTMLInputElement>('input[name="_token"]')?.value,
        ).toBe('csrf-test-token');
    }
}

function captureSubmissions() {
    const calls: { action: string; method: string }[] = [];
    const listener = (event: Event) =>
        calls.push(
            (event as CustomEvent<{ action: string; method: string }>).detail,
        );
    window.addEventListener('inertia-test-submit', listener);

    return {
        calls,
        stop: () => window.removeEventListener('inertia-test-submit', listener),
    };
}

async function submitEveryFormOnce(view: RenderResult, expected: number) {
    const submissions = captureSubmissions();
    const forms = Array.from(
        view.container.querySelectorAll('form[data-inertia-test="true"]'),
    );
    expect(forms).toHaveLength(expected);

    for (const form of forms) {
        const button = form.querySelector<HTMLButtonElement>(
            'button[type="submit"]',
        );
        expect(button).not.toBeNull();
        await fireEvent.submit(form);
        expect(button!.disabled).toBe(true);
        await fireEvent.click(button!);
    }

    expect(submissions.calls).toHaveLength(expected);
    submissions.stop();

    return submissions.calls;
}

const pipelineRun = {
    id: 6,
    type: 'document',
    status: 'running',
    trigger_source: 'manual',
    paperless_document_id: 9,
    progress_total: 2,
    progress_done: 0,
    progress_failed: 1,
    progress_skipped: 0,
    progress_current_phase: null,
    progress_message: null,
    reprocess_requested: false,
    created_at: null,
    updated_at: null,
    show_url: '/p/6',
    retry_url: '/p/6/retry',
    retry_failed_items_url: '/p/6/items',
    cancel_url: '/p/6/cancel',
    can_retry: true,
    can_retry_failed_items: true,
    can_cancel: true,
    command: null,
    webhook_delivery: null,
};

const delivery = {
    id: 7,
    source: 'paperless',
    event_type: 'document.updated',
    paperless_document_id: 9,
    status: 'failed',
    dedupe_key: null,
    request_id: null,
    received_at: null,
    processed_at: null,
    error: 'failure',
    payload_summary: [],
    show_url: '/w/7',
    retry_url: '/w/7/retry',
    dismiss_url: '/w/7/dismiss',
    can_retry: true,
    can_dismiss: true,
};

describe('all changed mutation surfaces', () => {
    it('suppresses duplicate submissions for setup and every maintenance form', async () => {
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        const setup = render(Setup, {
            requiresResetToken: false,
            paperlessUrl: 'https://paperless.test',
            actions: { store: '/setup', paperlessTags: '/setup/tags' },
        });
        expect(
            (
                screen.getByRole('button', {
                    name: 'AI connection, available after setup completion',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(await submitEveryFormOnce(setup, 1)).toEqual([
            { action: '/setup', method: 'post' },
        ]);
        setup.unmount();

        const maintenance = render(Maintenance, {
            commandCounts: { pending: 1, queued: 2, running: 3, failed: 4 },
            activeOperations: operations,
            actionUrls: {
                commands: '/commands',
                recover_pipeline_actors: '/recover',
                mark_embedding_stale: '/stale',
                document_pipeline: '/pipeline',
                reset: '/reset',
            },
            recentAuditLogs: [],
        });
        expect(screen.getByText(/Type RESET to confirm/)).toBeTruthy();
        await fireEvent.input(screen.getByLabelText('Confirmation'), {
            target: { value: 'RESET' },
        });
        const calls = await submitEveryFormOnce(maintenance, 10);
        expect(
            calls.filter(({ action }) => action === '/commands'),
        ).toHaveLength(6);
        expect(calls.map(({ action }) => action)).toEqual([
            '/recover',
            '/commands',
            '/commands',
            '/commands',
            '/commands',
            '/commands',
            '/commands',
            '/stale',
            '/pipeline',
            '/reset',
        ]);
    });

    it('suppresses duplicates for entity, OCR, MCP, pipeline, webhook, and error mutations', async () => {
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        const entityCsrfMeta = installCsrfMeta();
        const base = {
            type: 'tag',
            paperless_id: null,
            source_review_suggestion_id: null,
            created_at: null,
        };
        const entities = render(Entities, {
            segment: 'tags',
            type: 'tag',
            title: 'Tags',
            isAdmin: true,
            pending: [{ ...base, id: 1, name: 'Invoices', status: 'pending' }],
            approved: [
                {
                    ...base,
                    id: 2,
                    name: 'Receipts',
                    status: 'approved',
                    paperless_id: 20,
                    sync_status: 'failed',
                },
            ],
            rejected: [{ ...base, id: 3, name: 'Spam', status: 'rejected' }],
        });
        expectCsrfOnPostForms(entities, 4);
        await submitEveryFormOnce(entities, 4);
        entities.unmount();
        entityCsrfMeta.remove();

        const ocrCsrfMeta = installCsrfMeta();
        const ocr = render(OcrShow, {
            review: {
                id: 4,
                paperless_document_id: 9,
                status: 'pending',
                original_content: 'old',
                ocr_content: 'new',
                approved_content: null,
            },
            actions: { approve: '/ocr/approve', reject: '/ocr/reject' },
        });
        expectCsrfOnPostForms(ocr, 2);
        expect(await submitEveryFormOnce(ocr, 2)).toEqual([
            { action: '/ocr/approve', method: 'post' },
            { action: '/ocr/reject', method: 'post' },
        ]);
        ocr.unmount();
        ocrCsrfMeta.remove();

        const mcp = render(McpTokens, {
            tokens: [
                {
                    id: 5,
                    name: 'Desktop',
                    last_used_at: null,
                    revoked_at: null,
                    created_at: null,
                },
            ],
            createdToken: null,
        });
        await submitEveryFormOnce(mcp, 2);
        mcp.unmount();

        const pipeline = render(PipelineIndex, {
            runs: paginator([pipelineRun]),
            isAdmin: true,
        });
        expect(await submitEveryFormOnce(pipeline, 3)).toEqual([
            { action: '/p/6/retry', method: 'post' },
            { action: '/p/6/items', method: 'post' },
            { action: '/p/6/cancel', method: 'post' },
        ]);
        pipeline.unmount();

        const pipelineDetail = render(PipelineShow, {
            run: {
                ...pipelineRun,
                scope: null,
                progress_phase_total: 0,
                progress_phase_done: 0,
                progress_updated_at: null,
                retry_count: 0,
                max_retries: 3,
                next_retry_at: null,
                last_retry_at: null,
                retry_reason: null,
                retry_mode: null,
                reprocess_reason: null,
                reprocess_mode: null,
                started_at: null,
                finished_at: null,
                error_type: null,
                error: null,
                events: [],
                items: [],
                audit_logs: [],
            },
            isAdmin: true,
        });
        await submitEveryFormOnce(pipelineDetail, 3);
        pipelineDetail.unmount();

        const webhook = render(WebhookIndex, {
            deliveries: paginator([delivery]),
            isAdmin: true,
        });
        expect(await submitEveryFormOnce(webhook, 2)).toEqual([
            { action: '/w/7/retry', method: 'post' },
            { action: '/w/7/dismiss', method: 'post' },
        ]);
        webhook.unmount();

        const webhookDetail = render(WebhookShow, {
            delivery: {
                ...delivery,
                payload_hash: null,
                pipeline_events: [],
            },
            isAdmin: true,
        });
        await submitEveryFormOnce(webhookDetail, 2);
        webhookDetail.unmount();

        const errors = render(Errors, {
            filters: { source: 'paperless', status: 'failed', per_page: 25 },
            filterOptions: { sources: ['paperless'], statuses: ['failed'] },
            isAdmin: true,
            webhookErrors: paginator([delivery]),
        });
        await submitEveryFormOnce(errors, 2);
    });

    it.each([
        {
            label: 'Bulk accept (1)',
            action: '/review/bulk/accept',
            confirmation:
                'Accept 1 selected suggestions and queue their Paperless updates?',
        },
        {
            label: 'Bulk reject (1)',
            action: '/review/bulk/reject',
            confirmation:
                'Reject 1 selected suggestions without changing Paperless documents?',
        },
    ])(
        'confirms, disables, and suppresses a duplicate $label request',
        async ({ label, action, confirmation }) => {
            const csrfMeta = installCsrfMeta();

            const confirm = vi.fn(() => true);
            vi.stubGlobal('confirm', confirm);
            const submissions = captureSubmissions();
            const review = render(ReviewIndex, {
                suggestions: paginator([
                    {
                        id: 11,
                        paperless_document_id: 44,
                        status: 'pending',
                        confidence: 81,
                        judge_verdict: null,
                        original_title: 'Old',
                        proposed_title: 'New',
                        proposed_correspondent_id: null,
                        proposed_correspondent_name: null,
                        proposed_document_type_id: null,
                        proposed_document_type_name: null,
                        proposed_storage_path_id: null,
                        proposed_storage_path_name: null,
                        created_at: null,
                    },
                ]),
                filters: {
                    status: 'pending',
                    per_page: 25,
                    sort: 'created_desc',
                },
                actions: {
                    index: '/review',
                    bulkAccept: '/review/bulk/accept',
                    bulkReject: '/review/bulk/reject',
                },
            });

            await fireEvent.click(
                screen.getByRole('checkbox', {
                    name: 'Select review suggestion 11',
                }),
            );
            const button = screen.getByRole('button', { name: label });
            const form = button.closest('form');
            expect(form).not.toBeNull();
            expect(
                form?.querySelector<HTMLInputElement>('input[name="_token"]')
                    ?.value,
            ).toBe('csrf-test-token');
            expect(
                Array.from(
                    form?.querySelectorAll<HTMLInputElement>(
                        'input[name="suggestion_ids[]"]',
                    ) ?? [],
                    (input) => input.value,
                ),
            ).toEqual(['11']);
            await fireEvent.submit(form!);

            expect(confirm).toHaveBeenCalledOnce();
            expect(confirm).toHaveBeenCalledWith(confirmation);
            expect(button.disabled).toBe(true);
            expect(submissions.calls).toEqual([{ action, method: 'post' }]);

            await fireEvent.click(button);
            expect(submissions.calls).toEqual([{ action, method: 'post' }]);

            submissions.stop();
            review.unmount();
            csrfMeta.remove();
        },
    );

    it('renders review controls, exact effects, confirmations, and one request per control', async () => {
        const csrfMeta = installCsrfMeta();
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const review = render(ReviewShow, {
            suggestion: {
                id: 11,
                paperless_document_id: 44,
                status: 'pending',
                confidence: 81,
                reasoning: 'Reason',
                commit_status: null,
                preview_url: '/preview',
                judge_verdict: null,
                judge_reasoning: null,
                original: { title: 'Old', tags: [] },
                proposed: { title: 'New', tags: [] },
                context_documents: [],
                save_url: '/review/11/save',
                reprocess_url: '/review/11/reprocess',
            },
            entityOptions: {
                correspondents: [],
                documentTypes: [],
                storagePaths: [],
            },
        });
        expectCsrfOnPostForms(review, 4);
        const calls = await submitEveryFormOnce(review, 4);
        expect(calls[0]).toEqual({
            action: '/review/11/save',
            method: 'post',
        });
        expect(confirm.mock.calls.map(([message]) => message)).toEqual([
            'Accept this suggestion and queue its reviewed metadata update in Paperless?',
            'Reject this suggestion? No Paperless metadata will be changed.',
            'Force a new pipeline run for this one document? This queues fresh processing even when its content is unchanged.',
        ]);
        review.unmount();
        csrfMeta.remove();
    });

    it('covers settings save/prompt reset and every dashboard mutation without duplicate requests', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const settings = render(Settings, {
            groups: [{ name: 'General', slug: 'general', settings: [] }],
            sections: [],
            activeSection: 'prompts',
            prompts: [
                {
                    key: 'classify',
                    label: 'Classification',
                    description: 'Classification system prompt.',
                    content: 'Prompt',
                    has_override: true,
                    update_url: '/prompt',
                    reset_url: '/prompt/reset',
                },
            ],
            paperlessTagOptions: [],
            aiModelActions: { discover: '/discover', validate: '/validate' },
        });
        expect(await submitEveryFormOnce(settings, 3)).toHaveLength(3);
        expect(confirm).toHaveBeenCalledWith(
            'Reset the Classification override? The bundled default will take effect immediately.',
        );
        settings.unmount();

        const dashboard = render(Dashboard, {
            status: {
                setup_complete: true,
                paperless_url_configured: true,
                user_paperless_token_present: true,
                paperless_available: true,
                paperless_error: null,
                inbox_tag_id: 1,
                inbox_tag_label: 'Inbox',
                llm_provider: 'ollama',
                ollama_or_provider_configured: true,
                ocr_mode: 'text',
            },
            counts: { pending_reviews: 0 },
            activeOperations: operations,
            embeddingIndex: {
                id: 1,
                status: 'ready',
                embedding_model: 'embed',
                document_count: 9,
                embedded_count: 9,
                failed_count: 0,
                started_at: null,
                completed_at: null,
                error: null,
                ready: true,
                pending_build_commands: 0,
                build_url: '/build',
                mark_stale_url: '/stale',
            },
            maintenance: {
                poll_url: '/poll',
                reindex_url: '/reindex',
                pending_poll_commands: 0,
                pending_reindex_commands: 0,
                poll_interval_seconds: 600,
                document_processing_active: true,
                reindex_active: false,
            },
            recentErrors: [],
            recentActorExecutions: [],
            recentWebhookDeliveries: [delivery],
            recentPipelineRuns: [
                {
                    ...pipelineRun,
                    failed_items_count: 1,
                },
            ],
        });
        const calls = await submitEveryFormOnce(dashboard, 9);
        expect(calls.map(({ action }) => action)).toEqual([
            '/build',
            '/stale',
            '/poll',
            '/reindex',
            '/w/7/retry',
            '/w/7/dismiss',
            '/p/6/retry',
            '/p/6/items',
            '/p/6/cancel',
        ]);
    });
});
