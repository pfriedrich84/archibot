import { render, screen } from '@testing-library/svelte';
import { describe, expect, it } from 'vitest';
import ActiveOperationsPanel from '@/components/ActiveOperationsPanel.svelte';
import Heading from '@/components/Heading.svelte';
import StatusBadge from '@/components/StatusBadge.svelte';

describe('application design system semantics', () => {
    it('uses one page-level heading and preserves section headings', () => {
        const pageHeading = render(Heading, {
            title: 'Review queue',
            description: 'Documents waiting for a decision.',
        });

        expect(
            screen.getByRole('heading', { level: 1, name: 'Review queue' }),
        ).toBeTruthy();
        pageHeading.unmount();

        render(Heading, { title: 'Evidence', variant: 'small' });
        expect(
            screen.getByRole('heading', { level: 2, name: 'Evidence' }),
        ).toBeTruthy();
    });

    it('renders canonical statuses with text and the correct semantic tone', () => {
        const cases = [
            ['committed', 'committed', 'text-emerald-800'],
            ['synced', 'synced', 'text-emerald-800'],
            ['failed_permanent', 'failed permanent', 'text-destructive'],
            ['partially_failed', 'partially failed', 'text-amber-800'],
            ['partial', 'partial', 'text-amber-800'],
            ['cancel_requested', 'cancel requested', 'text-amber-800'],
        ] as const;

        for (const [status, label, tone] of cases) {
            const badge = render(StatusBadge, { status });
            expect(screen.getByText(label)).toBeTruthy();
            expect(badge.container.firstElementChild?.className).toContain(
                tone,
            );
            badge.unmount();
        }
    });

    it('exposes active-operation progress to assistive technology', () => {
        render(ActiveOperationsPanel, {
            operations: {
                summary: {
                    total: 1,
                    queued: 0,
                    running: 1,
                    retrying: 0,
                    blocked: 0,
                },
                operations_log_url: '/operations',
                items: [
                    {
                        key: 'run-1',
                        kind: 'pipeline',
                        id: 1,
                        label: 'Document pipeline',
                        status: 'running',
                        detail: 'Classifying one document',
                        progress_total: 4,
                        progress_done: 2,
                        progress_failed: 0,
                        progress_skipped: 0,
                        progress_message: null,
                        created_at: null,
                        started_at: null,
                        updated_at: null,
                        href: '/pipeline-runs/1',
                    },
                ],
            },
        });

        const progress = screen.getByRole('progressbar', {
            name: 'Document pipeline progress',
        });
        expect(progress.getAttribute('aria-valuenow')).toBe('50');
    });
});
