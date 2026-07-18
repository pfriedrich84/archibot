"""Repository-wide containment boundary and behavioral regression tests."""

from __future__ import annotations

from pathlib import Path

import pytest

from app.jobs.review_commit import ReviewCommitRecord, build_paperless_patch
from scripts.check_containment_boundaries import (
    CENTRAL_PAPERLESS_CLIENTS,
    EXPOSURE_FILES,
    inventory,
    load_baseline,
    scan_python,
    scan_repository,
    scan_text,
)

ROOT = Path(__file__).resolve().parents[1]


def test_exact_exposure_and_paperless_mutation_inventories_are_frozen() -> None:
    baseline = load_baseline()

    assert set(baseline) == {
        "authorization_command_files",
        "cli_registration_files",
        "exposure_files",
        "paperless_mutation_files",
    }
    assert set(baseline["exposure_files"]) >= set(EXPOSURE_FILES)
    assert set(baseline["paperless_mutation_files"]) >= CENTRAL_PAPERLESS_CLIENTS
    assert "app/cli.py" in baseline["cli_registration_files"]
    assert scan_repository(ROOT) == []


def test_new_dynamic_http_file_is_discovered_before_inventory_suppression(tmp_path: Path) -> None:
    # The fixed exposure paths are part of every inventory, so create a minimal
    # synthetic repository before freezing its reviewed baseline.
    for relative in EXPOSURE_FILES:
        target = tmp_path / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("", encoding="utf-8")
    scripts = tmp_path / "scripts"
    scripts.mkdir(parents=True, exist_ok=True)
    baseline_path = scripts / "containment_boundary_inventory.json"
    baseline_path.write_text(
        __import__("json").dumps(inventory(tmp_path), sort_keys=True), encoding="utf-8"
    )

    probe = tmp_path / "app/new_transport.py"
    probe.parent.mkdir(parents=True, exist_ok=True)
    probe.write_text(
        "import httpx\nclient = httpx.Client()\nclient.request(method_for(), '/health')\n",
        encoding="utf-8",
    )

    violations = scan_repository(tmp_path, baseline_path)
    rules = {(item.path, item.rule) for item in violations}
    assert (
        "app/new_transport.py",
        "paperless_mutation_files exact fingerprint changed",
    ) in rules
    assert (
        "app/new_transport.py",
        "direct Paperless HTTP mutation outside central client",
    ) in rules


def test_new_receiver_agnostic_writer_is_discovered_in_synthetic_repo(tmp_path: Path) -> None:
    for relative in EXPOSURE_FILES:
        target = tmp_path / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("", encoding="utf-8")
    scripts = tmp_path / "scripts"
    scripts.mkdir(parents=True, exist_ok=True)
    baseline_path = scripts / "containment_boundary_inventory.json"
    baseline_path.write_text(
        __import__("json").dumps(inventory(tmp_path), sort_keys=True), encoding="utf-8"
    )

    probe = tmp_path / "app/writer.py"
    probe.parent.mkdir(parents=True, exist_ok=True)
    probe.write_text(
        "class Writer:\n"
        "    transport = object()\n"
        "    def write(self):\n"
        "        return self.transport.request(method_for(), endpoint_for())\n",
        encoding="utf-8",
    )

    rules = {(item.path, item.rule) for item in scan_repository(tmp_path, baseline_path)}
    assert (
        "app/writer.py",
        "paperless_mutation_files exact fingerprint changed",
    ) in rules
    assert (
        "app/writer.py",
        "direct Paperless HTTP mutation outside central client",
    ) in rules


@pytest.mark.parametrize(
    ("path", "source", "rule"),
    [
        (
            "laravel/routes/web.php",
            "<?php Route::get('/assistant', AssistantController::class)->name('help.ask');",
            "disabled Chat/RAG surface token",
        ),
        (
            "laravel/routes/web.php",
            "<?php $alias = '/'.request('surface'); Route::get($alias, Handler::class);",
            "dynamic route path",
        ),
        (
            "laravel/resources/js/components/NavProbe.svelte",
            "const items = [{ title: 'Help', href: assistantIndex() }];",
            "disabled Chat/RAG surface token",
        ),
        (
            "app/provider_config_probe.py",
            "assistant_provider = supplied_endpoint",
            "disabled Chat/RAG surface token",
        ),
    ],
)
def test_route_navigation_and_dynamic_alias_probes_fail(path: str, source: str, rule: str) -> None:
    assert rule in {item.rule for item in scan_text(path, source)}


@pytest.mark.parametrize(
    "source",
    [
        "@app.command('assistant')\ndef harmless_alias(): return None\n",
        "alias = app.command\n@alias(dynamic_name)\ndef helper(): return None\n",
        "@app.command('help')\ndef helper(): return search_documents('question')\n",
        "register = cli.command\n@register(name='assistant-tools')\ndef renamed(): return None\n",
        "from click import command as expose\n@expose('rag-query')\ndef lookup(): return None\n",
        "subcommands = parser.add_subparsers()\nsubcommands.add_parser(dynamic_name)\n",
        "first = app.command\nsecond = first\n@second(name='assistant')\ndef renamed(): return None\n",
        "app.command('assistant')(handler)\n",
        "def assistant_handler(): return None\napp.command('help')(assistant_handler)\n",
        "def retrieval_helper(): return []\ncli.add_command(retrieval_helper, name='lookup')\n",
    ],
)
def test_typer_click_and_aliased_cli_registration_probes_fail(source: str) -> None:
    rules = {item.rule for item in scan_python("app/cli_probe.py", source)}
    assert rules & {
        "unreviewed Python CLI registration",
        "dynamic Python CLI registration",
        "disabled Chat/RAG Python CLI registration",
    }


@pytest.mark.parametrize(
    "source",
    [
        "@mcp.tool()\ndef case_files(): return []\n",
        "register = mcp.tool\n@register()\ndef renamed_lookup(): return []\n",
        "name = supplied_name\nmcp.add_tool(handler, name=name)\n",
        "server.register_resource(resource)\n",
    ],
)
def test_any_new_renamed_or_dynamic_mcp_registration_fails(source: str) -> None:
    assert "unreviewed MCP exposure" in {
        item.rule for item in scan_python("app/mcp_probe.py", source)
    }


@pytest.mark.parametrize(
    "source",
    [
        "async def mutate(client):\n    await client.patch_document(7, {'content': 'x'})\n",
        "async def mutate(client):\n    key = 'con' + 'tent'\n    body = {key: 'x'}\n    send = client.patch_document\n    await send(7, body)\n",
        "async def mutate(client, supplied):\n    await client.patch_document(7, payload_for(supplied))\n",
        "async def mutate(client):\n    await client.patch_document(7, {'files': []})\n",
        "async def mutate(client):\n    await client.patch_document(7, {'version_label': 'v2'})\n",
        "async def mutate(client):\n    await client.patch_document(7, {'storage_path_id': 2})\n",
        "async def mutate(client):\n    await client.patch_document(7, {dynamic_key(): 2})\n",
        "async def mutate(client):\n    await client.patch_document(7, {'owner': 2})\n",
    ],
)
def test_python_ast_dataflow_denies_forbidden_alias_and_dynamic_payloads(source: str) -> None:
    rules = {item.rule for item in scan_python("app/adversarial_mutation.py", source)}
    assert rules & {
        "prohibited Paperless document field",
        "dynamic Paperless mutation key",
        "unreviewed or dynamic Paperless mutation payload",
        "field outside reviewed manual mutation seam",
    }


@pytest.mark.parametrize(
    "source",
    [
        "<?php $fields = ['o'.'cr' => $text]; $paperless->patchDocument($token, 7, $fields);",
        "<?php $payload = ['storage_path' => 4]; $paperless->patchDocument($token, 7, $payload);",
        "<?php $payload = ['files' => []]; $paperless->patchDocument($token, 7, $payload);",
        "<?php $payload[$field] = $value; $paperless->patchDocument($token, 7, $payload);",
        "<?php $method = 'patchDocument'; $fields = ['content' => $text]; $paperless->$method($token, 7, $fields);",
        "<?php $fields = ['custom_fields' => []]; $paperless->patchDocument($token, 7, $fields);",
    ],
)
def test_php_token_dataflow_denies_helpers_aliases_and_dynamic_payloads(source: str) -> None:
    rules = {item.rule for item in scan_text("laravel/app/Adversarial.php", source)}
    assert rules & {
        "prohibited Paperless document field",
        "dynamic Paperless mutation key",
        "field outside reviewed manual mutation seam",
    }


@pytest.mark.parametrize(
    ("path", "source"),
    [
        (
            "laravel/resources/js/probe.ts",
            "client.fetch('/api/documents/7/', { method: 'PATCH', body: payload });",
        ),
        (
            "scripts/probe.sh",
            "curl --request PUT https://paperless/api/documents/7/ --data @payload.json",
        ),
    ],
)
def test_other_source_languages_cannot_add_direct_document_mutations(
    path: str, source: str
) -> None:
    assert "direct Paperless document mutation outside reviewed seam" in {
        item.rule for item in scan_text(path, source)
    }


@pytest.mark.parametrize(
    "source",
    [
        "import httpx\nasync def write(doc_id, body):\n    endpoint = f'/api/documents/{doc_id}/'\n    await httpx.AsyncClient().request('PATCH', endpoint, json=body)\n",
        "import requests as transport\ndef write(document_url, body):\n    session = transport.Session()\n    session.put(document_url, json=body)\n",
        "import aiohttp as net\nasync def write(endpoint, body):\n    async with net.ClientSession() as client:\n        await client.request(method='PATCH', url=endpoint, json=body)\n",
        "import httpx\nasync def write(base, document_id, body):\n    target = base + '/api/documents/' + str(document_id) + '/'\n    writer = httpx.AsyncClient().patch\n    await writer(target, json=body)\n",
        "import requests\ndef write(base, document_id, body):\n    target = f'{base}/api/documents/{document_id}/'\n    requests.request('P' + 'UT', target, json=body)\n",
        "import requests\ndef write(document_id, body):\n    target = endpoint_for(document_id)\n    writer = requests.patch\n    writer(target, json=body)\n",
        "import aiohttp\nasync def write(document_id, body):\n    target = endpoint_for(document_id)\n    session = aiohttp.ClientSession()\n    sender = session.request\n    await sender('PATCH', target, json=body)\n",
        "import httpx\nasync def write(document_id, body):\n    client = httpx.AsyncClient()\n    sender = getattr(client, 'request')\n    await sender('PATCH', endpoint_for(document_id), json=body)\n",
        "import requests\ndef write(document_id, body):\n    sender = getattr(requests, 'put')\n    sender(endpoint_for(document_id), json=body)\n",
        # Exact deny-by-default reviewer probes: the endpoint is deliberately
        # unrelated because a computed method is itself unacceptable.
        "import httpx\ndef write():\n    client = httpx.Client()\n    client.request(''.join(['P', 'ATCH']), '/health')\n",
        "import requests as web\ndef write():\n    method = 'P' + 'UT'\n    send = web.request\n    send(method, '/status')\n",
        "import aiohttp as web\ndef make_client():\n    return web.ClientSession()\nasync def write():\n    transport = make_client()\n    await transport.request(method_for(), endpoint_for(7))\n",
        "import httpx as web\ndef make_client():\n    return web.AsyncClient()\nasync def write():\n    first = make_client()\n    second = first\n    sender = second.send\n    await sender(resolve_method(), '/health')\n",
        "import requests\ndef write():\n    requests.request('P' + 'ATCH', '/api/' + 'documents/' + str(7) + '/')\n",
        # Exact receiver-agnostic probes: no import, annotation or recognized
        # binding is needed for the policy to apply.
        "class Writer:\n    def write(self):\n        return self.transport.request(method_for(), '/health')\n",
        "class Writer:\n    transport = object()\n    def write(self):\n        return self.transport.send('GET', endpoint_for())\n",
        "class Writer:\n    def write(self):\n        return self.transport.request('GET', '/he' + 'alth')\n",
        "request = harmless_alias\nclass Writer:\n    def write(self):\n        return self.transport.request(method_for(), '/health')\n",
        "class Writer:\n    def write(self):\n        return self.settings.network.transport.request(method_for(), '/health')\n",
        "def writer_factory():\n    return object()\ndef write():\n    return writer_factory().transport.send('GET', endpoint_for())\n",
        "class Writer:\n    pass\ndef write():\n    return Writer().transport.request(method_for(), '/health')\n",
        "class Writer:\n    def write(self):\n        return self.transport.patch('/api/documents/7/', json={})\n",
        "class Writer:\n    def write(self, document_id):\n        return self.transport.put(document_endpoint(document_id), json={})\n",
    ],
)
def test_httpx_requests_and_aiohttp_document_write_aliases_fail(source: str) -> None:
    assert "direct Paperless HTTP mutation outside central client" in {
        item.rule for item in scan_python("app/http_probe.py", source)
    }


@pytest.mark.parametrize(
    "source",
    [
        "<?php $endpoint = '/api/documents/'.$id.'/'; Http::patch($endpoint, $payload);",
        "<?php $endpoint = '/api/documents/'.$id.'/'; Http::send('PUT', $endpoint, ['json' => $payload]);",
        "<?php $documentUrl = $base.$path; $guzzle->request('PATCH', $documentUrl, ['json' => $payload]);",
        "<?php $verb = 'request'; $documentEndpoint = '/api/'.'documents/'.$id.'/'; $guzzle->$verb('PUT', $documentEndpoint, ['json' => $payload]);",
        "<?php $sender = [$guzzle, 'send']; $documentUrl = '/api/documents/'.$id.'/'; $sender('PATCH', $documentUrl, ['json' => $payload]);",
        "<?php $writer = [$guzzle, 'patch']; $documentEndpoint = endpointFor($id); $writer($documentEndpoint, ['json' => $payload]);",
        "<?php $verb = 'patch'; $documentEndpoint = endpointFor($id); Http::$verb($documentEndpoint, $payload);",
        "<?php $method = 'PATCH'; $documentEndpoint = endpointFor($id); $guzzle->request($method, $documentEndpoint, ['json' => $payload]);",
        "<?php $documentEndpoint = endpointFor($id); call_user_func([$guzzle, 'put'], $documentEndpoint, ['json' => $payload]);",
        "<?php $documentEndpoint = endpointFor($id); call_user_func([$guzzle, 'request'], 'PATCH', $documentEndpoint, ['json' => $payload]);",
        "<?php use Illuminate\\Support\\Facades\\Http as Web; Web::send(strtoupper('patch'), '/health');",
        "<?php use Illuminate\\Support\\Facades\\Http as Web; $method = 'P'.'UT'; Web::request($method, '/status');",
        "<?php use Illuminate\\Support\\Facades\\Http as Web; $pending = Web::withToken('x'); $alias = $pending; $alias->send(methodFor(), '/health');",
        "<?php use GuzzleHttp\\Client as Transport; function transport() { return new Transport(); } $wire = transport(); $alias = $wire; $alias->request(methodFor(), '/health');",
        "<?php use GuzzleHttp\\Client as Transport; $wire = new Transport(); $send = [$wire, 'request']; $send(strtoupper('put'), endpointFor($id));",
        "<?php use GuzzleHttp\\Client as Transport; $wire = new Transport(); $wire->request('P'.'ATCH', '/api/'.'documents/'.$id.'/');",
        "<?php final class Writer { public function write() { return $this->transport->request(methodFor(), '/health'); } }",
        "<?php final class Writer { public function write() { return $this->nested->transport->send('GET', endpointFor()); } }",
        "<?php final class Writer { public function write() { return $this->transport->patch('/api/documents/7/', []); } }",
        "<?php final class Writer { public function write() { return $this->transport->put(documentEndpoint(7), []); } }",
    ],
)
def test_laravel_http_and_guzzle_document_write_aliases_fail(source: str) -> None:
    assert "direct Paperless HTTP mutation outside central client" in {
        item.rule for item in scan_text("laravel/app/HttpProbe.php", source)
    }


@pytest.mark.parametrize(
    "source",
    [
        "async def commit(client, confidence, payload):\n    if confidence > 99: await client.patch_document(1, payload)\n",
        "async def authorize(client, judge_result, payload):\n    await client.patch_document(1, payload)\n",
        "async def queue(create_command, model_score):\n    create_command({'score': model_score})\n",
        "async def authorize(create_command, score):\n    neutral = score\n    create_command({'eligible': neutral})\n",
        "async def write(ReviewSuggestion, neutral_score):\n    ReviewSuggestion.create({'accepted': neutral_score})\n",
        "async def write(PipelineRun, model_verdict):\n    PipelineRun.update_or_create({'state': model_verdict})\n",
        "from app.models import ReviewSuggestion as Item\ndef write(neutral_score):\n    row = Item()\n    row.accepted = neutral_score\n    row.save()\n",
        "from app.models import Command as Work\ndef write(session, neutral_score):\n    session.add(Work(payload={'eligible': neutral_score}))\n",
        "def decide(policy, neutral_score):\n    return policy.can_change_document(42) and neutral_score > 0\n",
    ],
)
def test_model_judge_confidence_cannot_reach_authorization_command_or_mutation(source: str) -> None:
    assert "model-derived authorization reaches command/mutation seam" in {
        item.rule for item in scan_python("app/adversarial_authorization.py", source)
    }


@pytest.mark.parametrize(
    "source",
    [
        "<?php function authorizeWrite($modelScore) { return $modelScore > 90; }",
        "<?php Command::query()->create(['payload' => $payload, 'judgeResult' => $judgeResult]);",
        "<?php $paperless->patchDocument($token, 7, $fields); $llmPrediction = true;",
        "<?php ReviewSuggestion::create(['accepted' => $neutralScore]);",
        "<?php PipelineRun::updateOrCreate(['id' => 1], ['state' => $modelVerdict]);",
        "<?php use App\\Models\\ReviewSuggestion as Item; Item::create(['accepted' => $neutralScore]);",
        "<?php DB::table('commands')->insert(['payload' => ['eligible' => $neutralScore]]);",
        "<?php $allowed = $policy->allows($user, $document) && $neutralScore > 0;",
    ],
)
def test_php_model_terms_cannot_reach_authorization_command_or_mutation(source: str) -> None:
    assert "model-derived authorization reaches command/mutation seam" in {
        item.rule for item in scan_text("laravel/app/Adversarial.php", source)
    }


def _record() -> ReviewCommitRecord:
    return ReviewCommitRecord(
        id=1,
        paperless_document_id=42,
        proposed_title="Reviewed title",
        proposed_date="2026-07-18",
        proposed_correspondent_id=2,
        proposed_document_type_id=3,
        proposed_storage_path_id=4,
        proposed_tags=[{"id": 5}],
    )


@pytest.mark.parametrize("current_storage_path", [None, 7])
def test_reviewed_manual_patch_builder_has_exact_deterministic_field_seam(
    current_storage_path: int | None,
) -> None:
    fields = build_paperless_patch(
        _record(), current_tags=[6], current_storage_path=current_storage_path
    )

    expected = {
        "title": "Reviewed title",
        "created_date": "2026-07-18",
        "correspondent": 2,
        "document_type": 3,
        "tags": [5, 6],
    }
    if current_storage_path is None:
        expected["storage_path"] = 4
    assert fields == expected
    assert {"ocr", "content", "file", "files", "version", "versions"}.isdisjoint(fields)
    if current_storage_path is not None:
        assert "storage_path" not in fields


@pytest.mark.parametrize("configured", [1, 50, 100, 999])
def test_model_confidence_threshold_remains_ineffective(monkeypatch, configured: int) -> None:
    from app.config import Settings

    monkeypatch.setenv("AUTO_COMMIT_CONFIDENCE", str(configured))
    assert Settings().auto_commit_confidence == 0


def test_document_actor_still_stores_review_instead_of_patching_paperless() -> None:
    source = (ROOT / "app/actors/document.py").read_text(encoding="utf-8")

    assert "store_review_suggestion(" in source
    assert ".patch_document(" not in source
