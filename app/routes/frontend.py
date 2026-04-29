"""Routes for serving the new SvelteKit admin frontend under /app."""

from __future__ import annotations

from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse

from app.config import needs_setup

router = APIRouter(tags=["frontend"])

FRONTEND_BUILD_DIR = Path(__file__).resolve().parents[2] / "frontend" / "build"


def _safe_build_path(path: str) -> Path | None:
    candidate = (FRONTEND_BUILD_DIR / path).resolve()
    try:
        candidate.relative_to(FRONTEND_BUILD_DIR.resolve())
    except ValueError:
        return None
    return candidate


def _frontend_missing_response() -> HTMLResponse:
    return HTMLResponse(
        """
        <!doctype html>
        <html lang="de">
          <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>ArchiBot Admin Frontend</title>
            <style>
              body { font-family: Inter, system-ui, sans-serif; background: #020617; color: #e2e8f0; margin: 0; }
              main { max-width: 56rem; margin: 4rem auto; padding: 2rem; }
              .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 1.5rem; padding: 2rem; }
              code { background: #020617; padding: 0.2rem 0.4rem; border-radius: 0.5rem; }
              a { color: #34d399; }
            </style>
          </head>
          <body>
            <main>
              <div class="card">
                <p>Neue Admin-Oberfläche</p>
                <h1>SvelteKit-Build fehlt</h1>
                <p>
                  Baue das Frontend mit <code>cd frontend && npm install && npm run build</code> und lade dann <code>/app</code> neu.
                </p>
                <p>
                  Die servergerenderte Legacy-Oberfläche wurde entfernt; die Admin-UI läuft jetzt ausschließlich unter <a href="/app">/app</a>.
                </p>
              </div>
            </main>
          </body>
        </html>
        """,
        status_code=503,
    )


@router.get("/app", include_in_schema=False)
@router.get("/app/{path:path}", include_in_schema=False)
async def frontend_app(path: str = ""):
    if needs_setup() and path not in ("setup", "setup/"):
        return RedirectResponse(url="/app/setup", status_code=302)

    if not FRONTEND_BUILD_DIR.exists():
        return _frontend_missing_response()

    if path:
        candidate = _safe_build_path(path)
        if candidate and candidate.is_file():
            return FileResponse(candidate)

    index_path = FRONTEND_BUILD_DIR / "index.html"
    if not index_path.exists():
        return _frontend_missing_response()
    return FileResponse(index_path)
