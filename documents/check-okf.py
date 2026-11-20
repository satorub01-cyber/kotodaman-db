#!/usr/bin/env python3
"""
OKF (Open Knowledge Format) v0.2 軽量準拠チェックスクリプト

外部ライブラリ（PyYAML等）に依存せず Python 3 標準ライブラリのみで以下を検証する:
1. documents/index.md および documents/mcp/index.md の okf_version: "0.2" 宣言
2. 除外対象（.agents/, AGENTS.md, old-*.md, index.md, .gitignore対象）を除く全 Markdown の
   YAML frontmatter 存在と基本構文
3. 必須キー type（許可語彙: map/spec/guide/overview/note）および
   status（draft/stable/deprecated）・description（非空1行）の検証
4. index.md 内の相対リンク切れ検証、および対象ファイルの index.md 網羅確認
5. MCP配信ガイド3本の frontmatter 剥離後先頭行チェック
6. AGENTS.md のサイズ上限（15KB = 15360 bytes）チェック
"""

from __future__ import annotations

import re
import sys
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parent.parent

ALLOWED_TYPES = {"map", "spec", "guide", "overview", "note"}
ALLOWED_STATUSES = {"draft", "stable", "deprecated"}
AGENTS_MAX_BYTES = 15 * 1024

IGNORED_DIR_PREFIXES = (
    ".agents",
    ".git",
    "documents/official-documents-for-AI",
    "documents/presentations",
    "local-dev",
    "node_modules",
    "vendor",
)

EXCLUDED_EXACT = {
    "AGENTS.md",
    "documents/index.md",
    "documents/mcp/index.md",
}

MCP_RESOURCE_FILES = {
    "documents/mcp/character-draft-guide.md": "kotodaman://guide/character-draft",
    "documents/mcp/character-entry-guide.md": "kotodaman://guide/character-entry",
    "documents/mcp/kotowaza-guide.md": "kotodaman://guide/kotowaza",
}

FRONTMATTER_RE = re.compile(
    r"\A(?:\ufeff)?---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\Z)",
    re.DOTALL,
)


def is_excluded(rel_posix: str) -> bool:
    if rel_posix in EXCLUDED_EXACT:
        return True
    for prefix in IGNORED_DIR_PREFIXES:
        if rel_posix == prefix or rel_posix.startswith(prefix + "/"):
            return True
    name = Path(rel_posix).name
    if name.startswith("old-"):
        return True
    return False


def parse_simple_yaml(yaml_text: str) -> tuple[dict[str, object], list[str]]:
    """frontmatter 用の軽量 YAML パーサー（単一行キー値・インライン配列・複数行リスト対応）"""
    data: dict[str, object] = {}
    errors: list[str] = []
    lines = yaml_text.splitlines()
    current_list_key: str | None = None

    for lineno, raw_line in enumerate(lines, start=1):
        stripped = raw_line.strip()
        if not stripped or stripped.startswith("#"):
            continue

        if stripped.startswith("- "):
            if current_list_key is None:
                errors.append(f"YAML line {lineno}: 親キーのないリスト要素: {raw_line}")
                continue
            item = stripped[2:].strip()
            lst = data.setdefault(current_list_key, [])
            if isinstance(lst, list):
                lst.append(unquote_scalar(item))
            continue

        if ":" not in raw_line:
            errors.append(f"YAML line {lineno}: 'key: value' 形式ではありません: {raw_line}")
            continue

        current_list_key = None
        key, val = raw_line.split(":", 1)
        key = key.strip()
        val = val.strip()

        if not re.match(r"^[A-Za-z0-9_-]+$", key):
            errors.append(f"YAML line {lineno}: 不正なキー名 '{key}'")
            continue

        if val == "":
            data[key] = []
            current_list_key = key
        elif val.startswith("[") and val.endswith("]"):
            inner = val[1:-1].strip()
            if inner == "":
                data[key] = []
            else:
                data[key] = [unquote_scalar(part.strip()) for part in inner.split(",")]
        elif (val.startswith("[") and not val.endswith("]")) or (val.startswith('"') and not val.endswith('"')) or (val.startswith("'") and not val.endswith("'")):
            errors.append(f"YAML line {lineno}: 閉じ括弧またはクォートが閉じていません: {raw_line}")
        else:
            data[key] = unquote_scalar(val)

    return data, errors


def unquote_scalar(val: str) -> str:
    if len(val) >= 2 and ((val[0] == '"' and val[-1] == '"') or (val[0] == "'" and val[-1] == "'")):
        return val[1:-1]
    return val


def check_links_in_index(index_path: Path, errors: list[str]) -> set[str]:
    text = index_path.read_text(encoding="utf-8")
    linked_rel_paths: set[str] = set()
    link_re = re.compile(r"\[[^\]]+\]\(([^)#\s]+)(?:#[^)]*)?\)")
    for match in link_re.finditer(text):
        raw_target = match.group(1)
        if raw_target.startswith(("http://", "https://", "mailto:")):
            continue
        decoded = unquote(raw_target)
        resolved = (index_path.parent / decoded).resolve()
        if not resolved.exists():
            errors.append(f"{index_path.relative_to(ROOT).as_posix()}: リンク切れ '{raw_target}'")
            continue
        try:
            linked_rel_paths.add(resolved.relative_to(ROOT).as_posix())
        except ValueError:
            errors.append(f"{index_path.relative_to(ROOT).as_posix()}: リポジトリ外へのリンク '{raw_target}'")
    return linked_rel_paths


def main() -> int:
    errors: list[str] = []

    # 1. AGENTS.md サイズ上限チェック
    agents_path = ROOT / "AGENTS.md"
    if not agents_path.exists():
        errors.append("AGENTS.md が見つかりません")
    else:
        agents_size = agents_path.stat().st_size
        if agents_size > AGENTS_MAX_BYTES:
            errors.append(f"AGENTS.md が 15KB 上限を超過しています ({agents_size} bytes > {AGENTS_MAX_BYTES})")

    # 2. 予約 index.md チェック
    for idx_rel in ("documents/index.md", "documents/mcp/index.md"):
        idx_path = ROOT / idx_rel
        if not idx_path.exists():
            errors.append(f"必須インデックスが見つかりません: {idx_rel}")
            continue
        content = idx_path.read_text(encoding="utf-8")
        fm_match = FRONTMATTER_RE.match(content)
        if not fm_match:
            errors.append(f"{idx_rel}: YAML frontmatter がありません")
            continue
        meta, parse_errs = parse_simple_yaml(fm_match.group(1))
        errors.extend(f"{idx_rel}: {e}" for e in parse_errs)
        if str(meta.get("okf_version", "")) != "0.2":
            errors.append(f"{idx_rel}: okf_version が '0.2' ではありません (actual={meta.get('okf_version')!r})")

    root_links = check_links_in_index(ROOT / "documents/index.md", errors) if (ROOT / "documents/index.md").exists() else set()
    if (ROOT / "documents/mcp/index.md").exists():
        check_links_in_index(ROOT / "documents/mcp/index.md", errors)

    # 3. 対象 Markdown 一括チェック
    target_files: list[Path] = []
    for md_path in sorted(ROOT.rglob("*.md")):
        rel_posix = md_path.relative_to(ROOT).as_posix()
        if is_excluded(rel_posix):
            continue
        target_files.append(md_path)

    for md_path in target_files:
        rel_posix = md_path.relative_to(ROOT).as_posix()
        content = md_path.read_text(encoding="utf-8")
        fm_match = FRONTMATTER_RE.match(content)
        if not fm_match:
            errors.append(f"{rel_posix}: YAML frontmatter がありません")
            continue

        meta, parse_errs = parse_simple_yaml(fm_match.group(1))
        errors.extend(f"{rel_posix}: {e}" for e in parse_errs)

        doc_type = str(meta.get("type", "")).strip()
        if not doc_type:
            errors.append(f"{rel_posix}: 必須キー 'type' が空または未設定です")
        elif doc_type not in ALLOWED_TYPES:
            errors.append(f"{rel_posix}: 未定義の type '{doc_type}' (許可: {sorted(ALLOWED_TYPES)})")

        for rec_key in ("title", "description", "status"):
            val = str(meta.get(rec_key, "")).strip()
            if not val:
                errors.append(f"{rel_posix}: 推奨キー '{rec_key}' が空または未設定です")

        status = str(meta.get("status", "")).strip()
        if status and status not in ALLOWED_STATUSES:
            errors.append(f"{rel_posix}: 未定義の status '{status}' (許可: {sorted(ALLOWED_STATUSES)})")

        if "tags" not in meta or not isinstance(meta["tags"], list) or len(meta["tags"]) == 0:
            errors.append(f"{rel_posix}: 'tags' が配列として設定されていません")

        if rel_posix not in root_links:
            errors.append(f"{rel_posix}: documents/index.md からリンクされていません")

        if rel_posix in MCP_RESOURCE_FILES:
            expected_uri = MCP_RESOURCE_FILES[rel_posix]
            if str(meta.get("resource", "")).strip() != expected_uri:
                errors.append(f"{rel_posix}: resource キーが '{expected_uri}' ではありません")
            stripped_body = content[fm_match.end() :].lstrip("\r\n")
            if not stripped_body.startswith("# "):
                errors.append(f"{rel_posix}: frontmatter 除去後の先頭が '# ' 見出しで始まりません")

    if errors:
        print("OKF 準拠チェックでエラーが検出されました:")
        for err in errors:
            print(f"  [ERROR] {err}")
        return 1

    print(
        f"OK: 対象 Markdown {len(target_files)} 本の OKF v0.2 準拠、リンク整合、"
        f"MCPリソース剥離後書式、AGENTS.md サイズ ({agents_path.stat().st_size} bytes) を確認しました。"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
