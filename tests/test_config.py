from __future__ import annotations

from pathlib import Path

import pytest


def test_prompts_dir_prefers_source_tree():
    from app.config import Settings

    cfg = Settings()

    prompts_dir = cfg.prompts_dir

    assert prompts_dir.name == "prompts"
    assert prompts_dir.is_dir()
    assert (prompts_dir / "classify_system.txt").is_file()


@pytest.mark.parametrize(
    ("env_name", "field_name", "expected"),
    [
        ("PAPERLESS_INBOX_TAG_ID", "paperless_inbox_tag_id", 0),
        ("PAPERLESS_PROCESSED_TAG_ID", "paperless_processed_tag_id", None),
        ("ENABLE_TELEGRAM", "enable_telegram", False),
        ("OLLAMA_MODEL_SWAP_DELAY", "ollama_model_swap_delay", 8.0),
    ],
)
def test_empty_non_string_env_values_use_defaults(monkeypatch, env_name, field_name, expected):
    from app.config import Settings

    monkeypatch.setenv(env_name, "")

    cfg = Settings()

    assert getattr(cfg, field_name) == expected


def test_empty_string_env_values_remain_empty_strings(monkeypatch):
    from app.config import Settings

    monkeypatch.setenv("PAPERLESS_URL", "")

    cfg = Settings()

    assert cfg.paperless_url == ""


def test_blank_config_env_secret_does_not_override_existing_env_value(tmp_path, monkeypatch):
    import app.config as config_module

    (tmp_path / "config.env").write_text("PAPERLESS_TOKEN=\n", encoding="utf-8")
    original_data_dir = config_module.settings.data_dir
    original_token = config_module.settings.paperless_token
    object.__setattr__(config_module.settings, "data_dir", str(tmp_path))
    object.__setattr__(config_module.settings, "paperless_token", "env-token")

    config_module._apply_config_env_overrides()

    assert config_module.settings.paperless_token == "env-token"

    object.__setattr__(config_module.settings, "data_dir", original_data_dir)
    object.__setattr__(config_module.settings, "paperless_token", original_token)


def test_prompts_dir_falls_back_to_workdir_prompts(tmp_path, monkeypatch):
    from app.config import Settings

    workdir_prompts = tmp_path / "prompts"
    workdir_prompts.mkdir()
    (workdir_prompts / "classify_system.txt").write_text("fallback", encoding="utf-8")
    monkeypatch.chdir(tmp_path)

    import app.config as config_module

    source_prompts = Path(config_module.__file__).parent.parent / "prompts"
    original_is_dir = Path.is_dir

    def fake_is_dir(self: Path) -> bool:
        if self == source_prompts:
            return False
        return original_is_dir(self)

    monkeypatch.setattr(Path, "is_dir", fake_is_dir)

    cfg = Settings()

    assert cfg.prompts_dir == workdir_prompts
