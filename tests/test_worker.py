"""Tests for worker entity resolution and tag handling."""

from app.worker import _resolve_entity, _resolve_tags


class TestResolveEntity:
    def test_exact_match(self, sample_entities):
        assert _resolve_entity("Max Mustermann", sample_entities) == 1

    def test_case_insensitive(self, sample_entities):
        assert _resolve_entity("max mustermann", sample_entities) == 1
        assert _resolve_entity("STADTWERKE MÜNCHEN", sample_entities) == 2
        assert _resolve_entity("deutsche post", sample_entities) == 3

    def test_no_match(self, sample_entities):
        assert _resolve_entity("Unbekannter Absender", sample_entities) is None

    def test_none_input(self, sample_entities):
        assert _resolve_entity(None, sample_entities) is None

    def test_empty_string(self, sample_entities):
        assert _resolve_entity("", sample_entities) is None

    def test_empty_entity_list(self):
        assert _resolve_entity("Something", []) is None

    def test_partial_match_not_found(self, sample_entities):
        """Partial matches should NOT resolve — only exact."""
        assert _resolve_entity("Max", sample_entities) is None
        assert _resolve_entity("Stadtwerke", sample_entities) is None

    def test_whitespace_not_trimmed(self, sample_entities):
        """Leading/trailing whitespace means no match."""
        assert _resolve_entity(" Max Mustermann ", sample_entities) is None


class TestResolveTags:
    def test_all_tags_found(self, sample_entities, patch_db):
        proposed = [
            {"name": "Finanzen", "confidence": 90},
            {"name": "Wohnung", "confidence": 70},
        ]
        ids, dicts = _resolve_tags(proposed, sample_entities)
        assert ids == [20, 21]
        assert len(dicts) == 2
        assert dicts[0] == {"name": "Finanzen", "confidence": 90, "id": 20}
        assert dicts[1] == {"name": "Wohnung", "confidence": 70, "id": 21}

    def test_mixed_found_and_new(self, sample_entities, patch_db):
        proposed = [
            {"name": "Finanzen", "confidence": 90},
            {"name": "NeuerTag", "confidence": 60},
        ]
        ids, dicts = _resolve_tags(proposed, sample_entities)
        assert ids == [20]  # only Finanzen resolved
        assert dicts[1] == {"name": "NeuerTag", "confidence": 60, "id": None}

    def test_all_tags_new(self, sample_entities, patch_db):
        proposed = [
            {"name": "Komplett Neu", "confidence": 50},
        ]
        ids, dicts = _resolve_tags(proposed, sample_entities)
        assert ids == []
        assert dicts[0]["id"] is None

    def test_empty_proposed(self, sample_entities, patch_db):
        ids, dicts = _resolve_tags([], sample_entities)
        assert ids == []
        assert dicts == []

    def test_case_insensitive_tag_match(self, sample_entities, patch_db):
        proposed = [{"name": "finanzen", "confidence": 80}]
        ids, _dicts = _resolve_tags(proposed, sample_entities)
        assert ids == [20]

    def test_default_confidence(self, sample_entities, patch_db):
        proposed = [{"name": "Finanzen"}]  # no confidence key
        _ids, dicts = _resolve_tags(proposed, sample_entities)
        assert dicts[0]["confidence"] == 50  # default
