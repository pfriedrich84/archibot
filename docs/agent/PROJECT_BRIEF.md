# Project Brief — ArchiBot

ArchiBot helps classify newly scanned Paperless-NGX documents.

The product goal is:
- fetch documents tagged `Posteingang`
- use local Ollama models for classification
- suggest title, date, correspondent, document type, storage path and tags
- use embedding similarity to learn from already reviewed documents
- show all uncertain results in a review queue
- commit metadata back to Paperless only after approval or high-confidence auto-commit

ArchiBot should stay:
- self-hosted
- privacy-friendly
- Docker-first
- usable on small local GPUs
- robust enough for real household/business document workflows
