<script lang="ts">
  import { Badge, Button, Card, Spinner } from 'flowbite-svelte';
  import AppShell from '$lib/components/AppShell.svelte';
  import EmptyState from '$lib/components/EmptyState.svelte';
  import { askChat } from '$lib/api';
  import type { PageData } from './$types';

  let { data } = $props<{ data: PageData }>();

  type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    sources?: Array<{ id: number; title: string | null; distance: number }>;
  };

  let sessionId = $state<string | null>(null);
  let question = $state('');
  let messages = $state<ChatMessage[]>([]);
  let loading = $state(false);
  let errorMessage = $state('');

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
    } catch (error) {
      errorMessage = error instanceof Error ? error.message : 'Chat-Anfrage fehlgeschlagen.';
    } finally {
      loading = false;
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
          {#if sessionId}<Badge color="gray">Session {sessionId}</Badge>{/if}
        </div>

        <div class="mt-6 min-h-[24rem] space-y-4 rounded-2xl border border-slate-800 bg-slate-950/45 p-4">
          {#if messages.length === 0}
            <EmptyState icon="💬" title="Noch keine Frage gestellt" description="Frage zum Beispiel nach der letzten Rechnung, einem Vertragspartner oder ähnlichen Dokumenten im Embedding-Index." />
          {:else}
            {#each messages as message}
              <div class={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div class={`max-w-3xl rounded-2xl border px-4 py-3 text-sm leading-6 ${message.role === 'user' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50' : 'border-slate-700 bg-slate-900 text-slate-100'}`}>
                  <p class="whitespace-pre-wrap">{message.content}</p>
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
          {#if loading}
            <div class="flex items-center gap-2 text-sm text-slate-400"><Spinner size="4" /> Antwort wird erzeugt …</div>
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
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Aktivität</p>
        <h2 class="mt-2 text-xl font-semibold text-white">Letzte Chat-/Audit-Signale</h2>
        <div class="mt-5 space-y-3">
          {#each data.chat.recent_activity as item}
            <div class="rounded-2xl border border-slate-800 bg-slate-950/55 p-3 text-sm text-slate-300">
              <p>{item.details || 'Aktivität ohne Details'}</p>
              <p class="mt-1 text-xs text-slate-500">{item.occurred_at}</p>
            </div>
          {:else}
            <p class="rounded-2xl border border-slate-800 bg-slate-950/55 p-3 text-sm text-slate-400">Noch keine Chat-Aktivität erfasst.</p>
          {/each}
        </div>
      </Card>
    </div>
  {/snippet}
</AppShell>
