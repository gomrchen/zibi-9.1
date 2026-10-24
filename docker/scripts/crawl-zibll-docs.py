#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""爬取子比后台「主题文档」外链到主题 docs/，供本地引用。"""
from __future__ import annotations

import hashlib
import html as html_lib
import json
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / "wordpress/wp-content/themes/zibll"
OPTIONS = THEME / "inc/options/options-module.php"
DOCS = THEME / "docs"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
CHROME = Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
CHROME_PROFILE = Path("/tmp/zibll-docs-chrome-profile")

HTML_SHELL = """<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<style>
body{{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;line-height:1.7;max-width:860px;margin:24px auto;padding:0 16px;color:#222;background:#fafafa}}
header{{margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid #e5e5e5}}
header a{{color:#666;font-size:13px;text-decoration:none}}
h1{{font-size:1.55rem;margin:8px 0 0}}
.meta{{color:#888;font-size:12px;margin-top:6px}}
.article{{background:#fff;border:1px solid #eee;border-radius:8px;padding:20px 22px}}
.article img{{max-width:100%;height:auto}}
.article pre,.article code{{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}}
.article pre{{overflow:auto;background:#f6f8fa;padding:12px;border-radius:6px}}
.article a{{color:#2563eb}}
.list li{{margin:8px 0}}
footer{{margin:28px 0 40px;color:#999;font-size:12px}}
</style>
</head>
<body>
<header>
  <a href="index.html">← 本地主题文档目录</a>
  <h1>{title}</h1>
  <div class="meta">来源：{source} · 离线镜像</div>
</header>
<div class="article">
{body}
</div>
<footer>本页由开源发行版本地镜像生成，仅供离线查阅。</footer>
</body>
</html>
"""


def slug_for(url: str) -> str:
    p = urllib.parse.urlparse(url)
    path = urllib.parse.unquote(p.path or "").strip("/")
    qs = urllib.parse.parse_qs(p.query)
    if qs.get("s"):
        q = qs["s"][0]
        return "search-" + re.sub(r"[^\w\u4e00-\u9fff-]+", "-", q).strip("-")
    if not path:
        return "home"
    if path.endswith(".html"):
        return path[:-5] if "/" not in path else path.replace("/", "-")[:-5]
    safe = re.sub(r"[^\w\u4e00-\u9fff.-]+", "-", path).strip("-")
    return safe or hashlib.md5(url.encode()).hexdigest()[:12]


def _quote_url(url: str) -> str:
    """保证查询串中文被正确编码。"""
    p = urllib.parse.urlsplit(url)
    path = urllib.parse.quote(urllib.parse.unquote(p.path), safe="/:%")
    query = urllib.parse.urlencode(urllib.parse.parse_qsl(p.query, keep_blank_values=True), encoding="utf-8")
    return urllib.parse.urlunsplit((p.scheme, p.netloc, path, query, p.fragment))


def fetch_urllib(url: str) -> str:
    req = urllib.request.Request(
        _quote_url(url),
        headers={
            "User-Agent": UA,
            "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "zh-CN,zh;q=0.9",
            "Referer": "https://www.zibll.com/",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        charset = resp.headers.get_content_charset() or "utf-8"
        return resp.read().decode(charset, errors="ignore")


def fetch_chrome(url: str) -> str:
    CHROME_PROFILE.mkdir(parents=True, exist_ok=True)
    cmd = [
        str(CHROME),
        "--headless=new",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        f"--user-data-dir={CHROME_PROFILE}",
        "--virtual-time-budget=10000",
        "--dump-dom",
        _quote_url(url),
    ]
    r = subprocess.run(cmd, capture_output=True, timeout=60)
    html = r.stdout.decode("utf-8", errors="ignore")
    if len(html) < 2000 or "EO_Bot_Ssid" in html[:1500]:
        raise RuntimeError(f"chrome bot/empty len={len(html)}")
    return html


def fetch(url: str, retries: int = 3) -> str:
    last = None
    for i in range(retries):
        try:
            html = fetch_urllib(url)
            if len(html) > 5000 and "EO_Bot_Ssid" not in html[:1500] and (
                "article-content" in html or "wp-posts-content" in html or "/?s=" in url or "zibll_word" in url
            ):
                return html
            # 被盾或内容不足 → Chrome
            return fetch_chrome(url)
        except Exception as e:  # noqa: BLE001
            last = e
            time.sleep(1.0 * (i + 1))
            try:
                return fetch_chrome(url)
            except Exception as e2:  # noqa: BLE001
                last = e2
                time.sleep(1.2)
    raise RuntimeError(f"fetch failed {url}: {last}")


def extract_title(page: str) -> str:
    m = re.search(r'<h1 class="article-title">\s*<a[^>]*>(.*?)</a>', page, re.S)
    if m:
        return re.sub(r"<[^>]+>", "", m.group(1)).strip()
    m = re.search(r"<title>([^<]+)</title>", page, re.S)
    if m:
        t = m.group(1).strip()
        t = re.split(r"-Wordpress主题|-WordPress主题|-zibll|–|—", t)[0].strip()
        return t
    return "未命名文档"


def extract_article(page: str) -> str:
    patterns = [
        r'<div class="article-content">(.*?)</div>\s*<div class="theme-box article-tags"',
        r'<div class="article-content">(.*?)</div>\s*<div class="[^"]*article-(?:tags|nav|actions)',
        r'<div[^>]*class="[^"]*wp-posts-content[^"]*"[^>]*>(.*?)</div>\s*<div class="theme-box article-tags"',
        r'<div[^>]*class="[^"]*wp-posts-content[^"]*"[^>]*>(.*?)</div>\s*<div class="[^"]*article-(?:tags|nav)',
        r'<article[^>]*class="[^"]*article[^"]*"[^>]*>.*?<div class="article-content">(.*?)</div>',
    ]
    for pat in patterns:
        m = re.search(pat, page, re.S)
        if m and len(m.group(1).strip()) > 80:
            return m.group(1).strip()
    # 宽松：从 wp-posts-content 起截到 article-tags / 评论区
    m = re.search(
        r'(<div[^>]*class="[^"]*wp-posts-content[^"]*"[^>]*>)([\s\S]*?)(<div class="theme-box article-tags"|<div[^>]*id="comments"|<div class="article-nav")',
        page,
    )
    if m and len(m.group(2).strip()) > 80:
        return (m.group(1) + m.group(2)).strip()
    return ""


def extract_list_links(page: str, base: str = "https://www.zibll.com") -> list[tuple[str, str]]:
    """从搜索/专题页提取文章链接与标题。"""
    items: list[tuple[str, str]] = []
    seen = set()
    # 常见列表：item-heading / article-title / posts-item a
    for m in re.finditer(
        r'<a[^>]+href="(https://www\.zibll\.com/\d+\.html)"[^>]*>(.*?)</a>',
        page,
        re.S,
    ):
        url, inner = m.group(1), m.group(2)
        title = re.sub(r"<[^>]+>", "", inner).strip()
        if not title or len(title) < 4:
            continue
        if url in seen:
            continue
        seen.add(url)
        items.append((url, title))
    return items


def rewrite_links(body: str, url_map: dict[str, str]) -> str:
    def repl(m: re.Match) -> str:
        u = m.group(1)
        local = url_map.get(u) or url_map.get(u.rstrip("/"))
        if local:
            return f'href="{local}"'
        # 同站其它文章保留原文链注释式提示
        return f'href="{u}"'

    return re.sub(r'href="(https://www\.zibll\.com[^"]*)"', repl, body)


def write_page(slug: str, title: str, source: str, body: str) -> Path:
    DOCS.mkdir(parents=True, exist_ok=True)
    path = DOCS / f"{slug}.html"
    path.write_text(
        HTML_SHELL.format(
            title=html_lib.escape(title),
            source=html_lib.escape(source),
            body=body,
        ),
        encoding="utf-8",
    )
    return path


def parse_docs_from_php() -> list[tuple[str, str]]:
    text = OPTIONS.read_text(encoding="utf-8", errors="ignore")
    # 仅主题文档列表段
    m = re.search(r"\$docs = '<div class=\"flex hh zibll-doscs\">';(.*?)\$docs \.= '</div>';", text, re.S)
    block = m.group(1) if m else text
    pairs = []
    for mm in re.finditer(
        r"href=\"(https://www\.zibll\.com[^\"]+)\"[^>]*>'\s*\.\s*esc_html__\('([^']+)'",
        block,
    ):
        pairs.append((mm.group(1), mm.group(2)))
    # 去重保序
    out, seen = [], set()
    for u, t in pairs:
        if u in seen:
            continue
        seen.add(u)
        out.append((u, t))
    return out


def main() -> None:
    docs = parse_docs_from_php()
    # 额外：更新帮助页
    extra = [
        ("https://www.zibll.com/1411.html", "在线更新遇到问题"),
        ("https://www.zibll.com/375.html", "Zibll子比主题历史更新日志"),
    ]
    for u, t in extra:
        if u not in {x[0] for x in docs}:
            docs.append((u, t))

    print(f"文档条目: {len(docs)}")
    DOCS.mkdir(parents=True, exist_ok=True)

    url_to_slug: dict[str, str] = {}
    url_to_title: dict[str, str] = {}
    queue: list[str] = []
    for url, title in docs:
        slug = slug_for(url)
        url_to_slug[url] = f"{slug}.html"
        url_to_title[url] = title
        queue.append(url)

    # 先抓索引页，再扩展子链接（搜索/专题）
    expanded: list[tuple[str, str]] = []
    for url, title in docs:
        if "/?s=" in url or "/zibll_word" in url:
            print(f"[index] {title} <- {url}")
            try:
                page = fetch(url)
                items = extract_list_links(page)
                lis = "".join(
                    f'<li><a href="{urllib.parse.quote(slug_for(u))}.html">{html_lib.escape(t)}</a>'
                    f' <small style="color:#999">({html_lib.escape(u)})</small></li>'
                    for u, t in items
                )
                body = f"<p>以下为「{html_lib.escape(title)}」检索/专题结果本地索引：</p><ul class='list'>{lis or '<li>无结果</li>'}</ul>"
                write_page(slug_for(url), title, url, body)
                for u, t in items:
                    if u not in url_to_slug:
                        url_to_slug[u] = f"{slug_for(u)}.html"
                        url_to_title[u] = t
                        expanded.append((u, t))
                        queue.append(u)
            except Exception as e:  # noqa: BLE001
                write_page(slug_for(url), title, url, f"<p>抓取失败：{html_lib.escape(str(e))}</p>")
            time.sleep(0.4)

    # 文章页
    ok, fail = 0, 0
    article_urls = [u for u in queue if "/?s=" not in u and "/zibll_word" not in urllib.parse.urlparse(u).path]
    # 去重
    seen = set()
    article_urls2 = []
    for u in article_urls:
        if u in seen:
            continue
        seen.add(u)
        article_urls2.append(u)

    print(f"待抓文章: {len(article_urls2)} (+扩展 {len(expanded)})")
    for i, url in enumerate(article_urls2, 1):
        slug = slug_for(url)
        title = url_to_title.get(url, slug)
        dest = DOCS / f"{slug}.html"
        if dest.exists() and dest.stat().st_size > 500 and "/?s=" not in url:
            # 已有且非空，仍刷新内容
            pass
        try:
            print(f"[{i}/{len(article_urls2)}] {slug} {title}")
            page = fetch(url)
            title = extract_title(page) or title
            body = extract_article(page)
            if not body:
                body = f"<p>未能解析正文，请稍后重试。原文：<a href='{html_lib.escape(url)}'>{html_lib.escape(url)}</a></p>"
                fail += 1
            else:
                ok += 1
            url_to_title[url] = title
            # 暂存，链接重写第二遍
            write_page(slug, title, url, body)
        except Exception as e:  # noqa: BLE001
            fail += 1
            write_page(slug, title, url, f"<p>抓取失败：{html_lib.escape(str(e))}</p>")
            print(f"  FAIL {e}")
        time.sleep(0.35)

    # 第二遍：站内链改本地
    for url, local in list(url_to_slug.items()):
        path = DOCS / local
        if not path.exists():
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        # 只改 article 内链：整页替换即可
        new = rewrite_links(text, url_to_slug)
        if new != text:
            path.write_text(new, encoding="utf-8")

    # index
    lis = []
    for url, title in docs:
        local = url_to_slug[url]
        lis.append(f'<li><a href="{local}">{html_lib.escape(title)}</a></li>')
    index_body = "<ul class='list'>" + "".join(lis) + "</ul>"
    write_page("index", "子比主题本地文档目录", "local", index_body)
    # 修正 index 的返回链
    idx = DOCS / "index.html"
    idx.write_text(
        idx.read_text(encoding="utf-8").replace(
            '<a href="index.html">← 本地主题文档目录</a>',
            "<span>本地主题文档目录</span>",
        ),
        encoding="utf-8",
    )

    manifest = {
        "count_listed": len(docs),
        "count_files": len(list(DOCS.glob("*.html"))),
        "ok": ok,
        "fail": fail,
        "map": {u: url_to_slug[u] for u, _ in docs},
        "titles": {url_to_slug[u]: url_to_title.get(u, "") for u, _ in docs},
    }
    (DOCS / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"ok": ok, "fail": fail, "files": manifest["count_files"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
