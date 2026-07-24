<?php

namespace Tests\Unit;

use App\Services\Webhooks\PaperlessWebhookNormalizer;
use Tests\TestCase;

class PaperlessWebhookNormalizerTest extends TestCase
{
    public function test_extracts_document_id_from_supported_payload_shapes(): void
    {
        $normalizer = new PaperlessWebhookNormalizer;

        $this->assertSame(10, $normalizer->documentId(['document_id' => '10']));
        $this->assertSame(11, $normalizer->documentId(['document' => ['id' => 11]]));
        $this->assertSame(14, $normalizer->documentId(['document' => '14']));
        $this->assertSame(12, $normalizer->documentId(['object' => ['id' => 12]]));
        $this->assertSame(15, $normalizer->documentId(['object' => '15']));
        $this->assertSame(13, $normalizer->documentId(['id' => 13]));
        $this->assertSame(16, $normalizer->documentId(['doc_url' => 'https://paperless.example.test/documents/16/']));
        $this->assertSame(17, $normalizer->documentId(['document_url' => 'https://paperless.example.test/paperless/documents/17/']));
        $this->assertNull($normalizer->documentId(['document_id' => 'not-an-int']));
    }

    public function test_maps_paperless_event_types_to_durable_webhook_actions(): void
    {
        $normalizer = new PaperlessWebhookNormalizer;

        $this->assertSame('process_document', $normalizer->webhookAction('document.created'));
        $this->assertSame('process_document', $normalizer->webhookAction('document_imported'));
        $this->assertSame('process_document', $normalizer->webhookAction('document-consumed'));
        $this->assertSame('refresh_embedding', $normalizer->webhookAction('document.updated'));
        $this->assertSame('refresh_embedding', $normalizer->webhookAction('document changed'));
        $this->assertSame('refresh_embedding', $normalizer->webhookAction('document-edited'));
        $this->assertSame('delete_embedding', $normalizer->webhookAction('document.deleted'));
        $this->assertSame('delete_embedding', $normalizer->webhookAction('document trashed'));
        $this->assertSame('process_document', $normalizer->webhookAction('unknown'));
    }

    public function test_normalize_returns_small_stable_interface(): void
    {
        $normalizer = new PaperlessWebhookNormalizer;

        $this->assertSame([
            'event_type' => 'document.updated',
            'webhook_action' => 'refresh_embedding',
            'paperless_document_id' => 42,
            'paperless_modified' => '2026-06-02T12:00:00Z',
            'paperless_version_id' => 99,
            'paperless_version_checksum' => 'abc123',
        ], $normalizer->normalize([
            'event' => 'Document.Updated',
            'document' => [
                'id' => 42,
                'modified' => '2026-06-02T12:00:00Z',
                'version_added' => ['id' => 99, 'checksum' => 'abc123'],
            ],
        ]));
    }
}
