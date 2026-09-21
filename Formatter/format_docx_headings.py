#!/usr/bin/env python3
"""
Backward-compatible Word-to-Elementor DOCX formatter.

Run with no arguments:
    python format_docx_headings.py

It automatically processes every .docx in ./unformatted and writes:
    ./formatted/<name> - formatted.docx

Sections with more H3 items than the template has slots are still formatted;
every H3 title in that section is colored yellow (OVERFLOW_COLOR), and only the
first N items (the template's slots) go into the Elementor mapping.

Content mapping follows AutomationTestTemplate2.0.json:
HeroH1, HeroP,
Section2H2 + Section2Content1..4,
Section3H2 + Section3H2Subtitle + Section3Content1..6,
Section4H2 + Section4Content1H3/Desc..6,
Section5H2 + Section5Content,
Section6H2 + Section6P.
"""
from __future__ import annotations
import argparse
import json
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from docx import Document
from docx.shared import RGBColor

BASE_DIR = Path(__file__).resolve().parent
INPUT_DIR = BASE_DIR / "unformatted"
OUTPUT_DIR = BASE_DIR / "formatted"
SAMPLE_DIRS = (BASE_DIR / "sample formats", BASE_DIR / "sample_formats", BASE_DIR)
SAMPLE_NAMES = ("Sample Format.docx", "sample format.docx")
SECTIONS = ("services", "why", "process", "faq", "closing")
MAX_ITEMS = {"services": 4, "why": 6, "process": 6, "faq": 6}
# If a section has more H3 items than the template has slots, every H3 title
# in that section is colored so the oversized section is easy to spot.
OVERFLOW_COLOR = RGBColor(0xFF, 0xFF, 0x00)  # yellow

@dataclass
class Item:
    title: str
    bodies: list[str] = field(default_factory=list)

@dataclass
class Outline:
    title: str = ""
    hero: list[str] = field(default_factory=list)
    section_titles: dict[str, str] = field(default_factory=dict)
    section_subtitles: dict[str, str] = field(default_factory=dict)
    items: dict[str, list[Item]] = field(default_factory=dict)
    closing: list[str] = field(default_factory=list)
    def __post_init__(self):
        for s in SECTIONS:
            self.section_titles.setdefault(s, "")
            self.section_subtitles.setdefault(s, "")
            self.items.setdefault(s, [])

def ptext(p):
    return "".join(r.text or "" for r in p.runs).strip()

def hlevel(p):
    style = p.style
    name = (style.name or "") if style else ""
    m = re.search(r"heading\s*([1-3])", name, re.I)
    if m:
        return int(m.group(1))
    sid = getattr(style, "style_id", "") if style else ""
    m = re.search(r"heading\s*([1-3])", sid or "", re.I)
    if m:
        return int(m.group(1))
    return int(sid) if sid in {"1", "2", "3"} else 0

def norm(s):
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9]+", " ", s.lower())).strip()

def classify_section(text):
    """Classify a major header by keywords, not exact phrases."""
    n = norm(text)
    if not n:
        return None

    words = set(re.findall(r"[a-z0-9]+", n))

    # FAQ
    if (
        "faq" in words
        or "faqs" in words
        or ("frequently" in words and "questions" in words)
    ):
        return "faq"

    # Process
    if words & {"process", "procedure", "workflow"}:
        return "process"

    # Why
    if "why" in words:
        return "why"

    # Services
    if words & {"service", "services", "solutions"}:
        return "services"

    return None



def _infer_text_heading_level(paragraphs, idx):
    """Infer structural heading levels for documents with no Word heading styles."""
    text = ptext(paragraphs[idx]).strip()
    if not text:
        return 0
    low = norm(text)
    words = set(re.findall(r"[a-z0-9]+", low))

    if idx == 0:
        return 1

    # Strong major-section signals.
    if ("frequently" in words and "questions" in words) or "faq" in words or "faqs" in words:
        return 1
    if "why" in words:
        return 1
    if words & {"process", "procedure", "workflow"}:
        return 1
    if words & {"services", "solutions"}:
        return 1

    # FAQ questions are item headings. They are handled again after the FAQ
    # section is located so "Why..." FAQ questions never become Section 3.
    if text.endswith("?") and len(text) <= 120:
        return 2

    # Conservative item-heading inference.
    if len(text) <= 80 and len(text.split()) <= 12:
        nxt = ptext(paragraphs[idx + 1]).strip() if idx + 1 < len(paragraphs) else ""
        if (not re.search(r"[.!;:,]$", text)
                and not re.match(
                    r"^(we|our|our team|this|these|the|if|when|after|before|"
                    r"once|proper|regular|many|some|you|your|call|contact|"
                    r"don't|do not|a |an |to )\b", text, re.I)
                and len(nxt) >= 45):
            return 2
    return 0


def read_rows(path):
    d = Document(str(path))
    paragraphs = [p for p in d.paragraphs if ptext(p)]
    if not paragraphs:
        raise ValueError(f"No readable paragraphs in {path}")

    # Prefer genuine Word heading styles whenever the source contains them.
    if any(hlevel(p) in (1, 2, 3) for p in paragraphs):
        return [(hlevel(p), ptext(p)) for p in paragraphs]

    # Text-only fallback: infer the structure from semantic section labels,
    # FAQ question punctuation, and the short title-like lines surrounding
    # those structures.
    rows = [(0, ptext(p)) for p in paragraphs]
    rows[0] = (1, rows[0][1])

    def words_at(i):
        return set(re.findall(r"[a-z0-9]+", norm(rows[i][1])))

    def is_major(i, kind):
        w = words_at(i)
        if kind == "why":
            return "why" in w and not rows[i][1].strip().endswith("?")
        if kind == "process":
            return bool(w & {"process", "procedure", "workflow"})
        if kind == "faq":
            return (
                ("frequently" in w and "questions" in w)
                or bool(w & {"faq", "faqs"})
            )
        return False

    why_idx = next((i for i in range(1, len(rows)) if is_major(i, "why")), None)
    process_idx = next(
        (i for i in range((why_idx or 0) + 1, len(rows))
         if is_major(i, "process")), None
    )
    faq_idx = next(
        (i for i in range((process_idx or 0) + 1, len(rows))
         if is_major(i, "faq")), None
    )

    # If the major Why heading itself ends with "?", recognize the canonical
    # "Why Choose Us..." form without mistaking FAQ questions for it.
    if why_idx is None:
        why_idx = next(
            (i for i in range(1, len(rows))
             if re.search(r"\bwhy\s+choose\s+us\b", norm(rows[i][1]))),
            None
        )

    # Mark major sections.
    for idx in (why_idx, process_idx, faq_idx):
        if idx is not None:
            rows[idx] = (1, rows[idx][1])

    # Infer service/item headings before Why.
    if why_idx is not None:
        for i in range(1, why_idx):
            if rows[i][0] == 0:
                rows[i] = (_infer_text_heading_level(paragraphs, i), rows[i][1])

    # Infer Why and Process item headings.
    if why_idx is not None:
        end = process_idx if process_idx is not None else len(rows)
        for i in range(why_idx + 1, end):
            if rows[i][0] == 0:
                rows[i] = (_infer_text_heading_level(paragraphs, i), rows[i][1])

    if process_idx is not None:
        end = faq_idx if faq_idx is not None else len(rows)
        for i in range(process_idx + 1, end):
            if rows[i][0] == 0:
                rows[i] = (_infer_text_heading_level(paragraphs, i), rows[i][1])

    # FAQ questions are explicitly questions. This prevents "Why..." FAQ
    # questions from ever being interpreted as the major Why section.
    closing_idx = None
    if faq_idx is not None:
        faq_count = 0
        for i in range(faq_idx + 1, len(rows)):
            text = rows[i][1].strip()

            if text.endswith("?") and len(text) <= 120:
                rows[i] = (2, text)
                faq_count += 1
                continue

            if faq_count >= MAX_ITEMS["faq"]:
                # The answer to FAQ #6 is usually a long paragraph. The next
                # short, title-like paragraph is the closing CTA heading.
                if (len(text) <= 100 and len(text.split()) <= 14
                        and not re.search(r"[.!;:,]$", text)):
                    closing_idx = i
                    rows[i] = (1, text)
                    break

    if closing_idx is not None:
        for i in range(closing_idx + 1, len(rows)):
            rows[i] = (0, rows[i][1])

    return rows

def collect_body(rows, i):
    out = []
    while i < len(rows) and rows[i][0] == 0:
        out.append(rows[i][1])
        i += 1
    return out, i

def parse_outline(path):
    """Parse a source DOCX using headers plus document structure."""
    rows = read_rows(path)
    o = Outline()
    o.title = rows[0][1]

    # Find the major sections first. These searches are limited by order, not
    # by H1/H2/H3 level, because source templates use mixed heading levels.
    def find_classified(start, section):
        for i in range(start, len(rows)):
            level, text = rows[i]
            if level in (1, 2, 3) and classify_section(text) == section:
                return i
        return None

    why_i = find_classified(1, "why")
    if why_i is None:
        raise ValueError(
            "Could not identify major section(s): why. "
            "Headings found after title: " +
            " | ".join(text for level, text in rows[1:] if level > 0)
        )

    process_i = find_classified(why_i + 1, "process")
    if process_i is None:
        raise ValueError(
            "Could not identify major section(s): process. "
            "Headings found after Why: " +
            " | ".join(text for level, text in rows[why_i:] if level > 0)
        )

    faq_i = find_classified(process_i + 1, "faq")
    if faq_i is None:
        raise ValueError(
            "Could not identify major section(s): faq. "
            "Headings found after Process: " +
            " | ".join(text for level, text in rows[process_i:] if level > 0)
        )

    # Services are either:
    #   A) a dedicated Services heading followed by four item headings, or
    #   B) four item headings immediately after the hero/title, with no
    #      dedicated Services heading.  Crucially, only headings BEFORE Why
    #      are considered, so "Reliable Service" in the Why section cannot
    #      become the Services boundary.
    services_i = None
    services_title = None

    for i in range(1, why_i):
        level, text = rows[i]
        if level not in (1, 2, 3) or classify_section(text) != "services":
            continue
        count = sum(1 for j in range(i + 1, why_i) if rows[j][0] in (2, 3))
        if count >= MAX_ITEMS["services"]:
            services_i = i
            services_title = text
            break

    implicit_services = services_i is None
    if implicit_services:
        service_headers = [
            i for i in range(1, why_i) if rows[i][0] in (2, 3)
        ]
        if len(service_headers) != MAX_ITEMS["services"]:
            raise ValueError(
                "Could not identify the Services section. "
                f"Found {len(service_headers)} item headings before Why; "
                f"expected {MAX_ITEMS['services']}."
            )
        services_start = service_headers[0]
        services_end = why_i

        # No Services header exists in these documents. Create the heading
        # expected by the Elementor Section2H2 field from the document title.
        base = re.sub(r"\s+in\s+.+$", "", o.title, flags=re.I).strip()
        services_title = f"Our {base} Services"
    else:
        services_start = services_i
        services_end = why_i

    # Closing is determined structurally after the FAQ section. After six
    # FAQ item headings, the next heading at any level is the closing CTA.
    closing_i = None
    faq_headers = 0
    for i in range(faq_i + 1, len(rows)):
        level, text = rows[i]
        if level in (2, 3):
            if faq_headers < MAX_ITEMS["faq"]:
                faq_headers += 1
                continue
            closing_i = i
            break
        if level == 1 and faq_headers >= MAX_ITEMS["faq"]:
            closing_i = i
            break

    if closing_i is None:
        raise ValueError("Could not identify major section(s): closing.")

    o.section_titles["services"] = services_title
    o.section_titles["why"] = rows[why_i][1]
    o.section_titles["process"] = rows[process_i][1]
    o.section_titles["faq"] = rows[faq_i][1]
    o.section_titles["closing"] = rows[closing_i][1]

    # Hero ends at the first service item/header. With an explicit Services
    # heading, include that heading's preceding body as hero; with implicit
    # Services, likewise stop before the first service item.
    o.hero = [text for level, text in rows[1:services_start] if text.strip()]

    def parse_items(start_i, end_i, start_is_item=False):
        items = []
        current = None
        begin = start_i if start_is_item else start_i + 1

        for i in range(begin, end_i):
            level, text = rows[i]
            if not text.strip():
                continue
            if level in (2, 3):
                if current is not None:
                    items.append(current)
                current = Item(title=text)
            elif current is not None:
                current.bodies.append(text)

        if current is not None:
            items.append(current)
        return items

    o.items["services"] = parse_items(
        services_start, services_end, start_is_item=implicit_services
    )
    o.items["why"] = parse_items(why_i, process_i)
    o.items["process"] = parse_items(process_i, faq_i)
    o.items["faq"] = parse_items(faq_i, closing_i)
    o.closing = [text for level, text in rows[closing_i + 1:] if text.strip()]

    for section in ("services", "why", "process", "faq"):
        expected = MAX_ITEMS[section]
        actual = len(o.items[section])
        if actual < expected:
            raise ValueError(
                f"{section} contains {actual} items; expected at least {expected}."
            )

    return o

def validate(o):
    """Validate constraints imposed by the Elementor template."""
    if not o.title:
        raise ValueError("HeroH1/page title is missing.")

    for sec in ("services", "why", "process", "faq", "closing"):
        if not o.section_titles[sec]:
            raise ValueError(f"Missing required major section: {sec}.")

def clear_body(d):
    body = d.element.body
    sect = body.find("{http://schemas.openxmlformats.org/wordprocessingml/2006/main}sectPr")
    for child in list(body):
        if child is not sect:
            body.remove(child)

def add(d, style, value, color=None):
    p = d.add_paragraph(value)
    p.style = style
    if color is not None:
        for r in p.runs:
            r.font.color.rgb = color
    return p

def overflow_count(o, section):
    """Number of items beyond what the template has slots for."""
    return max(0, len(o.items[section]) - MAX_ITEMS.get(section, 10**9))

def add_items(d, o, section):
    """Write H3 + body for each item; if the section is oversized, color all H3s."""
    oversized = overflow_count(o, section) > 0
    for x in o.items[section]:
        add(d, "Heading 3", x.title, OVERFLOW_COLOR if oversized else None)
        for b in x.bodies:
            add(d, "Normal", b)

def write_docx(o, sample, output):
    d = Document(str(sample))
    clear_body(d)

    add(d, "Heading 1", o.title)
    for x in o.hero:
        add(d, "Normal", x)

    # Section 2
    if o.section_titles["services"]:
        add(d, "Heading 2", o.section_titles["services"])
        add_items(d, o, "services")

    # Section 3
    if o.section_titles["why"]:
        add(d, "Heading 2", o.section_titles["why"])
        if o.section_subtitles["why"]:
            add(d, "Heading 3", o.section_subtitles["why"])
        add_items(d, o, "why")

    # Section 4
    if o.section_titles["process"]:
        add(d, "Heading 2", o.section_titles["process"])
        add_items(d, o, "process")

    # Section 5
    if o.section_titles["faq"]:
        add(d, "Heading 2", o.section_titles["faq"])
        add_items(d, o, "faq")

    # Section 6
    if o.section_titles["closing"]:
        add(d, "Heading 2", o.section_titles["closing"])
        for b in o.closing:
            add(d, "Normal", b)

    output.parent.mkdir(parents=True, exist_ok=True)
    d.save(str(output))

def mapping(o):
    return {
        "HeroH1": o.title,
        "HeroP": "\n".join(o.hero),
        "Section2H2": o.section_titles["services"],
        "Section2Content": [
            {"data-customid": f"Section2Content{i}", "title": x.title, "description": "\n".join(x.bodies)}
            for i, x in enumerate(o.items["services"][:MAX_ITEMS["services"]], 1)
        ],
        "Section3H2": o.section_titles["why"],
        "Section3H2Subtitle": o.section_subtitles["why"],
        "Section3Content": [
            {"data-customid": f"Section3Content{i}", "title": x.title, "description": "\n".join(x.bodies)}
            for i, x in enumerate(o.items["why"][:MAX_ITEMS["why"]], 1)
        ],
        "Section4H2": o.section_titles["process"],
        "Section4Content": [
            {"h3_slot": f"Section4Content{i}H3", "description_slot": f"Section4Content{i}Desc",
             "title": x.title, "description": "\n".join(x.bodies)}
            for i, x in enumerate(o.items["process"][:MAX_ITEMS["process"]], 1)
        ],
        "Section5H2": o.section_titles["faq"],
        "Section5Content": [
            {"question": x.title, "answer": "\n".join(x.bodies)}
            for x in o.items["faq"][:MAX_ITEMS["faq"]]
        ],
        "Section6H2": o.section_titles["closing"],
        "Section6P": "\n".join(o.closing),
    }

def find_sample():
    for directory in SAMPLE_DIRS:
        for name in SAMPLE_NAMES:
            candidate = directory / name
            if candidate.is_file():
                return candidate
    for directory in SAMPLE_DIRS:
        if directory.is_dir():
            for candidate in sorted(directory.glob("*.docx")):
                if not candidate.name.startswith("~$") and candidate.parent not in {INPUT_DIR, OUTPUT_DIR}:
                    return candidate
    return None

def parse_args(argv):
    ap = argparse.ArgumentParser(description="Format DOCX files for the Word-to-Elementor template.")
    ap.add_argument("inputs", nargs="*", type=Path)
    ap.add_argument("--sample", type=Path, default=None)
    ap.add_argument("--output-dir", type=Path, default=OUTPUT_DIR)
    ap.add_argument("--output", type=Path, default=None)
    ap.add_argument("--mapping-json", action="store_true")
    return ap.parse_args(argv)

def main(argv=None):
    a = parse_args(argv if argv is not None else sys.argv[1:])
    inputs = list(a.inputs)
    if not inputs and INPUT_DIR.is_dir():
        inputs = sorted(p for p in INPUT_DIR.glob("*.docx") if not p.name.startswith("~$"))

    if not inputs:
        print(f"No .docx files found in {INPUT_DIR}", file=sys.stderr)
        return 1

    sample = a.sample or find_sample()
    if sample is None or not sample.is_file():
        print("Sample DOCX not found. Use --sample \"path\\to\\Sample Format.docx\".", file=sys.stderr)
        return 1

    failures = 0
    for source in inputs:
        if not source.is_file():
            print(f"Skip missing file: {source}", file=sys.stderr)
            failures += 1
            continue

        output = a.output if len(inputs) == 1 and a.output else a.output_dir / f"{source.stem}.docx"

        try:
            outline = parse_outline(source)
            validate(outline)
            write_docx(outline, sample, output)

            print(f"Wrote: {output}")
            print(f"  HeroH1: {outline.title!r}")
            print(f"  HeroP: {len(outline.hero)} paragraph(s)")
            print(f"  Section2Content: {len(outline.items['services'])}")
            print(f"  Section3Content: {len(outline.items['why'])}")
            print(f"  Section4Content: {len(outline.items['process'])}")
            print(f"  Section5Content: {len(outline.items['faq'])}")
            print(f"  Section6P: {len(outline.closing)} paragraph(s)")

            for sec in ("services", "why", "process", "faq"):
                extra = overflow_count(outline, sec)
                if extra:
                    print(f"  NOTE: {sec} has {len(outline.items[sec])} items; "
                          f"template has {MAX_ITEMS[sec]} slots; all {sec} H3 titles "
                          f"colored yellow")

            if a.mapping_json:
                print(json.dumps(mapping(outline), ensure_ascii=False, indent=2))

        except Exception as exc:
            print(f"Failed {source}: {exc}", file=sys.stderr)
            failures += 1

    return 1 if failures else 0

if __name__ == "__main__":
    raise SystemExit(main())


# v3 change:
# Closing/CTA headings are detected by document position, not CTA wording:
# after the FAQ major section, the next heading is treated as the Closing
# heading. This prevents the parser from requiring phrase-specific CTA rules.
