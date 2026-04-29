<script lang="ts">
  import { Badge, Button, Card, Spinner } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import { askChat, deleteChatSession, loadChat, loadChatSession } from '$lib/api';
  import type { ChatSessionSummary } from '$lib/types';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();
  const initialData = () => data;

  type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    sources?: Array<{ id: number; title: string | null; distance: number }>;
  };

  let sessionId = $state<string | null>(null);
  let question = $state('');
  let messages = $state<ChatMessage[]>([]);
  let sessions = $state<ChatSessionSummary[]>(initialData().chat.sessions);
  let loading = $state(false);
  let loadingSession = $state(false);
  let errorMessage = $state('');

  function escapeHtml(value: string): string {
    return value
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function safeUrl(value: string): string | null {
    try {
      const url = new URL(value, window.location.origin);
      return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? url.href : null;
    } catch {
      return null;
    }
  }

  function renderInlineMarkdown(input: string): string {
    let html = input;
    html = html.replace(/`([^`]+)`/g, '<code class="rounded bg-slate-950/80 px-1 py-0.5 text-emerald-100">$1</code>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-white">$1</strong>');
    html = html.replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_match, label: string, url: string) => {
      const href = safeUrl(url);
      if (!href) return label;
      return `<a href="${escapeHtml(href)}" target="_blank" rel="noreferrer" class="text-emerald-300 underline decoration-emerald-400/40 underline-offset-2 hover:text-emerald-200">${label}</a>`;
    });
    return html;
  }

  function renderMarkdown(markdown: string): string {
    const codeBlocks: string[] = [];
    let text = escapeHtml(markdown).replace(/```(?:\w+)?\n?([\s\S]*?)```/g, (_match, code: string) => {
      codeBlocks.push(`<pre class="my-3 overflow-x-auto rounded-2xl border border-slate-700 bg-slate-950 p-3 text-xs leading-5 text-slate-100"><code>${code.trim()}</code></pre>`);
      return `\u0000CODE${codeBlocks.length - 1}\u0000`;
    });

    const blocks = text.split(/\n{2,}/).map((block) => {
      const trimmed = block.trim();
      if (!trimmed) return '';
      if (/^\u0000CODE\d+\u0000$/.test(trimmed)) return trimmed;

      const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
      if (heading) {
        const level = Math.min(heading[1].length + 2, 6);
        return `<h${level} class="mt-3 text-base font-semibold text-white first:mt-0">${renderInlineMarkdown(heading[2])}</h${level}>`;
      }

      const lines = trimmed.split('\n');
      if (lines.every((line) => /^\s*[-*]\s+/.test(line))) {
        return `<ul class="my-2 list-disc space-y-1 pl-5">${lines.map((line) => `<li>${renderInlineMarkdown(line.replace(/^\s*[-*]\s+/, ''))}</li>`).join('')}</ul>`;
      }
      if (lines.every((line) => /^\s*\d+[.)]\s+/.test(line))) {
        return `<ol class="my-2 list-decimal space-y-1 pl-5">${lines.map((line) => `<li>${renderInlineMarkdown(line.replace(/^\s*\d+[.)]\s+/, ''))}</li>`).join('')}</ol>`;
      }

      return `<p class="my-2 first:mt-0 last:mb-0">${renderInlineMarkdown(lines.join('<br>'))}</p>`;
    });

    let rendered = blocks.join('');
    for (const [index, block] of codeBlocks.entries()) {
      rendered = rendered.replace(`\u0000CODE${index}\u0000`, block);
    }
    return rendered;
  }

  async function refreshSessions() {
    const response = await loadChat(fetch);
    sessions = response.sessions;
  }

  async function sendQuestion() {
    const text = question.trim();
    if (!text || loading) return;

    messages = [...messages, { role: 'user', content: text }];
    question = '';
    loading = true;
    errorMessage = '';

    try {
      const response = await askChat(text, sessionId);
      sessionId = response.session_id;
      messages = [
        ...messages,
        {
          role: 'assistant',
          content: response.answer,
          sources: response.sources
        }
      ];
      await refreshSessions();
    } catch (error) {
      errorMessage = error instanceof Error ? error.message : 'Chat-Anfrage fehlgeschlagen.';
    } finally {
      loading = false;
    }
  }

  async function openSession(id: string) {
    loadingSession = true;
    errorMessage = '';
    try {
      const response = await loadChatSession(id);
      sessionId = response.id;
      messages = response.messages;
      await refreshSessions();
    } catch (error) {
      errorMessage = error instanceof Error ? error.message : 'Chat konnte nicht geladen werden.';
    } finally {
      loadingSession = false;
    }
  }

  function newChat() {
    sessionId = null;
    question = '';
    messages = [];
    errorMessage = '';
  }

  async function removeSession(id: string) {
    if (!confirm('Chat wirklich löschen?')) return;
    try {
      await deleteChatSession(id);
      if (sessionId === id) newChat();
      await refreshSessions();
    } catch (error) {
      errorMessage = error instanceof Error ? error.message : 'Chat konnte nicht gelöscht werden.';
    }
  }
</script>

<AppShell title="Chat" subtitle="Stelle Fragen an den lokalen RAG-Index. Antworten nutzen ähnliche Dokumente als Kontext und zeigen Quellen direkt darunter.">
  {#snippet children()}
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">RAG Chat</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Dokumente befragen</h2>
          </div>
          <div class="flex items-center gap-2">
            {#if sessionId}<Badge color="gray">Session {sessionId}</Badge>{/if}
            <Button color="alternative" class="rounded-xl" onclick={newChat}>Neuer Chat</Button>
          </div>
        </div>

        <div class="mt-6 min-h-[24rem] space-y-4 rounded-2xl border border-slate-800 bg-slate-950/45 p-4">
          {#if messages.length === 0}
            <EmptyState icon="💬" title="Noch keine Frage gestellt" description="Frage zum Beispiel nach der letzten Rechnung, einem Vertragspartner oder ähnlichen Dokumenten im Embedding-Index." />
          {:else}
            {#each messages as message}
              <div class={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div class={`max-w-3xl rounded-2xl border px-4 py-3 text-sm leading-6 ${message.role === 'user' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50' : 'border-slate-700 bg-slate-900 text-slate-100'}`}>
                  {#if message.role === 'assistant'}
                    <div class="prose prose-invert max-w-none text-sm leading-6 prose-p:my-2 prose-strong:text-white">{@html renderMarkdown(message.content)}</div>
                  {:else}
                    <p class="whitespace-pre-wrap">{message.content}</p>
                  {/if}
                  {#if message.sources?.length}
                    <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-700/70 pt-3">
                      {#each message.sources as source}
                        <span class="rounded-full border border-slate-700 bg-slate-950/70 px-2.5 py-1 text-xs text-slate-300">
                          #{source.id} {source.title || 'Unbenannt'} · {source.distance}
                        </span>
                      {/each}
                    </div>
                  {/if}
                </div>
              </div>
            {/each}
          {/if}
          {#if loading || loadingSession}
            <div class="flex items-center gap-2 text-sm text-slate-400"><Spinner size="4" /> {loading ? 'Antwort wird erzeugt …' : 'Chat wird geladen …'}</div>
          {/if}
        </div>

        {#if errorMessage}
          <div class="mt-4 rounded-2xl border border-rose-500/20 bg-rose-500/10 p-4 text-sm text-rose-100">{errorMessage}</div>
        {/if}

        <form class="mt-4 flex flex-col gap-3 sm:flex-row" onsubmit={(event) => { event.preventDefault(); void sendQuestion(); }}>
          <input
            bind:value={question}
            type="text"
            placeholder="Frage an deine Dokumente …"
            class="min-w-0 flex-1 rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-500/40"
          />
          <Button type="submit" color="green" class="rounded-2xl px-6" disabled={loading || !question.trim()}>
            Fragen
          </Button>
        </form>
      </Card>

      <Card size="xl" class="rounded-3xl border border-slate-800/80 bg-slate-900/75 p-6 shadow-lg shadow-slate-950/20">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Chat Sessions</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Letzte Chats</h2>
          </div>
          <Button color="alternative" size="xs" class="rounded-xl" onclick={newChat}>Neu</Button>
        </div>
        <div class="mt-5 space-y-3">
          {#each sessions as item}
            <div class={`rounded-2xl border p-3 text-sm transition ${sessionId === item.id ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/55'}`}>
              <button type="button" class="block w-full text-left" onclick={() => void openSession(item.id)}>
                <div class="flex items-center justify-between gap-3">
                  <p class="font-medium text-slate-100">{item.title}</p>
                  <Badge color={item.origin === 'telegram' ? 'blue' : 'gray'}>{item.origin === 'telegram' ? 'Telegram' : 'Web'}</Badge>
                </div>
                <p class="mt-1 line-clamp-2 text-slate-400">{item.preview || 'Keine Vorschau'}</p>
                <p class="mt-2 text-xs text-slate-500">{item.last_active}</p>
              </button>
              <div class="mt-3 flex justify-end border-t border-slate-800/80 pt-3">
                <Button color="red" size="xs" class="rounded-xl" onclick={() => void removeSession(item.id)}>Löschen</Button>
              </div>
            </div>
          {:else}
            <p class="rounded-2xl border border-slate-800 bg-slate-950/55 p-3 text-sm text-slate-400">Noch keine Chat-Sessions erfasst.</p>
          {/each}
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
