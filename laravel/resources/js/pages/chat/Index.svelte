<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Chat/RAG',
                href: '/chat',
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';

    type ChatSource = { id: number; title: string | null; distance: number };
    type ChatMessage = {
        role: 'user' | 'assistant';
        content: string;
        sources?: ChatSource[];
    };
    type ChatSessionSummary = {
        id: string;
        title: string;
        preview: string;
        origin: 'web' | 'telegram';
        last_active: string | null;
        message_count: number;
    };
    type ChatPayload = {
        sessions: ChatSessionSummary[];
        recent_activity: { details: string | null; occurred_at: string }[];
    };

    let {
        initialPayload,
        endpoints,
    }: {
        initialPayload: ChatPayload;
        endpoints: { index: string; ask: string; session: string };
    } = $props();

    let refreshedSessions = $state<ChatSessionSummary[] | null>(null);
    let sessions = $derived<ChatSessionSummary[]>(
        refreshedSessions ?? initialPayload.sessions ?? [],
    );
    let messages = $state<ChatMessage[]>([]);
    let sessionId = $state<string | null>(null);
    let question = $state('');
    let loading = $state(false);
    let errorMessage = $state('');

    type MarkdownBlock =
        | { type: 'paragraph'; text: string }
        | { type: 'heading'; level: number; text: string }
        | { type: 'list'; items: string[] }
        | { type: 'code'; text: string };

    const csrfToken = () =>
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    const sessionUrl = (id: string) =>
        endpoints.session.replace('__SESSION__', encodeURIComponent(id));

    async function refreshSessions() {
        const response = await fetch(`${endpoints.index}?limit=8`, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const payload = (await response.json()) as ChatPayload;
            refreshedSessions = payload.sessions ?? [];
        }
    }

    async function openSession(id: string) {
        errorMessage = '';
        const response = await fetch(sessionUrl(id), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            errorMessage = 'Chat session could not be loaded.';

            return;
        }

        const payload = await response.json();
        sessionId = payload.id;
        messages = payload.messages ?? [];
    }

    function newChat() {
        sessionId = null;
        messages = [];
        question = '';
        errorMessage = '';
    }

    async function deleteSession(id: string) {
        if (!window.confirm('Delete this chat session?')) {
            return;
        }

        const response = await fetch(sessionUrl(id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (!response.ok) {
            errorMessage = 'Chat session could not be deleted.';

            return;
        }

        if (sessionId === id) {
            newChat();
        }

        await refreshSessions();
    }

    function markdownBlocks(content: string): MarkdownBlock[] {
        const blocks: MarkdownBlock[] = [];
        const lines = content.split(/\r?\n/);
        let paragraph: string[] = [];
        let list: string[] = [];
        let code: string[] | null = null;

        const flushParagraph = () => {
            if (paragraph.length > 0) {
                blocks.push({ type: 'paragraph', text: paragraph.join(' ') });
                paragraph = [];
            }
        };
        const flushList = () => {
            if (list.length > 0) {
                blocks.push({ type: 'list', items: list });
                list = [];
            }
        };

        for (const line of lines) {
            if (line.trim().startsWith('```')) {
                if (code === null) {
                    flushParagraph();
                    flushList();
                    code = [];
                } else {
                    blocks.push({ type: 'code', text: code.join('\n') });
                    code = null;
                }

                continue;
            }

            if (code !== null) {
                code.push(line);
                continue;
            }

            const heading = /^(#{1,3})\s+(.+)$/.exec(line.trim());

            if (heading) {
                flushParagraph();
                flushList();
                blocks.push({
                    type: 'heading',
                    level: heading[1].length,
                    text: heading[2],
                });
                continue;
            }

            const bullet = /^[-*]\s+(.+)$/.exec(line.trim());

            if (bullet) {
                flushParagraph();
                list.push(bullet[1]);
                continue;
            }

            if (line.trim() === '') {
                flushParagraph();
                flushList();
                continue;
            }

            flushList();
            paragraph.push(line.trim());
        }

        if (code !== null) {
            blocks.push({ type: 'code', text: code.join('\n') });
        }

        flushParagraph();
        flushList();

        return blocks.length > 0
            ? blocks
            : [{ type: 'paragraph', text: content }];
    }

    async function ask() {
        const trimmed = question.trim();

        if (!trimmed || loading) {
            return;
        }

        loading = true;
        errorMessage = '';
        question = '';
        messages = [...messages, { role: 'user', content: trimmed }];

        try {
            const response = await fetch(endpoints.ask, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    question: trimmed,
                    session_id: sessionId,
                }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(
                    payload.message ?? 'Question could not be answered.',
                );
            }

            sessionId = payload.session_id;
            messages = [
                ...messages,
                {
                    role: 'assistant',
                    content: payload.answer ?? '',
                    sources: payload.sources ?? [],
                },
            ];
            await refreshSessions();
        } catch (error) {
            errorMessage =
                error instanceof Error
                    ? error.message
                    : 'Question could not be answered.';
        } finally {
            loading = false;
        }
    }
</script>

<AppHead title="Chat/RAG" />

<div class="space-y-6">
    <Heading
        title="Chat/RAG"
        description="Ask questions across indexed Paperless documents. Sessions are stored in Laravel and answers are generated by the Python RAG helper."
    />

    <div class="grid gap-4 lg:grid-cols-[18rem_1fr]">
        <aside class="space-y-3 rounded-xl border p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Recent sessions</h2>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onclick={newChat}>New</Button
                >
            </div>
            <div class="space-y-2">
                {#each sessions as session (session.id)}
                    <div
                        class="rounded-md border p-3 text-sm"
                        class:bg-muted={session.id === sessionId}
                    >
                        <button
                            type="button"
                            class="block w-full text-left"
                            onclick={() => openSession(session.id)}
                        >
                            <span class="font-medium">{session.title}</span>
                            <span
                                class="mt-1 block line-clamp-2 text-muted-foreground"
                            >
                                {session.preview || 'No preview'}
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                {session.origin} · {session.message_count} messages
                            </span>
                        </button>
                        <button
                            type="button"
                            class="mt-2 text-xs text-destructive underline"
                            onclick={() => deleteSession(session.id)}
                        >
                            Delete
                        </button>
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">
                        No completed chat sessions yet.
                    </p>
                {/each}
            </div>
        </aside>

        <section class="flex min-h-[36rem] flex-col rounded-xl border">
            <div class="flex-1 space-y-4 overflow-auto p-4">
                {#each messages as message, index (index)}
                    <article
                        class="rounded-xl border p-4"
                        class:bg-muted={message.role === 'assistant'}
                    >
                        <div
                            class="mb-2 text-xs font-medium uppercase text-muted-foreground"
                        >
                            {message.role === 'user' ? 'You' : 'ArchiBot'}
                        </div>
                        <div class="space-y-2 text-sm leading-6">
                            {#each markdownBlocks(message.content) as block, blockIndex (blockIndex)}
                                {#if block.type === 'heading'}
                                    <div class="font-semibold">
                                        {block.text}
                                    </div>
                                {:else if block.type === 'list'}
                                    <ul class="list-disc space-y-1 pl-5">
                                        {#each block.items as item, itemIndex (itemIndex)}
                                            <li>{item}</li>
                                        {/each}
                                    </ul>
                                {:else if block.type === 'code'}
                                    <pre
                                        class="overflow-auto rounded-md bg-background p-3 text-xs"><code
                                            >{block.text}</code
                                        ></pre>
                                {:else}
                                    <p class="whitespace-pre-wrap">
                                        {block.text}
                                    </p>
                                {/if}
                            {/each}
                        </div>
                        {#if message.sources && message.sources.length > 0}
                            <div class="mt-3 flex flex-wrap gap-2">
                                {#each message.sources as source (`${source.id}-${source.distance}`)}
                                    <span
                                        class="rounded-full bg-background px-2 py-1 text-xs text-muted-foreground"
                                    >
                                        {source.title ??
                                            `Document reference ${source.id}`} · distance
                                        {source.distance}
                                    </span>
                                {/each}
                            </div>
                        {/if}
                    </article>
                {:else}
                    <div
                        class="flex h-full items-center justify-center text-center text-muted-foreground"
                    >
                        Start a new question to search your indexed documents.
                    </div>
                {/each}
                {#if loading}
                    <div
                        class="rounded-xl border bg-muted p-4 text-sm text-muted-foreground"
                    >
                        ArchiBot is searching documents and generating an
                        answer…
                    </div>
                {/if}
            </div>

            <form
                class="border-t p-4"
                onsubmit={(event) => {
                    event.preventDefault();
                    ask();
                }}
            >
                {#if errorMessage}
                    <div
                        class="mb-3 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {errorMessage}
                    </div>
                {/if}
                <div class="grid gap-3">
                    <textarea
                        bind:value={question}
                        rows="3"
                        placeholder="Ask about invoices, correspondents, document types, deadlines…"
                        disabled={loading}
                        class="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                    ></textarea>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            disabled={loading || question.trim() === ''}
                        >
                            {loading ? 'Asking…' : 'Ask'}
                        </Button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>
