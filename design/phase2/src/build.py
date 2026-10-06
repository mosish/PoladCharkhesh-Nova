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

AR2FA = str.maketrans({"ي": "ی", "ك": "ک", "‌": ""})
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
    names = [p["code"], *p["aliases"], fam[p["family"]]["fa"], fam[p["family"]]["en"]]
    p["key"] = "|".join(norm(n) for n in names)
    p["bore"] = p["dims"].get("d", p["dims"].get("Fw", ""))
    p["dims_show"] = dims_show(p)

def wa(text):
    from urllib.parse import quote
    return f"https://wa.me/{CONTACT['wa']}?text={quote(text)}"

hero_balls = [(round(150 + 75 * math.cos(k * 2 * math.pi / 9 - math.pi / 2), 1), round(180 + 75 * math.sin(k * 2 * math.pi / 9 - math.pi / 2), 1)) for k in range(9)]
bom_range = [(round(26 + i * 8.7, 1), round(26 + (i + 1) * 8.7, 1)) for i in range(6)]

env = Environment(loader=FileSystemLoader(str(ROOT)), autoescape=True, trim_blocks=True, lstrip_blocks=True)

def render_page(lang):
    other = "en" if lang == "fa" else "fa"
    t = S[lang]
    num = (lambda n: fa(n)) if lang == "fa" else (lambda n: str(n))
    def sheet(n):
        return num(f"{n:02d}")
    def sheethead(n, name):
        return Markup(f'<div class="sheethead" aria-hidden="true"><span class="sheethead__no">{sheet(n)} <small>/ {sheet(10)}</small></span>'
                      f'<span class="sheethead__name">{html.escape(name)}</span><span class="sheethead__ticks"></span><span class="sheethead__ref">PC-{n:02d}</span></div>')
    c = dict(CONTACT, **CONTACT_SHOW[lang])
    js_strings = dict(JS[lang], lang=lang, wa=CONTACT["wa"], tel=CONTACT["tel1"], checked=cat["checked"])
    tpl = env.get_template("page.html.j2")
    out = tpl.render(lang=lang, dir="rtl" if lang == "fa" else "ltr", other_lang=other, other_file=f"{other}.html",
                     subset="fa" if lang == "fa" else "latin", t=t, c=c, families=cat["families"], fam=fam,
                     products=cat["products"], examples=["6205", "22220 E", "UCP 208", "30208"], num=num, sheet=sheet,
                     sheethead=sheethead, wa=wa, hero_balls=hero_balls, bom_range=bom_range,
                     js_strings=Markup(json.dumps(js_strings, ensure_ascii=False).replace("</", "<\\/")))
    (OUT / f"{lang}.html").write_text(out, encoding="utf-8")

def product_json(p):
    f = p["family"]
    order = {"needle": ["Fw", "D", "C"], "thrust": ["d", "D", "H"], "tapered": ["d", "D", "T"], "seal": ["d", "D", "b"], "unit": ["d"]}.get(f, ["d", "D", "B"])
    rows = []
    for k in order:
        if k in p["dims"]:
            lab = DIM_LABELS_BY_FAMILY.get(f, {}).get(k, DIM_LABELS[k])
            rows.append({"s": k, "fa": lab[0], "en": lab[1], "v": p["dims"][k]})
    kind = "unit" if f == "unit" else "seal" if f == "seal" else "radial"
    return {
        "slug": p["slug"], "code": p["code"], "family": f, "status": p["status"], "aliases": p["aliases"],
        "fam": {k: fam[f][k] for k in ("fa", "en", "def_fa", "def_en", "glyph")},
        "dims": rows, "dims_note": p.get("dims_note"), "source": p["source"], "checked": cat["checked"],
        "ratings": [{"s": r[0], "fa": r[1], "en": r[2]} for r in RATINGS[kind]],
    }

if __name__ == "__main__":
    (OUT / "data" / "p").mkdir(parents=True, exist_ok=True)
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
    return block(":root {"), block(':root[data-theme="dark"] {'), block(".band {")

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
         "pending": "Pending text", "pending-bg": "Pending fill", "pending-line": "Pending border", "ok": "Verified text", "ok-bg": "Verified fill", "danger": "Errors"}

def kb(path):
    return round((OUT / path).stat().st_size / 1024, 1)

def render_system(perf):
    light, dark, band = parse_tokens()
    order = ["paper", "paper-2", "sheet", "ink", "ink-2", "ink-3", "line", "line-2", "line-3", "accent", "accent-ink", "signal", "tint", "tint-2", "pending", "pending-bg", "pending-line", "ok", "ok-bg", "danger"]
    def pal(t):
        return [(k, t[k], ROLES.get(k, "")) for k in order if k in t]
    bandfull = dict(dark, **band)
    pairs = [("ink / paper", "Body text", "ink", "paper", 4.5), ("ink-2 / paper", "Secondary text", "ink-2", "paper", 4.5),
             ("ink-3 / paper", "Labels, captions", "ink-3", "paper", 4.5), ("ink-3 / sheet", "Labels on raised surfaces", "ink-3", "sheet", 4.5),
             ("line / paper", "Links, drawing ink, sheet numbers", "line", "paper", 4.5), ("accent-ink / accent", "Button text", "accent-ink", "accent", 4.5),
             ("pending / pending-bg", "Pending badge text", "pending", "pending-bg", 4.5), ("ok / ok-bg", "Verified badge text", "ok", "ok-bg", 4.5),
             ("signal / sheet", "Focus ring (non-text)", "signal", "sheet", 3.0), ("line-2 / sheet", "Input borders (non-text)", "line-2", "sheet", 3.0)]
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
    scale = [("fs-5", "32→52 px", "قطعه را با کدش پیدا کنید", "Find the part by its code", 700),
             ("fs-4", "28→40 px", "جستجوی محصول", "Product explorer", 700),
             ("fs-3", "28 px", "درون یک بلبرینگ", "Inside a bearing", 700),
             ("fs-1", "18 px", "متن مقدمه و عنوان کارت‌ها", "Lead text and card titles", 400),
             ("fs-0", "16 px", "متن اصلی برای خواندن روی موبایل، با فاصله سطر کافی.", "Body text for reading on a phone, with generous leading.", 400),
             ("fs--1", "14 px", "متن ثانویه، جدول‌ها و دکمه‌ها", "Secondary text, tables and buttons", 400),
             ("fs--2", "12 px", "برچسب‌ها و توضیح نقشه", "Labels and drawing captions", 400)]
    spacing = [("s1", "4px"), ("s2", "8px"), ("s3", "12px"), ("s4", "16px"), ("s5", "24px"), ("s6", "32px"), ("s7", "48px"), ("s8", "64px"), ("s9", "96px")]
    pf = {r["lang"] + r["profile"][:4]: r for r in perf} if perf else {}
    g = lambda k, f, d="—": (f(pf[k]) if k in pf else d)
    ledger = [
        ("Paper and ink palette; no raster image in the first screen", "0 KB of images", f"LCP element is the headline text: {g('faSlow', lambda r: r['lcp'])} ms on Slow 4G, {g('fa3G-', lambda r: r['lcp'])} ms on 3G-class (fa)"),
        ("Vazirmatn, two static weights, subset per language", f"fa {fonts['files'][0][3]} + {fonts['files'][1][3]} KB; en {fonts['files'][2][3]} + {fonts['files'][3][3]} KB (+{fonts['files'][4][3]} KB Persian-only subset)", "vs 111 KB for the full variable font; layout shift from font swap 0.000–0.001"),
        ("JetBrains Mono for codes, subset, ligatures removed", f"{fonts['files'][5][3]} KB per weight", "from 21.2 KB; codes stay unambiguous (0/O, 1/l)"),
        ("Drafting grid as CSS gradients, only inside drawing frames", "0 bytes", "Smaller painted area than a page-wide grid"),
        ("Inline SVG drawings + one symbol sprite (icons, 10 family glyphs)", f"inside the HTML: {g('faSlow', lambda r: round(r['html']/1024,1))} KB compressed for the whole fa page", "Glyphs defined once, reused in 10 cards and every result row"),
        ("No shadows, blur, gradients (except the pending hatch)", "0", "Cheap paint and no backdrop-filter on low-end phones"),
        ("Sticky header and workspace with position: sticky", "0 JS", "No scroll listeners for layout"),
        ("Explorer list rendered in HTML; filtering toggles hidden rows", "0 requests per search", "Typing and filtering are local"),
        ("Workspace code-split", f"{g('faSlow', lambda r: ', '.join(r['onDemand']))} bytes on first open", "Nothing loads until a product is opened (pre-fetched on first hover)"),
        ("Scroll narrative via CSS scroll timelines; JS fallback", "≈1 KB JS fallback", "Transforms on 8 SVG groups; the fallback listener is detached while off-screen"),
        ("content-visibility on three lower sections", "0", "Skips rendering work below the fold"),
        ("Blueprint dark theme from the same tokens", "≈0.6 KB CSS", "Follows the device setting"),
        ("Total first view", f"fa {g('faSlow', lambda r: round(r['total']/1024,1))} KB in {g('faSlow', lambda r: r['requests'])} requests; en {g('enSlow', lambda r: round(r['total']/1024,1))} KB in {g('enSlow', lambda r: r['requests'])}", "Budget: ≤ 120 KB target, ≤ 150 KB ceiling, ≤ 8 requests"),
    ]
    sample_rows = [p for p in cat["products"] if p["slug"] in ("6205", "22220-e", "ucp-208")]
    def sheethead(n, name):
        return Markup(f'<div class="sheethead" aria-hidden="true"><span class="sheethead__no">{n:02d} <small>/ 10</small></span><span class="sheethead__name">{html.escape(name)}</span><span class="sheethead__ticks"></span><span class="sheethead__ref">PC-{n:02d}</span></div>')
    out = env.get_template("system.html.j2").render(palettes=[("Whiteprint (light, default)", pal(light)), ("Blueprint (dark, from device setting)", pal(dark)), ("Band (Anatomy section, both themes)", pal(bandfull))],
        contrast=contrast, fonts=fonts, scale=scale, spacing=spacing, ledger=ledger, families=cat["families"], fam=fam,
        sample_rows=sample_rows, sheethead=sheethead, t=S["en"])
    (OUT / "system.html").write_text(out, encoding="utf-8")
    return fonts, ledger

def render_hub(perf, fonts):
    out = env.get_template("hub.html.j2").render(perf=perf, fonts=fonts, shots=sorted(p.name for p in (OUT / "shots").glob("*.webp")) if (OUT / "shots").exists() else [])
    (OUT / "index.html").write_text(out, encoding="utf-8")

if __name__ == "__main__":
    pfile = ROOT.parent / "qa" / "perf-final.json"
    perf = json.loads(pfile.read_text()) if pfile.exists() else []
    fonts, _ = render_system(perf)
    if (ROOT / "hub.html.j2").exists():
        render_hub(perf, fonts)
    print("system + hub built")
