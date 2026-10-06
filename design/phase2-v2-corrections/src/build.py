# -*- coding: utf-8 -*-
"""Phase 2 prototype builder. Mirrors what the PHP Publish Engine will do in production:
one template + structured content -> static fa/en HTML + per-product JSON."""
import json, math, re, os, html
from pathlib import Path
from jinja2 import Environment, FileSystemLoader
from markupsafe import Markup
from strings import S, JS, CONTACT, CONTACT_SHOW, DIM_LABELS, DIM_LABELS_BY_FAMILY, RATINGS, fa

ROOT = Path(__file__).resolve().parent
OUT = ROOT.parent / "site"
cat = json.loads((ROOT / "catalogue.json").read_text(encoding="utf-8"))
fam = {f["slug"]: f for f in cat["families"]}
for f in cat["families"]:
    f["count"] = sum(1 for p in cat["products"] if p["family"] == f["slug"])

AR2FA = str.maketrans({"ي": "ی", "ك": "ک", "\u200c": "", "\u200e": "", "\u200f": "", "\u2066": "", "\u2067": "", "\u2068": "", "\u2069": ""})
DIG = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")

def norm(s):
    s = s.translate(DIG).translate(AR2FA).lower().replace("×", "x")
    return re.sub(r"[\s\-_/.]+", "", s)

def dims_show(p):
    d = p["dims"]
    if p["family"] == "unit":
        return f"d {d['d']:g}"
    order = {"needle": ["Fw", "D", "C"], "thrust": ["d", "D", "H"], "tapered": ["d", "D", "T"], "seal": ["d", "D", "b"]}.get(p["family"], ["d", "D", "B"])
    return " × ".join(f"{d[k]:g}" for k in order if k in d)

for p in cat["products"]:
    # Search keys, kept apart on purpose: k = this designation (and equivalent spellings),
    # v = DIFFERENT designations (suffix variants) that resolve to this base series, t = free text for partial matches
    p["k"] = "|".join(dict.fromkeys(norm(n) for n in [p["code"], *p["spellings"]]))
    p["v"] = "|".join(norm(v["code"]) for v in p["variants"])
    p["vn"] = "|".join(v["code"] for v in p["variants"])
    p["t"] = "|".join(norm(n) for n in [p["code"], *p["spellings"], *[v["code"] for v in p["variants"]], fam[p["family"]]["fa"], fam[p["family"]]["en"]])
    p["bore"] = p["dims"].get("d", p["dims"].get("Fw", ""))
    p["dims_show"] = dims_show(p)
    d = p["dims"]
    order = {"needle": ["Fw", "D", "C"], "thrust": ["d", "D", "H"], "tapered": ["d", "D", "T"], "seal": ["d", "D", "b"], "unit": ["d"]}.get(p["family"], ["d", "D", "B"])
    p["cols"] = [(k, f"{d[k]:g}") if k in d else (k, None) for k in order] + [(None, None)] * (3 - len(order))

def wa(text):
    from urllib.parse import quote
    return f"https://wa.me/{CONTACT['wa']}?text={quote(text)}"

hero_balls = [(round(150 + 75 * math.cos(k * 2 * math.pi / 9 - math.pi / 2), 1), round(180 + 75 * math.sin(k * 2 * math.pi / 9 - math.pi / 2), 1)) for k in range(9)]
bom_range = [(round(.45 + i * .045, 3), round(.45 + (i + 1) * .045, 3)) for i in range(6)]
steps_range = [(0, .1), (.1, .3), (.3, .45), (.45, .72), (.72, .86), (.86, 1.01)]


# ---------------------------------------------------------------- critical CSS split (mirrors the Publish Engine plan in D5)
CRIT_COMP = ("prototype marker", "Header", "Buttons", "Search", "hero search", "Drawing frame", "Mobile action dock")
CRIT_SECT = ("HERO",)

def split_css():
    css = (OUT / "assets" / "nova.css").read_text(encoding="utf-8")
    order = re.search(r"@layer [\w, ]+;", css).group(0)
    blocks, extra, i = {}, [], 0
    for m in re.finditer(r"@layer (\w+) \{\n", css):
        start = m.end(); depth = 1; j = start
        while depth:
            c = css[j]; depth += (c == "{") - (c == "}"); j += 1
        blocks[m.group(1)] = css[start:j - 1]
        if m.group(1) == "sect":
            sect_end = j
    extra = re.findall(r"^@property[^\n]+", css, re.M)
    def sections(body, crit):
        parts = re.split(r"(?m)^(?=  /\* )", body)
        keep, rest = [], []
        for k, part in enumerate(parts):
            head = part.strip()[3:40] if part.strip().startswith("/*") else ""
            (keep if (k == 0 and not head) or any(head.startswith(x) for x in crit) else rest).append(part)
        return "".join(keep), "".join(rest)
    cc, cr = sections(blocks["comp"], CRIT_COMP)
    sc, sr = sections(blocks["sect"], CRIT_SECT)
    crit = order + "".join(f"@layer {n}{{{blocks[n]}}}" for n in ("reset", "tokens", "base", "layout")) + f"@layer comp{{{cc}}}@layer sect{{{sc}}}"
    rest = order + f"@layer comp{{{cr}}}@layer sect{{{sr}}}" + "".join(extra) + f"@layer motion{{{blocks['motion']}}}"
    def mini(t):
        t = re.sub(r"/\*.*?\*/", "", t, flags=re.S)
        return re.sub(r"\n\s*", "\n", t).strip()
    return mini(crit), mini(rest)

from jinja2 import StrictUndefined
# ---------------------------------------------------------------- JS: hand-written sources in src/js, minified at build time
# (developer tooling on a workstation; production runs no Node: hashed, minified assets are deployed as static files)
def build_js():
    import shutil, subprocess
    terser = ROOT.parent / "qa" / "node_modules" / ".bin" / "terser"
    for name in ("nova.js", "ws.js"):
        src, dst = ROOT / "js" / name, OUT / "assets" / name
        try:
            subprocess.run([str(terser), str(src), "--module", "--compress", "passes=2", "--mangle", "--comments", "false", "-o", str(dst)], check=True, capture_output=True)
        except Exception:
            shutil.copyfile(src, dst)

env = Environment(loader=FileSystemLoader(str(ROOT)), autoescape=True, trim_blocks=True, lstrip_blocks=True)
# public pages render strictly: a missing string must fail the build, never ship as an empty label
strict_env = Environment(loader=FileSystemLoader(str(ROOT)), autoescape=True, trim_blocks=True, lstrip_blocks=True, undefined=StrictUndefined)

def render_page(lang):
    other = "en" if lang == "fa" else "fa"
    t = S[lang]
    num = (lambda n: fa(n)) if lang == "fa" else (lambda n: str(n))
    def sheet(n):
        return f"{n:02d}"   # drawing references are Latin on every page
    def sheethead(n, name):
        return Markup(f'<div class="sheethead" aria-hidden="true"><span class="sheethead__no">{sheet(n)} <small>/ {sheet(10)}</small></span>'
                      f'<span class="sheethead__name">{html.escape(name)}</span><span class="sheethead__ticks"></span><span class="sheethead__ref">PC-{n:02d}</span></div>')
    c = dict(CONTACT, **CONTACT_SHOW[lang])
    js_strings = dict(JS[lang], lang=lang, wa=CONTACT["wa"], tel=CONTACT["tel1"], checked=cat["checked"])
    crit, rest = split_css()
    (OUT / "assets" / "nova-rest.css").write_text(rest, encoding="utf-8")
    tpl = strict_env.get_template("page.html.j2")
    out = tpl.render(crit_css=Markup(crit), lang=lang, dir="rtl" if lang == "fa" else "ltr", other_lang=other, other_file=f"{other}.html",
                     subset="fa" if lang == "fa" else "latin", t=t, c=c, families=cat["families"], fam=fam,
                     products=cat["products"], examples=["6205", "22220 E", "UCP 208", "30208"], num=num, sheet=sheet,
                     sheethead=sheethead, wa=wa, hero_balls=hero_balls, bom_range=bom_range, steps_range=steps_range,
                     js_strings=Markup(json.dumps(js_strings, ensure_ascii=False).replace("</", "<\\/")))
    (OUT / f"{lang}.html").write_text(out, encoding="utf-8")

def product_json(p):
    """Per-product JSON, as the Publish Engine will emit it. Provenance is per value: every value row carries
    its own status (ok / pending) and source key; sources are listed once with their checked date."""
    f = p["family"]
    order = {"needle": ["Fw", "D", "C"], "thrust": ["d", "D", "H"], "tapered": ["d", "D", "T"], "seal": ["d", "D", "b"], "unit": ["d"]}.get(f, ["d", "D", "B"])
    rows = []
    for k in order:
        if k in p["dims"]:
            lab = DIM_LABELS_BY_FAMILY.get(f, {}).get(k, DIM_LABELS[k])
            src = p["dim_src"].get(k)
            rows.append({"s": k, "fa": lab[0], "en": lab[1], "v": p["dims"][k], "u": "mm", "src": src, "st": "ok" if src else "pending"})
    extra = [dict(e, st="ok") for e in p.get("extra", [])]
    kind = "unit" if f == "unit" else "seal" if f == "seal" else "radial"
    return {
        "slug": p["slug"], "code": p["code"], "family": f, "status": p["status"],
        "spellings": p["spellings"], "variants": [v["code"] for v in p["variants"]],
        "fam": {k: fam[f][k] for k in ("fa", "en", "def_fa", "def_en", "glyph")},
        "dims": rows, "extra": extra, "dims_note": p.get("dims_note"),
        "sources": [dict(v, key=k) for k, v in p["sources"].items()],
        "ratings": [{"s": r[0], "fa": r[1], "en": r[2], "st": "pending"} for r in RATINGS[kind]],
        "demo": bool(p.get("provenance_demo")),
    }

ADMIN_DRAWING = """<svg viewBox="0 0 300 200" aria-hidden="true"><defs><pattern id="wf-h" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="5" stroke="#2f338a" stroke-width="1"/></pattern><marker id="wf-a" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 1L8 4L0 7Z" fill="#2f338a"/></marker></defs>
<path d="M50 100H250" stroke="#2f338a" stroke-width=".8" stroke-dasharray="10 3 2 3"/>
<g stroke="#2f338a" stroke-width="1.2" fill="url(#wf-h)"><rect x="130" y="30" width="40" height="12"/><rect x="130" y="54" width="40" height="12.5"/><rect x="130" y="133.5" width="40" height="12.5"/><rect x="130" y="158" width="40" height="12"/></g>
<g stroke="#2f338a" stroke-width="1.2" fill="#fff"><circle cx="150" cy="48" r="6.5"/><circle cx="150" cy="152" r="6.5"/></g>
<g stroke="#2f338a" stroke-width=".8" fill="none"><path d="M172 30H206M172 170H206M128 66.5H94M128 133.5H94M130 172V192M170 172V192"/><path d="M200 31V169M100 67.5V132.5M131 187H169" marker-start="url(#wf-a)" marker-end="url(#wf-a)"/></g>
<g font-family="JetBrains Mono, monospace" font-size="11" font-weight="600" fill="#2f338a"><text x="206" y="104">Ø52</text><text x="94" y="104" text-anchor="end">Ø25</text><text x="150" y="182" text-anchor="middle">15</text></g></svg>"""

def render_admin():
    order = {"needle": ["Fw", "D", "C"], "thrust": ["d", "D", "H"], "tapered": ["d", "D", "T"], "seal": ["d", "D", "b"], "unit": ["d"]}
    edited = ["۱۰:۱۲", "دیروز", "دیروز", "۳ روز پیش", "۳ روز پیش", "هفته پیش", "۱۰:۱۲", "هفته پیش", "هفته پیش", "هفته پیش", "هفته پیش", "۲ روز پیش", "۲ روز پیش"]
    rows = []
    for i, p in enumerate(cat["products"]):
        keys = order.get(p["family"], ["d", "D", "B"])
        d = p["dims"]
        have = [k for k in keys if k in d]
        sourced = len(have) if p.get("source") else 0
        if p["family"] == "unit":
            src, cls = f"{sourced}/?", "warn"
        else:
            src, cls = f"{sourced}/{len(keys)}", ("" if sourced == len(keys) else "bad")
        rows.append(dict(code=p["code"], fam_fa=fam[p["family"]]["short_fa"], syms="×".join(have), dims_cell=" × ".join(f"{d[k]:g}" for k in have),
                         src=src, src_cls=cls, status=p["status"], draft=False, en_missing=False, edited=edited[i % len(edited)], sel=p["code"] in ("UCP 208", "TC 25×42×7")))
    rows.append(dict(code="NU 2210", fam_fa=fam["cylindrical"]["short_fa"], syms="", dims_cell="", src="0/3", src_cls="bad", status="pending", draft=True, en_missing=True, edited="امروز", sel=False))
    inquiries = [
        dict(name="مشتری نمونه ۱", org="شرکت نمونه", codes=[("6205-2RS", True), ("6208", False)], lang="fa", when="۱۰:۰۵", new=True, st="جدید", st_cls="b-new"),
        dict(name="مشتری نمونه ۲", org="کارگاه نمونه", codes=[("22220 E", True)], lang="fa", when="۰۹:۲۰", new=True, st="جدید", st_cls="b-new"),
        dict(name="Sample customer 3", org="Sample Ltd", codes=[("UCP 208", True), ("UCFL 205", False)], lang="en", when="دیروز", new=True, st="جدید", st_cls="b-new"),
        dict(name="مشتری نمونه ۴", org="—", codes=[("NU 2210", False)], lang="fa", when="دیروز", new=False, st="در حال پیگیری", st_cls="b-pend"),
        dict(name="مشتری نمونه ۵", org="شرکت نمونه", codes=[("30208", True)], lang="fa", when="۲ روز پیش", new=False, st="پاسخ داده شد", st_cls="b-ok"),
    ]
    media = [
        dict(fn="6205-section-a1b2c3d4.webp", size="38 KB", dim="1600×1200", refs=2, sel=True, block=True),
        dict(fn="ucp-208-photo-9f8e7d6c.webp", size="41 KB", dim="1600×1200", refs=1),
        dict(fn="family-tapered-5a4b3c2d.webp", size="22 KB", dim="1024×768", refs=1, deco=True),
        dict(fn="datasheet-placeholder-0a1b2c3d.pdf", size="1.2 MB", pages="۴ صفحه", pdf=True, refs=1),
        dict(fn="workshop-placeholder-7e6d5c4b.webp", size="64 KB", dim="1600×1067", unused=True),
        dict(fn="seal-tc-3c2b1a0f.webp", size="18 KB", dim="1024×768", refs=1),
        dict(fn="housing-ucp-8d7c6b5a.webp", size="27 KB", dim="1024×768", refs=1),
        dict(fn="lubricant-placeholder-2f1e0d9c.webp", size="19 KB", dim="1024×768", refs=1),
    ]
    sections = [("01", S["fa"]["s1"], True, True), ("02", S["fa"]["s2"], True, False), ("03", S["fa"]["s3"], True, False), ("04", S["fa"]["s4"], True, False),
                ("05", S["fa"]["s5"], True, False), ("06", S["fa"]["s6"], True, False), ("07", S["fa"]["s7"], True, False), ("08", S["fa"]["s8"], False, False),
                ("09", S["fa"]["s9"], True, False), ("10", S["fa"]["s10"], True, False)]
    releases = [("R-0412", "دیروز ۱۵:۴۲", "۳ تغییر", True), ("R-0411", "۳ روز پیش", "۱۲ تغییر", False), ("R-0410", "هفته پیش", "۱ تغییر", False),
                ("R-0409", "هفته پیش", "۵ تغییر", False), ("R-0408", "۲ هفته پیش", "۲۰ تغییر", False)]
    c = dict(name_fa="پولاد چرخش", name_en="Polad Charkhesh", addr_fa=S["fa"]["address"], addr_en=S["en"]["address"],
             phones=[("همراه", CONTACT_SHOW["fa"]["tel1_show"], CONTACT["tel1"], True), ("همراه", CONTACT_SHOW["fa"]["tel2_show"], CONTACT["tel2"], False),
                     ("ثابت", CONTACT_SHOW["fa"]["land1_show"], CONTACT["land1"], False), ("ثابت", CONTACT_SHOW["fa"]["land2_show"], CONTACT["land2"], False)],
             hours=[(dname, "open", "08:00", "16:00") for dname in ["شنبه", "یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه"]] + [("پنج‌شنبه", "unknown", "—", "—"), ("جمعه", "unknown", "—", "—")])
    nav = [(None, [("dash", "پیشخوان", 0, False)]),
           ("کاتالوگ", [("products", "محصولات", 0, False), ("fam", "خانواده‌ها", 0, False), ("src", "منابع", 0, False), ("brand", "برندها", 0, False)]),
           ("محتوا", [("sections", "بخش‌های صفحه", 0, False), ("media", "رسانه", 0, False), ("docs", "مستندات", 0, False), ("seo", "سئو", 0, False)]),
           ("ارتباط", [("inq", "استعلام‌ها", 3, False), ("company", "شرکت و تماس", 0, False)]),
           ("انتشار", [("publish", "انتشار", 7, True)]),
           ("سیستم", [("users", "کاربران و نقش‌ها", 0, False), ("audit", "گزارش رویدادها", 0, False), ("backup", "پشتیبان‌گیری", 0, False)])]
    toc = [("A1", "dashboard", "Dashboard"), ("A2", "products", "Products"), ("A3", "editor", "Product editor"), ("A4", "media", "Media library"),
           ("A5", "sections", "Sections / content"), ("A6", "inquiries", "Inquiries"), ("A7", "publish", "Publish"), ("A8", "company", "Company / settings"),
           ("A9", "mobile", "Mobile triage"), ("·", "model", "Entity map"), ("·", "variants", "Variant policy"), ("·", "migration", "Migration plan"), ("·", "standards", "Standards & decisions")]
    entity_map = [
        ("Product", "A2 list · A3 editor", "A1 data quality · A6 code matching · A7 diff", "Status and Verification are independent; Verified needs every core dimension sourced (Owner only)"),
        ("ProductVariant", "A3 · variant table", "Public search · A6 matching", "A different designation (suffix) under a base product; own status; optional variant-specific SpecValues; until it has them the site says \u201cbase series match — requires confirmation\u201d"),
        ("Spelling", "A3 · equivalent spellings", "Search keys", "Another way of writing the same designation; unique normalised key; never used for suffixes"),
        ("SpecValue", "A3 Dimensions & data", "Public readout, tables, drawings", "One row per value: value, unit, source, check date, is_placeholder; empty never renders"),
        ("Family", "Families (list + form)", "A2 filter · A3 · public rail", "10 fixed rows; controlled spec-key list and drawing template per family"),
        ("Brand", "Brands (list + form)", "A3 brand select", "Only brands marked verified render publicly; no distributor claims"),
        ("Source", "A3 quick source · Sources list", "A3 per-value picker · public provenance line", "Reusable; title, publisher, edition/year, URL or file"),
        ("Media", "A4 library", "A3 Media tab · Sections · A7 validation", "Validated and converted at upload; role informative or decorative; ALT-INF blocks the affected language content in Publish; unused media never blocks; reference-counted; 30-day trash"),
        ("Document", "Documents (list + form)", "A3 Documents tab · public Documents section", "PDF with title, language, version, links; size and page count shown"),
        ("Section", "A5", "A7 diff", "Fixed set and fixed fields; hero pinned first; fa/en side by side"),
        ("CompanySetting", "A8", "Header, contact, footer, structured data", "Phones stored E.164, shown Latin; tri-state hours; empty never renders"),
        ("SEO", "A3 SEO tab · SEO overview", "A7 validation", "Auto-generated defaults, overridable; length meters; index flag follows status"),
        ("Inquiry", "A6 · A9", "A1 · demand list", "Stored before notifying; keeps the requested designation verbatim + match type (exact / variant / series / related / none) + resolved product and variant; never published"),
        ("User / Role", "A8 → Users", "Top bar", "Two roles: Owner, Editor"),
        ("AuditEvent", "Audit log (read-only)", "A1 recent activity · A6 history", "Append-only"),
        ("Release / Publish", "A7", "Top bar count · A1 tile", "Diff → validate → build → atomic switch; 5 kept; one-click rollback"),
    ]
    out = env.get_template("admin.html.j2").render(products=rows, inquiries=inquiries, media=media, sections=sections, releases=releases, c=c, nav=nav,
                                                   toc=toc, entity_map=entity_map, drawing=Markup(ADMIN_DRAWING), fa=fa,
                                                   cos=lambda a: math.cos(a * math.pi / 4), sin=lambda a: math.sin(a * math.pi / 4))
    (OUT / "admin.html").write_text(out, encoding="utf-8")

if __name__ == "__main__":
    (OUT / "data" / "p").mkdir(parents=True, exist_ok=True)
    build_js()
    for lang in ("fa", "en"):
        render_page(lang)
    for p in cat["products"]:
        (OUT / "data" / "p" / f"{p['slug']}.json").write_text(json.dumps(product_json(p), ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print("built", [x.name for x in OUT.iterdir()])

# ---------------------------------------------------------------- design system + hub
def parse_tokens():
    css = (OUT / "assets" / "nova.css").read_text(encoding="utf-8")
    def block(start):
        i = css.index(start); j = css.index("}", i)
        return dict(re.findall(r"--([\w-]+):\s*(#[0-9a-fA-F]{6})", css[i:j]))
    return block(":root {"), block(':root[data-theme="dark"] {'), block(".navy {")

def lum(h):
    c = [int(h[i:i + 2], 16) / 255 for i in (1, 3, 5)]
    c = [x / 12.92 if x <= .03928 else ((x + .055) / 1.055) ** 2.4 for x in c]
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]

def ratio(a, b):
    la, lb = sorted([lum(a), lum(b)], reverse=True)
    return (la + .05) / (lb + .05)

ROLES = {"paper": "Page ground", "paper-2": "Recessed, hover", "sheet": "Raised surfaces", "ink": "Primary text", "ink-2": "Secondary text",
         "ink-3": "Tertiary text, labels", "line": "Drawing ink, rules", "line-2": "Secondary rules", "line-3": "Dividers", "grid": "Drafting grid",
         "accent": "Primary action", "accent-ink": "Text on action", "signal": "Focus, interaction", "tint": "Selected row", "tint-2": "Highlight",
         "pending": "Pending text", "pending-bg": "Pending fill", "pending-line": "Pending border", "ok": "Verified text", "ok-bg": "Verified fill", "danger": "Errors",
         "mark": "Warm accent: the part in focus"}
CONSTANTS = [("brand", "Brand navy: ink and action on paper"), ("ice", "Interaction and focus"), ("ice-2", "Action on navy fields"),
             ("navy-950", "Deepest field (footer, dark navy)"), ("navy-900", "Navy field ground"), ("navy-800", "Raised surface on navy"),
             ("steel-050", "Metal highlight"), ("steel-200", "Metal light"), ("steel-400", "Metal mid"), ("steel-700", "Metal shadow"),
             ("brass", "Warm accent on paper"), ("brass-2", "Warm accent on navy")]

def kb(path):
    return round((OUT / path).stat().st_size / 1024, 1)

def render_system(perf):
    light, dark, band = parse_tokens()
    order = ["paper", "paper-2", "sheet", "ink", "ink-2", "ink-3", "line", "line-2", "line-3", "accent", "accent-ink", "signal", "tint", "tint-2", "pending", "pending-bg", "pending-line", "ok", "ok-bg", "danger", "mark"]
    constants = [(k, light[k], role) for k, role in CONSTANTS if k in light]
    def pal(t):
        return [(k, t[k], ROLES.get(k, "")) for k in order if k in t]
    bandfull = dict(dark, **band)
    pairs = [("ink / paper", "Body text", "ink", "paper", 4.5), ("ink-2 / paper", "Secondary text", "ink-2", "paper", 4.5),
             ("ink-3 / paper", "Labels, captions", "ink-3", "paper", 4.5), ("ink-3 / sheet", "Labels on raised surfaces", "ink-3", "sheet", 4.5),
             ("line / paper", "Links, drawing ink, sheet numbers", "line", "paper", 4.5), ("accent-ink / accent", "Button text", "accent-ink", "accent", 4.5),
             ("pending / pending-bg", "Pending badge text", "pending", "pending-bg", 4.5), ("ok / ok-bg", "Verified badge text", "ok", "ok-bg", 4.5),
             ("signal / sheet", "Focus ring (non-text)", "signal", "sheet", 3.0), ("line-2 / sheet", "Input borders (non-text)", "line-2", "sheet", 3.0),
             ("mark / paper", "Brass accent marks (non-text)", "mark", "paper", 3.0)]
    contrast = []
    for name, use, fg, bg, need in pairs:
        vals = []
        for t in (light, dark, bandfull):
            vals.append(ratio(t.get(fg, light[fg]), t.get(bg, light[bg])))
        contrast.append((name, use, vals, need))
    f = OUT / "assets" / "fonts"
    files = [("vazirmatn-Regular-fa.woff2", "Persian + basic Latin", "fa", kb("assets/fonts/vazirmatn-Regular-fa.woff2")),
             ("vazirmatn-Bold-fa.woff2", "Persian + basic Latin", "fa", kb("assets/fonts/vazirmatn-Bold-fa.woff2")),
             ("vazirmatn-Regular-latin.woff2", "Latin", "en", kb("assets/fonts/vazirmatn-Regular-latin.woff2")),
             ("vazirmatn-Bold-latin.woff2", "Latin", "en", kb("assets/fonts/vazirmatn-Bold-latin.woff2")),
             ("vazirmatn-Regular-arabic.woff2", "Persian only (unicode-range)", "en, when Persian text is present", kb("assets/fonts/vazirmatn-Regular-arabic.woff2")),
             ("jbmono-400.woff2", "Latin, digits, × ± °, no ligatures", "both", kb("assets/fonts/jbmono-400.woff2")),
             ("jbmono-600.woff2", "Latin, digits, × ± °, no ligatures", "both", kb("assets/fonts/jbmono-600.woff2"))]
    fonts = dict(files=files, fa_total=round(sum(x[3] for x in files if x[2] in ("fa", "both")), 1),
                 en_total=round(sum(x[3] for x in files if x[2] != "fa"), 1))
    scale = [("fs-6", "36→68 px", "بلبرینگ را با کدش پیدا کنید", "Find any bearing by its code", 700),
             ("fs-5", "32→50 px", "درون یک بلبرینگ", "Inside a bearing", 700),
             ("fs-4", "26→36 px", "جستجوی محصول", "Product explorer", 700),
             ("fs-3", "24 px", "با کارشناس صحبت کنید", "Talk to a specialist", 700),
             ("fs-1", "18 px", "متن مقدمه و عنوان کارت‌ها", "Lead text and card titles", 400),
             ("fs-0", "16 px", "متن اصلی برای خواندن روی موبایل، با فاصله سطر کافی.", "Body text for reading on a phone, with generous leading.", 400),
             ("fs--1", "14 px", "متن ثانویه، جدول‌ها و دکمه‌ها", "Secondary text, tables and buttons", 400),
             ("fs--2", "13 px · minimum", "برچسب‌ها و توضیح نقشه", "Labels and drawing captions", 400)]
    spacing = [("s1", "4px"), ("s2", "8px"), ("s3", "12px"), ("s4", "16px"), ("s5", "24px"), ("s6", "32px"), ("s7", "48px"), ("s8", "64px"), ("s9", "96px")]
    pf = {r["lang"] + r["profile"][:4]: r for r in perf} if perf else {}
    g = lambda k, f, d="—": (f(pf[k]) if k in pf else d)
    ledger = [
        ("Navy hero with text headline; the bearing is inline SVG, no raster", "0 KB of images", f"LCP element is the headline: {g('faSlow', lambda r: r['lcp'])} ms on Slow 4G, {g('fa3G-', lambda r: r['lcp'])} ms on 3G-class (fa)"),
        ("Metallic bearing rendering: 4 gradients, shared by hero, assembly and closing mark", "≈1.5 KB in the HTML", "Gradients defined once, referenced by id; no filters, blur or shadows"),
        ("Vazirmatn, two static weights, subset per language; Bold preloaded for the headline", f"fa {fonts['files'][0][3]} + {fonts['files'][1][3]} KB; en {fonts['files'][2][3]} + {fonts['files'][3][3]} KB (+{fonts['files'][4][3]} KB Persian-only subset)", "vs 111 KB for the full variable font; font swap shift ≈ 0"),
        ("JetBrains Mono for codes, dimensions and phones; subset, no ligatures", f"{fonts['files'][5][3]} KB per weight", "from 21.2 KB; codes stay unambiguous (0/O, 1/l)"),
        ("Catalogue as a real table in the HTML; filters toggle hidden rows", "0 requests per search", "Typing and filtering are local; rows are crawlable links"),
        ("Inline SVG + one symbol sprite (icons, 10 family glyphs)", f"whole fa page HTML {g('faSlow', lambda r: round(r['html']/1024,1))} KB compressed", "Glyphs defined once, reused in the rail, cards and every row"),
        ("Workspace code-split", f"{g('faSlow', lambda r: r['onDemandKB'])} KB on first open", "Nothing loads until a product is opened (pre-fetched on first hover)"),
        ("Scroll narrative from one registered value per stage (--sr, --p); JS fallback", "≈1.5 KB JS fallback", "Transforms and opacity only; fallback listeners detach off-screen"),
        ("Dark theme and navy fields from the same tokens", "≈1 KB CSS", "Follows the device setting; no toggle"),
        ("Total first view", f"fa {g('faSlow', lambda r: round(r['total']/1024,1))} KB in {g('faSlow', lambda r: r['requests'])} requests; en {g('enSlow', lambda r: round(r['total']/1024,1))} KB in {g('enSlow', lambda r: r['requests'])}", "Diagnostic only: no size or request budget (owner decisions D14/D17; the Gate 1 budget was ≤ 120 KB, ≤ 8 requests)"),
    ]
    sample_rows = [p for p in cat["products"] if p["slug"] in ("6205", "22220-e", "ucp-208")]
    def sheethead(n, name):
        return Markup(f'<div class="sheethead" aria-hidden="true"><span class="sheethead__no">{n:02d} <small>/ 10</small></span><span class="sheethead__name">{html.escape(name)}</span><span class="sheethead__ticks"></span><span class="sheethead__ref">PC-{n:02d}</span></div>')
    out = env.get_template("system.html.j2").render(palettes=[("Light (default)", pal(light)), ("Dark (follows the device setting)", pal(dark)), ("Navy field (hero, Anatomy, contact, footer — both themes)", pal(bandfull))],
        constants=constants, contrast=contrast, fonts=fonts, scale=scale, spacing=spacing, ledger=ledger, families=cat["families"], fam=fam,
        sample_rows=sample_rows, sheethead=sheethead, t=S["en"])
    (OUT / "system.html").write_text(out, encoding="utf-8")
    return fonts, ledger

def render_hub(perf, fonts):
    qa = ROOT.parent / "qa"
    pv1 = {(r["lang"], r["profile"].split(" (")[0]): r for r in json.loads((qa / "perf-v1-re.json").read_text())}
    pv2 = {(r["lang"], r["profile"].split(" (")[0]): r for r in json.loads((qa / "perf-v2.json").read_text())}
    keys = [k for k in [("fa", "Slow 4G"), ("fa", "3G-class"), ("en", "Slow 4G"), ("en", "3G-class")] if k in pv1 and k in pv2]
    kb1 = lambda v: f"{v:.1f} KB"
    rows = [("First-view transfer", "≤ 120 KB", lambda r: round(r["total"] / 1024, 1), kb1, True),
            ("Requests", "≤ 8", lambda r: r["requests"], str, True),
            ("HTML (incl. inline SVG, critical CSS in V2)", "≤ 30 KB", lambda r: round(r["html"] / 1024, 1), kb1, True),
            ("CSS file (V2: deferred, not render-blocking)", "≤ 14 KB", lambda r: round(r["css"] / 1024, 1), kb1, True),
            ("JS, initial", "≤ 12 KB", lambda r: round(r["js"] / 1024, 1), kb1, True),
            ("Fonts", "≤ 60 KB", lambda r: round(r["fonts"] / 1024, 1), kb1, True),
            ("First paint = LCP (hero headline)", "≤ 1.8 s", lambda r: round(r["lcp"] / 1000, 2), lambda v: f"{v:.2f} s", True),
            ("CLS", "≤ 0.05", lambda r: r["cls"], lambda v: f"{v:.3f}", True),
            ("Loaded on first product open", "≤ 15 KB", lambda r: r["onDemandKB"], kb1, True)]
    inp = json.loads((qa / "inp-v1v2.json").read_text())
    pairs = [("fa-d-hero", "d1-fa-hero", "Persian hero, desktop", False), ("fa-d-exp", "d3-fa-workspace", "Explorer with the workspace open", False),
             ("fa-d-ea", "d4-ea-4", "Exploded Assembly, mid-sequence", False), ("fa-d-con", "d5-fa-contact", "Contact", False),
             ("en-d-dark-exp", "d11-dark-workspace", "Dark mode, workspace", False), ("fa-d-rm-ea", "d12-rm-ea", "Reduced motion, Anatomy", False),
             ("fa-m-hero", "d6-m-hero", "Mobile hero", True), ("fa-m-sheet", "d8-m-sheet", "Mobile bottom sheet", True)]
    D = [
        (1, "Persian hero, desktop", "Navy field, two columns. Statement, dominant search, four example codes and a photo-by-WhatsApp link on one side; a deep-groove ball bearing rendered half in restrained metal, half as a line drawing (section A–A, ØD and Ød) on the other. The rolling elements turn a few degrees with scroll and lean toward a fine pointer; the facts strip shows verified address, hours and numbers.",
         [("d1-fa-hero", "Persian hero at 1440 × 900")], 1),
        (2, "Product Explorer, desktop", "A technical catalogue rather than product cards: drawing glyph, designation, family, d / D / B (or the family's own symbols), provenance. The family rail shows counts (empty families stay visible but disabled), with bore segments and a verified-only toggle. An unknown code keeps the query and offers \u201cAsk about this code\u201d.",
         [("d2-fa-explorer", "Catalogue, rail with counts, filters"), ("d2-fa-ask", "6208 is not listed: the query is kept and handed to a person")], 2),
        (3, "Workspace open, desktop", "A direct hit from the hero search lands on the result with the whole panel and its actions in view. Designation first, then a three-cell d / D / B readout, three tabs, and a drawing generated from the dimensions before any imagery. The footer always holds WhatsApp, Call and Inquiry.",
         [("d3-fa-workspace", "Hero search \u201c22220 E\u201d \u2192 result + workspace"), ("d3-fa-workspace-specs", "Specifications: sourced values, pending ratings")], 2),
        (4, "Exploded Assembly, desktop", "Confined to Anatomy. One scroll-driven value moves through six stages; the stage strip, balloons and parts list stay in step. The last image is the JavaScript fallback for browsers without scroll timelines.",
         [("d4-ea-1", "01 Assembled (metal)"), ("d4-ea-2", "02 Separated (sections)"), ("d4-ea-3", "03 Dimensioned (Ød, ØD, B)"), ("d4-ea-4", "04 Explained (balloon + row)"), ("d4-ea-5", "05 Related families"), ("d4-ea-6", "06 Resolved"), ("d4-ea-fallback", "Fallback driver (no scroll timelines)")], 3),
        (5, "Contact, desktop", "A navy field with a large WhatsApp button (number in Latin digits), the verified channels list and a small closing bearing, next to the inquiry form.",
         [("d5-fa-contact", "Contact sheet")], 1),
        (6, "Persian hero and search, mobile", "The search sits inside the first viewport, above the bearing; the dock holds Call, WhatsApp and Search.", [("d6-m-hero", "390 × 844")], 4),
        (7, "Explorer, mobile", "Rows become two-line records; the rail scrolls sideways; the bore segments fit the width; the ask panel stacks.", [("d7-m-explorer", "Catalogue"), ("d7-m-ask", "Ask about this code")], 4),
        (8, "Bottom sheet, mobile", "Sized to its content (max 88% height), one scroll region, readable values first, actions in reach; Esc, scrim, drag-down or close dismiss it, and the dock hides meanwhile.", [("d8-m-sheet", "22220 E")], 4),
        (9, "Exploded Assembly, mobile", "A shorter track (190 vh) with a numbers-only stage strip and the active stage name under it; larger dimension labels.", [("d9-m-ea-a", "Separated"), ("d9-m-ea-b", "Explained")], 4),
        (10, "English LTR validation", "Same template and tokens, mirrored layout, drawings and codes never mirrored. Workspace shows a product with technical data pending.", [("d10-en-hero", "Hero"), ("d10-en-workspace", "UCP 208, data pending"), ("d10-en-ea", "Anatomy"), ("d10-en-m-hero", "Mobile")], 4),
        (11, "Dark mode", "Automatic from the device setting, no toggle: sheets turn deep blue, navy fields one step deeper, no glows.", [("d11-dark-hero", "Hero"), ("d11-dark-workspace", "Workspace"), ("d11-dark-ea", "Anatomy"), ("d11-dark-m-explorer", "Mobile, English")], 4),
        (12, "Reduced motion", "No sticky track and no animation: Anatomy shows the complete dimensioned technical state with every balloon and the parts list.", [("d12-rm-ea", "Desktop"), ("d12-rm-m-ea", "Mobile")], 3),
        (13, "Admin Control Center wireframes", "Low/medium fidelity, Persian RTL, Nova tokens, dense and utilitarian; English notes map each element to the data model. Not implemented.",
         [("d13-dashboard", "A1 Dashboard"), ("d13-products", "A2 Products"), ("d13-editor", "A3 Product editor"), ("d13-media", "A4 Media library"), ("d13-sections", "A5 Sections / content"), ("d13-inquiries", "A6 Inquiries"), ("d13-publish", "A7 Publish"), ("d13-company", "A8 Company / settings"), ("d13-mobile", "A9 Mobile triage")], 3),
    ]
    gate = [("G2-1", "Visual direction V2", "Drawing Sheet language on paper sheets × deep-navy presentation fields × restrained metal; colour roles as listed", '<a href="fa.html">fa</a> · <a href="en.html">en</a>'),
            ("G2-2", "Palette and tokens", "Brand #2F338A, ice #2196F3 / #90CAF9, navy 950/900/800, steel for metal only, one brass accent; light, dark (automatic) and navy-field contexts; measured contrast", '<a href="system.html#color">system#color</a>'),
            ("G2-3", "Typography and digits", "Vazirmatn 400/700 with display sizes up to 68 px; JetBrains Mono for codes, dimensions, units, drawing references and phones (Latin digits in both languages); Persian digits in prose and counts; 13 px minimum", '<a href="system.html#type">system#type</a>'),
            ("G2-4", "Hero", "Two columns; search dominates; split metal / line bearing with a subtle scroll and pointer response; mobile search in the first viewport", '<a href="fa.html">fa</a>'),
            ("G2-5", "Product Explorer", "Catalogue table, family rail with counts, bore segments, verified toggle, \u201cAsk about this code\u201d", '<a href="fa.html#explorer">fa#explorer</a>'),
            ("G2-6", "Product Workspace", "Docked panel (desktop) / content-sized bottom sheet (mobile); readout; three tabs; drawing before imagery; direct-hit landing", '<a href="fa.html#p-22220-e">fa#22220 E</a>'),
            ("G2-7", "Exploded Assembly", "Six stages in Anatomy only; 320 vh desktop / 190 vh mobile; JS fallback; static state under reduced motion", '<a href="fa.html#anatomy">fa#anatomy</a>'),
            ("G2-8", "Dark mode", "Automatic, no toggle, no glow", "OS setting"),
            ("G2-9", "Reduced motion", "Complete static technical state; no sticky track", "OS setting"),
            ("G2-10", "RTL / LTR rules", "Mirror layout, never drawings or codes; Latin islands on Persian pages set in mono", '<a href="system.html#bidi">system#bidi</a>'),
            ("G2-11", "Admin Control Center IA", "Eight core screens + mobile triage; Draft \u2192 Publish; two roles; entity map", '<a href="admin.html">admin.html</a>'),
            ("G2-12", "Performance approach", "Inline critical CSS + deferred rest; Persian subsets without Latin letters; lazy lower sections; all Gate 1 budgets met", '<a href="#perf">#perf</a>')]
    questions = [
        "<b>Latin islands in mono on Persian pages.</b> English family names, the EN switch and source names are set in JetBrains Mono on the Persian page. This is what lets the Persian font drop its Latin letters (−8.6 KB). Approve, or keep Vazirmatn Latin at that cost?",
        "<b>Hero bearing.</b> A generic deep-groove ball bearing drawn from scratch: no photo, no brand. Is this acceptable as the signature image until real product photography exists?",
        "<b>Admin language and roles.</b> Persian-only admin UI for v1; only the Owner can set \u201cVerified for indexing\u201d; CSV import of the old catalogue (needs an export). Please confirm all three.",
        "<b>Inquiry notifications:</b> which email address should receive them? SMTP availability comes from the host probe.",
        "<b>Still open from Gate 1:</b> exact domain names, host probe results, logo SVG, Thursday/Friday hours (and whether Saturday–Wednesday is correct for 08:00–16:00), verified brands and sources, map coordinates.",
    ]
    # ---- correction round (items 1–12) ----
    gate[5] = ("G2-6", "Product Workspace", "Docked panel (desktop) / content-sized bottom sheet (mobile); readout; three tabs; drawing before imagery; direct-hit landing. Corrected: one stable shell (region / modal dialog) with loading, ready, data-error and module-error states; Close in every state; real Retry; focus in, contained and restored; Escape contract", '<a href="fa.html#p-22220-e">fa#22220 E</a>')
    gate[9] = ("G2-10", "RTL / LTR rules", "Mirror layout, never drawings or codes; Latin islands on Persian pages set in mono for technical and utility Latin only (decision A); codes inside Persian sentences are bidi-isolated", '<a href="system.html#bidi">system#bidi</a>')
    gate[10] = ("G2-11", "Admin Control Center IA", "Eight core screens (desktop / tablet-first, 1024 px and wider) + A9 mobile triage; Draft → Publish; two roles; Persian-only UI; Owner-only “Verified for indexing” (decision C)", '<a href="admin.html">admin.html</a>')
    gate += [("G2-13", "Product identity and variants", "Requested designation kept verbatim from search to Admin; spellings separate from variants; base-series match needs confirmation; Product + light ProductVariant model (option B)", '<a href="admin.html#variants">admin#variants</a>'),
             ("G2-14", "Per-value provenance", "Each value: source key, status (verified / pending), checked date; mixed sources shown per value; pending stays pending", '<a href="fa.html#p-6206">fa#6206</a>'),
             ("G2-15", "Alt-text publishing rule", "ALT-INF: informative image in a language release needs alt in that language, otherwise Publish blocks the affected content; decorative → empty alt; unused media never blocks; same rule in A4 and A7", '<a href="admin.html#media">A4</a> · <a href="admin.html#publish">A7</a>'),
             ("G2-16", "Catalogue migration plan (Phase 3)", "Audit → repeatable validated importer → keep only trustworthy provenance → safe pending defaults → reconciliation report; CSV is one input path; nothing migrated yet", '<a href="admin.html#migration">admin#migration</a>')]
    report = [
        (1, "Typing an unknown code (6208) after opening 6205 left the 6205 workspace, its URL hash and its CTAs in place. A slow earlier response could overwrite a newer selection.",
         "One active selection (product, requested designation, match type, token) drives the row highlight, workspace, URL, copy/link, WhatsApp and inquiry. A query or filter that hides the open product closes the workspace and clears the hash and highlight. Each selection aborts the previous request; stale responses are dropped by token. Loading shows the new product’s identity and its own CTAs only.", "1a–1c"),
        (2, "With a family filter on, the explorer said “22220 E is not in the catalogue”: the verdict came from the filtered subset.",
         "Hero search and example codes are global: conflicting filters are cleared and the reset is announced. The explorer separates <b>not in catalogue</b> (checked against the whole catalogue) from <b>hidden by filters</b>, with a Show it action; hidden partial matches show a count and a reset. Tested with family, bore and verified-only, including UCP 208.", "2a–2e"),
        (3, "6205-2RS and 6205-ZZ were aliases of 6205: the result, workspace, link and inquiry all said “6205”.",
         "Spellings (22220E → 22220 E) and variants (6205-2RS, 6205-ZZ) are separate keys. The requested designation survives search → result → workspace → copy/link (<span class=\"mono\">#p-6205:6205-2RS</span>) → WhatsApp → inquiry → the Admin A6 match record. Variants and unlisted suffixes show “Base series match — requested variant requires confirmation”. No variant data invented. Model: Product + light ProductVariant (option B), see <a href=\"admin.html#variants\">admin#variants</a>.", "3, 3b, 3c"),
        (4, "At 320 px the sheet footer showed WhatsApp and a Call icon only; the inquiry form was not offered.",
         "Footer is two rows at every width: WhatsApp full width, then Call and Inquiry form, unclipped at 320 and 390 px. Inquiry closes the sheet, scrolls to the form, focuses the first empty required field and prefills the full designation (e.g. 6205-2RS) with its match note. One scroll region.", "4a, 4b"),
        (5, "The panel was built on open; a failed product load showed only “Couldn’t load. Try again.” with no product, Close or CTAs; the mobile sheet was an aside with role=dialog (axe: aria-allowed-role).",
         "Static shell: <span class=\"mono\">role=region</span> on desktop, modal dialog on mobile with an inert background. Close in loading, ready, data-error and module-error states. Retry re-fetches the data or re-imports the module (cache-busted, since failed imports are cached). Focus goes to the title, stays inside the sheet on Tab / Shift+Tab, and returns to the row or search field. Escape: mobile always closes; desktop closes when focus is inside the panel. Cached reopen makes one request and lands focus on the title every time (five in a row on a phone). Resizing across the breakpoint with a product open swaps panel and sheet and keeps focus.", "5a–5k"),
        (6, "A4 marked missing English alt as required (error); A7 listed the same image as a non-blocking warning; the “without alt” count included an unused file.",
         "One rule, <span class=\"mono\">ALT-INF</span>, in A1, A4 and A7: an informative image in a language release needs alt in that language, or Publish blocks the affected content. Same three repairs everywhere: write alt, mark decorative (outputs <span class=\"mono\">alt=\"\"</span>), or remove it from that release. Unused media (<span class=\"mono\">ALT-UNUSED</span>) is information only.", "6"),
        (7, "Readout labels 12 px; Admin headers, badges, helper text and chips 11.5–12.5 px; workspace drawing labels shrank to about 8 px on phones.",
         "Meaningful labels are 13 px (readout, Admin headers, badges, helper text, chips; only pins and file names stay at 12 px). Workspace drawing labels are larger on desktop and larger again on mobile; mobile Exploded Assembly labels render at about 12.5 px at 320 px and 15.6 px at 390 px. The optional enlarged view was not needed.", "visual"),
        (8, "English header at 320 px: page 324 px wide, the language switch clipped.",
         "Below 380 px the header gap is 12 px and the mark 28 px; no text was shrunk. Measured with <span class=\"mono\">documentElement.clientWidth</span>: no overflow on any page at 320 or 390 px.", "8"),
        (9, "Admin screens had no scope label.",
         "A1–A8 labelled “Desktop / tablet-first wireframe · 1024 px and wider”; A9 labelled as the mobile workflow. No mobile Admin built.", "visual"),
        (10, "One source per product; no per-value status.",
         "Every value carries a source key and a status; sources carry a checked date. 6206 shows Source A (d, D, B), Source B (minimum chamfer r1,2 = 1 mm, read from the cited page) and pending load ratings. Mixed sources show per-value chips in the readout and a Source column plus legend in Specifications. No values fabricated.", "visual"),
        (11, "—",
         "Phase 3 migration plan at <a href=\"admin.html#migration\">admin#migration</a>: audit → repeatable, validated importer (dry run, idempotent) → keep only trustworthy provenance → everything else enters as pending and not indexed → reconciliation report. CSV is one input path, not the only one. Nothing migrated.", "doc"),
        (12, "—",
         "Re-verified in Persian and English: scripted checks, axe, dark mode, reduced motion, 320 / 390 px; performance re-measured with FCP and LCP reported separately.", "below"),
    ]
    corr_pairs = [("p1-before", "p1-after", "Unknown code after another product was open", False),
                  ("p2-before", "p2-after", "Exact code hidden by an old filter", False),
                  ("p3-before", "p3-after", "Requested variant 6205-2RS", False),
                  ("p7-before", "p7-after", "Product data failure", False),
                  ("adm-media-before", "adm-media-after", "A4 Media alt rule", False),
                  ("adm-publish-before", "adm-publish-after", "A7 Publish alt rule", False),
                  ("p4-before", "p4-after", "Mobile sheet CTAs at 320 px", True),
                  ("p5-before", "p5-after", "English header at 320 px", True),
                  ("p6-before", "p6-after", "Mobile Exploded Assembly labels", True)]
    corr_new = [("a-prov-fa", "6206: per-value provenance, Sources A and B, pending ratings"), ("a-prov-en-m", "Provenance on a phone (English)"),
                ("a-series-fa", "6205-2RSH: base series match, confirmation needed"), ("a-loading-en", "Loading: the new product’s identity and CTAs only"),
                ("a-sheet-variant-fa", "Sheet for 6205-2RS at 390 px"), ("a-inquiry-fa", "Inquiry prefilled with 6205-2RS after the sheet closes"),
                ("a-dark-fa", "Dark mode, desktop"), ("a-dark-en-m", "Dark mode, 6205-ZZ on a phone"),
                ("a-rm-sheet-fa", "Reduced motion: sheet"), ("a-rm-ea-fa", "Reduced motion: Anatomy"),
                ("adm-editor-after", "A3 editor: spellings and ProductVariant rows"), ("adm-inquiries-after", "A6 inquiries: requested designation and match type"),
                ("adm-dashboard-after", "A1 dashboard: same ALT rules"), ("adm-variants", "Variant data-model policy"), ("adm-migration", "Migration plan")]
    sc = json.loads((qa / "scenarios.json").read_text())
    groups, other = {}, []
    for r in sc:
        if r["lang"] in ("fa", "en"):
            g = groups.setdefault((r["id"], r["name"]), {"id": r["id"], "name": r["name"], "fa": "—", "en": "—"})
            g[r["lang"]] = "✓" if r["ok"] else "✗"
        else:
            other.append(r)
    qa_rows = list(groups.values())
    lay = [r for r in other if r["id"] == "8"]
    if lay:
        ok = all(r["ok"] for r in lay)
        qa_rows.append({"id": "8", "name": f"Design system, Admin wireframes and this hub at 320 and 390 px: no horizontal overflow ({sum(r['ok'] for r in lay)}/{len(lay)})", "fa": "✓" if ok else "✗", "en": "✓" if ok else "✗"})
    for r in other:
        if r["id"] != "8":
            qa_rows.append({"id": r["id"], "name": r["name"] + " (Persian-only Admin)", "fa": "✓" if r["ok"] else "✗", "en": "n/a"})
    qa_pass, qa_total = sum(r["ok"] for r in sc), len(sc)
    def axe_s(v):
        return "0 issues" if not v else ", ".join(f"{x['id']} ×{x['n']}" for x in v)
    ab = {r["name"]: r["violations"] for r in json.loads((qa / "axe2-v2a.json").read_text())}
    axe_rows = [(r["name"], axe_s(ab.get(r["name"], [])) if r["name"] in ab else "—", axe_s(r["violations"])) for r in json.loads((qa / "axe2.json").read_text())]
    load = lambda f: {(r["lang"], r["profile"].split(" (")[0]): r for r in json.loads((qa / f).read_text())}
    pv2a, pv2c, pun = load("perf-v2a-min.json"), load("perf-v2c.json"), load("perf-v2a.json")
    v2a_unmin = f"fa {pun[('fa', 'Slow 4G')]['total'] / 1024:.1f} KB · en {pun[('en', 'Slow 4G')]['total'] / 1024:.1f} KB (JS {pun[('fa', 'Slow 4G')]['js'] / 1024:.1f} KB)"
    ms = lambda v: f"{v} ms"
    perf_rows2 = [("First-view transfer", "≤ 120 KB", lambda r: round(r["total"] / 1024, 1), kb1, True),
                  ("Requests", "≤ 8", lambda r: r["requests"], str, True),
                  ("HTML (incl. inline SVG and critical CSS)", "≤ 30 KB", lambda r: round(r["html"] / 1024, 1), kb1, True),
                  ("CSS file (deferred)", "≤ 14 KB", lambda r: round(r["css"] / 1024, 1), kb1, True),
                  ("JS, initial (minified)", "≤ 12 KB", lambda r: round(r["js"] / 1024, 1), kb1, True),
                  ("Fonts", "≤ 60 KB", lambda r: round(r["fonts"] / 1024, 1), kb1, True),
                  ("FCP", "≤ 1.8 s", lambda r: r["fcp"], ms, True),
                  ("LCP", "≤ 2.5 s", lambda r: r["lcp"], ms, True),
                  ("CLS", "≤ 0.05", lambda r: r["cls"], lambda v: f"{v:.3f}", True),
                  ("Loaded on first product open", "≤ 15 KB", lambda r: r["onDemandKB"], kb1, True)]
    inp2 = json.loads((qa / "inp-v2a-v2c.json").read_text())
    decisions = [("A", "Latin islands: approved", "JetBrains Mono for technical and utility Latin on Persian pages: codes, units, references, source keys, the EN switch and short English names. The rule stays narrow: longer English text on a Persian page takes <span class=\"mono\">.en-prose</span> and uses the system sans (no extra download). Codes inside Persian sentences are bidi-isolated. Documented at <a href=\"system.html#bidi\">system#bidi</a>."),
                 ("B", "Hero bearing: approved", "Unchanged."),
                 ("C", "Admin: Persian-only UI; Owner-only “Verified for indexing”; catalogue import approved in principle", "Applied in the wireframes; import button renamed “ورود کاتالوگ…” (not CSV-only); migration rules at <a href=\"admin.html#migration\">admin#migration</a>."),
                 ("D", "Inquiry notification email: TBD", "A8 shows it as an Admin setting, never hardcoded; SMTP to be confirmed by the host probe."),
                 ("E", "Remaining inputs stay open", "Listed below; none blocks this round.")]
    questions = ["<b>Domains</b> (.ir and .com names).", "<b>Host probe</b> results (PHP, MariaDB, SMTP, cron, gzip/Brotli).", "<b>Logo SVG</b> (the concentric mark remains a placeholder).",
                 "<b>Exact hours</b>: Thursday/Friday, and whether Saturday–Wednesday 08:00–16:00 is correct.", "<b>Verified brands and sources</b> for the catalogue.",
                 "<b>Map coordinates</b> for No. 433 Dardasht St.", "<b>Inquiry notification email</b> (decision D)."]
    # ---- Gate 2 closure (after the takeover audit, 2026-10-04): T-1 fixed; owner decisions D14–D18
    gate[11] = ("G2-12", "Performance approach", "Inline critical CSS + deferred rest; Persian subsets without Latin letters; lazy lower sections. The lab measurements are healthy; under owner decisions D14/D17 they are diagnostics, not budgets or Gate criteria", '<a href="#perf">#perf</a>')
    gate[12] = ("G2-13", "Product identity and variants", "Requested designation kept verbatim from search to Admin; spellings separate from variants; a listed variant or an unlisted suffix is a base-series match that needs confirmation; the plain designation of a listed suffixed one (6305 → 6305-2RS, 22220 → 22220 E) is a related match, and the listed one is never called its base (T-1, fixed); Product + light ProductVariant model (option B)", '<a href="admin.html#variants">admin#variants</a>')
    report.append(("T-1", "Found at the takeover audit: asking for 6305 (only 6305-2RS listed) or 22220 (only 22220 E listed) said “6305 is listed as base series 6305-2RS”; the workspace, WhatsApp text and inquiry also called 6305-2RS the base series and 6305 a variant. A half-typed 6305-2 got the same claim.",
                   "A separate <span class=\"mono\">related</span> match type: the listed suffixed designation is named as a suffixed variant of what was asked, never its base (“Related match — requested designation requires confirmation”), in the explorer, workspace, WhatsApp text and inquiry. It needs the whole base part, so a half-typed code matches nothing. A listed variant and an unlisted suffix each get their own explanation line. The requested designation stays exact; no data was added.", "3, 3t, 3d, 3e"))
    corr_pairs.append(("t1-before", "t1-after", "Plain 6305 asked, only 6305-2RS listed (T-1)", False))
    decisions.append(("D14–D18", "Owner decisions after the takeover audit", "No size or request budget; performance measurements are diagnostics only, so G2-12 is not judged against numbers and the A7 page-budget check is removed (D14, D17). Priority order (D15). Dependency discipline replaces the zero-third-party rule (D16). The local governance documents are authoritative (D18). Recorded in <span class=\"mono\">00</span> and <span class=\"mono\">04</span>."))
    questions += ["<b>Old catalogue export or repository access</b> for the Phase 3 migration audit.", "<b>Provider backup retention</b> and restore procedure."]
    # T-1 focused re-run (designation and variant checks only) on the fixed build, and the same checks on the build before the fix
    read = lambda f: json.loads((qa / f).read_text(encoding="utf-8")) if (qa / f).exists() else []
    t1, t1_before = read("scenarios-designation.json"), read("scenarios-designation-before-t1.json")
    t1_groups = {}
    for r in t1:
        g = t1_groups.setdefault((r["id"], r["name"]), {"id": r["id"], "name": r["name"], "fa": "—", "en": "—"})
        g[r["lang"]] = "✓" if r["ok"] else "✗"
    t1_rows, t1_pass, t1_total = list(t1_groups.values()), sum(r["ok"] for r in t1), len(t1)
    t1_before_fail = sum(not r["ok"] for r in t1_before)
    out = env.get_template("hub.html.j2").render(perf_v1=pv1, perf_v2=pv2, perf_keys=keys, perf_rows=rows, inp=inp, pairs=pairs,
                                                 deliverables=D, gate=gate, questions=questions, report=report, corr_pairs=corr_pairs,
                                                 corr_new=corr_new, qa_rows=qa_rows, qa_pass=qa_pass, qa_total=qa_total, axe_rows=axe_rows,
                                                 v2a_unmin=v2a_unmin, perf_rows2=perf_rows2, pv2a=pv2a, pv2c=pv2c, inp2=inp2, decisions=decisions,
                                                 t1_rows=t1_rows, t1_pass=t1_pass, t1_total=t1_total, t1_before_fail=t1_before_fail, t1_before_total=len(t1_before))
    (OUT / "index.html").write_text(out, encoding="utf-8")

if __name__ == "__main__":
    pfile = ROOT.parent / "qa" / "perf-v2.json"
    if not pfile.exists() or pfile.stat().st_size < 10:
        pfile = ROOT.parent / "qa" / "perf-final.json"
    perf = json.loads(pfile.read_text()) if pfile.exists() else []
    fonts, _ = render_system(perf)
    if (ROOT / "hub.html.j2").exists():
        render_hub(perf, fonts)
    render_admin()
    print("system + hub + admin built")
