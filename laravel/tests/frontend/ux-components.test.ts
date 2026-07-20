import { page } from '@inertiajs/svelte';
import { fireEvent, render, screen } from '@testing-library/svelte';
import { describe, expect, it, vi } from 'vitest';
import GlobalFlash from '@/components/GlobalFlash.svelte';
import Pagination from '@/components/Pagination.svelte';
import Maintenance from '@/pages/admin/Maintenance.svelte';
import Settings from '@/pages/admin/Settings.svelte';
import Dashboard from '@/pages/Dashboard.svelte';
import Errors from '@/pages/diagnostics/Errors.svelte';
import Entities from '@/pages/entities/Index.svelte';
import OcrShow from '@/pages/ocr/Show.svelte';
import PipelineIndex from '@/pages/pipeline-runs/Index.svelte';
import ReviewIndex from '@/pages/review/Index.svelte';
import McpTokens from '@/pages/settings/McpTokens.svelte';
import WebhookIndex from '@/pages/webhooks/Index.svelte';

const operations = {
    summary: { total: 0, queued: 0, running: 0, retrying: 0, blocked: 0 },
    items: [],
    operations_log_url: '/operations',
};
const submitted = () => {
    const calls: unknown[] = [];
    window.addEventListener('inertia-test-submit', (event) =>
        calls.push((event as CustomEvent).detail),
    );

    return calls;
};

describe('executable mutation controls', () => {
    it('renders Maintenance destructive effect, confirms, disables, and suppresses a duplicate submit', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const calls = submitted();
        render(Maintenance, {
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

        const button = screen.getByRole('button', {
            name: 'Mark embedding index stale',
        }) as HTMLButtonElement;
        await fireEvent.submit(button.closest('form')!);
        await fireEvent.click(button);
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(confirm).toHaveBeenCalledWith(
            'Mark the embedding index stale? Document processing will stop until a fresh embedding build completes.',
        );
        expect(button.disabled).toBe(true);
        expect(calls).toHaveLength(1);
        expect(calls[0]).toEqual({ action: '/stale', method: 'post' });
    });

    it('invokes exact confirmations for every entity destructive control', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        const base = {
            type: 'tag',
            paperless_id: null,
            source_review_suggestion_id: null,
            sync_status: null,
            created_at: null,
        };
        render(Entities, {
            segment: 'tags',
            type: 'tag',
            title: 'Tags',
            isAdmin: true,
            pending: [{ ...base, id: 1, name: 'Invoices', status: 'pending' }],
            approved: [],
            rejected: [{ ...base, id: 2, name: 'Spam', status: 'rejected' }],
        });
        await fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
        await fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        await fireEvent.click(
            screen.getByRole('button', { name: 'Unblacklist' }),
        );
        expect(confirm.mock.calls.map(([message]) => message)).toEqual([
            'Approve “Invoices” for the whitelist and queue its Paperless application?',
            'Reject and block “Invoices”? It will not be applied to Paperless.',
            'Remove “Spam” from the blocklist? Future suggestions may propose it again.',
        ]);
    });

    it('cancels review bulk, OCR, MCP, pipeline, and webhook requests when confirmation is false', async () => {
        const calls = submitted();
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
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

        const review = render(ReviewIndex, {
            suggestions: paginator([
                {
                    id: 3,
                    paperless_document_id: 9,
                    status: 'pending',
                    confidence: 0.8,
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
            filters: { status: 'pending', per_page: 25, sort: 'created_desc' },
            actions: {
                index: '/review',
                bulkAccept: '/accept',
                bulkReject: '/reject',
            },
        });
        await fireEvent.click(screen.getByRole('checkbox'));
        expect(
            screen.getByRole('button', { name: 'Bulk accept (1)' }),
        ).toBeTruthy();
        await fireEvent.click(
            screen.getByRole('button', { name: 'Bulk accept (1)' }),
        );
        await fireEvent.click(
            screen.getByRole('button', { name: 'Bulk reject (1)' }),
        );
        review.unmount();

        const ocr = render(OcrShow, {
            review: {
                id: 4,
                paperless_document_id: 9,
                status: 'pending',
                original_content: 'old',
                ocr_content: 'new',
                approved_content: null,
            },
            actions: { approve: '/approve', reject: '/reject' },
        });
        await fireEvent.click(
            screen.getByRole('button', { name: 'Reject OCR result' }),
        );
        ocr.unmount();

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
        await fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
        mcp.unmount();

        const run = {
            id: 6,
            type: 'document',
            status: 'running',
            trigger_source: 'manual',
            paperless_document_id: 9,
            progress_total: 1,
            progress_done: 0,
            progress_failed: 0,
            progress_skipped: 0,
            progress_current_phase: null,
            progress_message: null,
            reprocess_requested: false,
            created_at: null,
            updated_at: null,
            show_url: '/p/6',
            retry_url: '/retry',
            retry_failed_items_url: '/items',
            cancel_url: '/cancel',
            can_retry: false,
            can_retry_failed_items: false,
            can_cancel: true,
            command: null,
            webhook_delivery: null,
        };
        const pipeline = render(PipelineIndex, {
            runs: paginator([run]),
            isAdmin: true,
        });
        await fireEvent.click(
            screen.getByRole('button', { name: 'Cancel run' }),
        );
        pipeline.unmount();

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
            retry_url: '/retry',
            dismiss_url: '/dismiss',
            can_retry: false,
            can_dismiss: true,
        };
        render(WebhookIndex, {
            deliveries: paginator([delivery]),
            isAdmin: true,
        });
        await fireEvent.click(
            screen.getByRole('button', { name: 'Dismiss webhook failure' }),
        );

        expect(confirm.mock.calls.map(([message]) => message)).toEqual([
            'Accept 1 selected suggestions and queue their Paperless updates?',
            'Reject 1 selected suggestions without changing Paperless documents?',
            'Reject this OCR correction? The local corrected snapshot will remain rejected and Paperless content will not change.',
            'Revoke MCP token “Desktop”? Existing clients using it will immediately lose access.',
            'Cancel pipeline run 6? Remaining queued work will not start.',
            'Dismiss webhook failure 7? It will no longer appear as an active failure.',
        ]);
        expect(calls).toEqual([]);
    });

    it('renders Dashboard controls and confirms every destructive operation with its exact effect', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        render(Dashboard, {
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
            recentWebhookDeliveries: [
                {
                    id: 8,
                    event_type: 'document.updated',
                    status: 'failed',
                    paperless_document_id: 2,
                    error: 'failed',
                    received_at: null,
                    processed_at: null,
                    show_url: '/w/8',
                    retry_url: '/w/8/retry',
                    dismiss_url: '/w/8/dismiss',
                    can_retry: true,
                    can_dismiss: true,
                },
            ],
            recentPipelineRuns: [
                {
                    id: 12,
                    type: 'document',
                    status: 'running',
                    trigger_source: 'manual',
                    paperless_document_id: 2,
                    progress_total: 2,
                    progress_done: 1,
                    progress_failed: 0,
                    progress_skipped: 0,
                    progress_current_phase: null,
                    progress_message: null,
                    reprocess_requested: false,
                    created_at: null,
                    updated_at: null,
                    retry_url: '/p/12/retry',
                    retry_failed_items_url: '/p/12/items',
                    cancel_url: '/p/12/cancel',
                    failed_items_count: 0,
                    can_retry: false,
                    can_retry_failed_items: false,
                    can_cancel: true,
                },
            ],
        });
        await fireEvent.click(
            screen.getByRole('button', { name: 'Mark embedding index stale' }),
        );
        await fireEvent.click(
            screen.getByRole('button', { name: 'Dismiss webhook failure' }),
        );
        await fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(confirm.mock.calls.map(([message]) => message)).toEqual([
            'Mark the embedding index stale? Document processing will stop until a fresh embedding build completes.',
            'Dismiss webhook failure 8? It will no longer appear as an active failure.',
            'Cancel pipeline run 12? Remaining queued work will not start.',
        ]);
    });

    it('renders exact error count/effect and confirms dismissal', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        render(Errors, {
            filters: { source: 'paperless', status: 'failed', per_page: 25 },
            filterOptions: { sources: ['paperless'], statuses: ['failed'] },
            isAdmin: true,
            webhookErrors: {
                data: [
                    {
                        id: 19,
                        source: 'paperless',
                        event_type: 'document.updated',
                        paperless_document_id: 4,
                        status: 'failed',
                        dedupe_key: 'd',
                        request_id: 'r',
                        received_at: null,
                        processed_at: null,
                        error: 'Queue unavailable',
                        payload_summary: [],
                        show_url: '/19',
                        retry_url: '/19/retry',
                        dismiss_url: '/19/dismiss',
                        can_retry: true,
                        can_dismiss: true,
                    },
                ],
                links: [],
                from: 1,
                to: 1,
                total: 1,
                current_page: 1,
                last_page: 1,
                per_page: 25,
            },
        });
        expect(screen.getByText('1 webhook delivery error')).toBeTruthy();
        expect(screen.getByText('Queue unavailable')).toBeTruthy();
        await fireEvent.click(
            screen.getByRole('button', { name: 'Dismiss webhook failure' }),
        );
        expect(confirm).toHaveBeenCalledWith(
            'Dismiss webhook failure 19? It will be removed from the active error list.',
        );
    });
});

describe('status, manual model, and pagination states', () => {
    it('exposes Inertia global success and failure through live regions', () => {
        page.props.flash = {
            success: '3 suggestions accepted.',
            error: 'Queue unavailable.',
        };
        render(GlobalFlash);
        expect(screen.getByRole('status').textContent).toContain(
            '3 suggestions accepted.',
        );
        expect(screen.getByRole('alert').textContent).toContain(
            'Queue unavailable.',
        );
    });

    it('preserves filters while navigating and changing page size', async () => {
        history.replaceState(
            {},
            '',
            '/archibot/errors?status=failed&source=paperless&page=3',
        );
        const navigate = vi.fn();
        render(Pagination, {
            links: [
                {
                    url: '/archibot/errors?status=failed&source=paperless&page=1&per_page=25',
                    label: '1',
                    active: false,
                },
                {
                    url: '/archibot/errors?status=failed&source=paperless&page=2&per_page=25',
                    label: '2',
                    active: true,
                },
                {
                    url: '/archibot/errors?status=failed&source=paperless&page=3&per_page=25',
                    label: '3',
                    active: false,
                },
                { url: null, label: 'Next', active: false },
            ],
            from: 26,
            to: 50,
            total: 63,
            perPage: 25,
            label: 'Error pages',
            navigate,
        });
        expect(
            screen.getByRole('navigation', { name: 'Error pages' }),
        ).toBeTruthy();
        expect(screen.getByText('Showing 26–50 of 63')).toBeTruthy();
        const pageThree = screen.getByRole('link', {
            name: '3',
        }) as HTMLAnchorElement;
        expect(pageThree.href).toContain(
            'status=failed&source=paperless&page=3&per_page=25',
        );
        const clickedHref = vi.fn();
        pageThree.addEventListener('click', (event) => {
            event.preventDefault();
            clickedHref(pageThree.href);
        });
        await fireEvent.click(pageThree);
        expect(clickedHref).toHaveBeenCalledWith(
            expect.stringContaining(
                '/archibot/errors?status=failed&source=paperless&page=3&per_page=25',
            ),
        );
        await fireEvent.change(screen.getByRole('combobox'), {
            target: { value: '50' },
        });
        expect(navigate.mock.calls[0][0].toString()).toContain(
            '/archibot/errors?status=failed&source=paperless&per_page=50',
        );
    });

    it('distinguishes discovery and configured-model validation states', async () => {
        const responses = [
            {
                ok: true,
                json: async () => ({
                    items: [],
                    provider: {
                        type: 'ollama',
                        base_url: 'http://ollama',
                    },
                    discovery: {
                        message:
                            'Provider returned no useful models; enter a model ID manually.',
                    },
                }),
            },
            {
                ok: false,
                json: async () => ({
                    message: 'Discovery endpoint unavailable.',
                }),
            },
            {
                ok: false,
                json: async () => ({
                    message: 'Model cannot perform OCR vision.',
                }),
            },
            {
                ok: true,
                json: async () => ({
                    message: 'Model validated for OCR vision.',
                }),
            },
        ];
        const fetch = vi.fn(async () => responses.shift() as Response);
        vi.stubGlobal('fetch', fetch);
        render(Settings, {
            groups: [
                {
                    name: 'AI',
                    slug: 'ai-provider',
                    settings: [
                        {
                            key: 'llm_provider',
                            input_name: 'llm_provider',
                            label: 'Provider',
                            type: 'text',
                            options: [],
                            required: false,
                            sensitive: false,
                            read_only: false,
                            has_value: true,
                            value: 'ollama',
                            help: null,
                            min: null,
                            max: null,
                            step: null,
                            entity: null,
                        },
                        {
                            key: 'ocr.vision_model',
                            input_name: 'ocr_vision_model',
                            label: 'OCR vision model',
                            type: 'text',
                            options: [],
                            required: false,
                            sensitive: false,
                            read_only: false,
                            has_value: true,
                            value: 'saved/vision-model',
                            help: null,
                            min: null,
                            max: null,
                            step: null,
                            entity: null,
                        },
                    ],
                },
            ],
            sections: [],
            activeSection: 'ai-provider',
            prompts: [],
            paperlessTagOptions: [],
            aiModelActions: { discover: '/discover', validate: '/validate' },
        });
        const load = screen.getByRole('button', {
            name: 'Test connection and load models',
        });
        await fireEvent.click(load);
        expect((await screen.findByRole('status')).textContent).toContain(
            'Provider returned no useful models; enter a model ID manually.',
        );
        await fireEvent.click(load);
        expect((await screen.findByRole('alert')).textContent).toContain(
            'Connection failure: Discovery endpoint unavailable.',
        );

        const input = screen.getByLabelText(
            'OCR vision model',
        ) as HTMLInputElement;
        expect(input.value).toBe('saved/vision-model');
        await fireEvent.input(input, { target: { value: 'vision/model-v1' } });
        const validate = screen.getByRole('button', {
            name: 'Validate configured models',
        });
        await fireEvent.click(validate);
        expect(
            await screen.findByText(
                'Validation failure: ocr_vision: Model cannot perform OCR vision.',
            ),
        ).toBeTruthy();
        await fireEvent.click(validate);
        expect(
            await screen.findByText(/vision\/model-v1 validated/),
        ).toBeTruthy();
        expect(
            (screen.getByLabelText('OCR vision model') as HTMLInputElement)
                .value,
        ).toBe('vision/model-v1');
        expect(fetch).toHaveBeenCalledTimes(4);
    });
});
