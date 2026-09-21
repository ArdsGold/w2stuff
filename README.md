# Wolf Forge Elementor Bulk Page Generator

Version 1.8.0

## Template markers

The plugin is designed for the Elementor JSON structure used by Wolf Forge.

Use Elementor Advanced > Attributes / Custom Attributes with these values:

- `data-customID|h1NonRepeat` — main H1 destination (maps to DOCX Heading 1).
- `data-customID|sectionTitleNonRepeat` — major section-title destination (maps to DOCX Heading 2).
- `data-customID|hNonRepeat` — normal heading destination (maps to DOCX Heading 3).
- `data-customID|pNonRepeat` — paragraph/content destination.
- `data-customID|repeatableItem` — repeatable widget destination.

The plugin reads the value after the pipe (`|`).

## Unique DOCX repeatable sections

A DOCX paragraph is a repeatable boundary when it is both:

1. a Word heading, and
2. yellow font (or yellow highlight).

Everything after that yellow heading belongs to that repeatable item until the next yellow heading.

For an `icon-box` marked `data-customID|repeatableItem`:

- yellow heading -> `title_text`
- following content -> `description_text`

The plugin clones the **marked widget itself**, not its parent Elementor column/container. Existing repeatable widgets are reduced when there are fewer DOCX repeatable items and cloned when there are more.

## Non-repeat content

For non-yellow DOCX content, the plugin maps by the custom ID in occurrence order:

- first non-yellow heading -> first `h1NonRepeat`
- following non-yellow headings -> `hNonRepeat`
- non-heading paragraphs -> `pNonRepeat`

## Safety

Install on staging first. Elementor JSON structures and third-party widgets can vary by Elementor version. The plugin publishes generated pages after the queue processes them.


## v1.2.0 — Section-aware repeatable widgets

Unique mode now supports a **Widgets per section** setting. The plugin finds the Elementor Section that contains `data-customID|repeatableItem` widgets, keeps the marked widgets as visual prototypes, and clones the entire containing Section when the configured limit is reached. Yellow DOCX headings remain the content boundaries. Each cloned widget receives a fresh Elementor element ID and is inserted back into the same relative parent Column as its prototype.

Recommended setting for the supplied template: **4 widgets per section**. The supplied template contains four marked Icon Box widgets in the Services section.


## v1.4.0 — Created Pages To-Do
After each page is generated, the plugin records it in a Newly Created Pages — To-Do list on the admin screen. Each entry provides Edit Page, Edit with Elementor, and View links for quick review.


## v1.5.0 — DOCX Mapping Reliability
Improves DOCX text extraction and Elementor persistence. Yellow detection now checks all runs within a paragraph, text extraction preserves common Word breaks/tabs, and generated Elementor data is written again after Elementor document save so a document-save normalization cannot restore the original placeholder text. Generation logs include DOCX/template mapping counts for troubleshooting.


## v1.6.0 — Semantic DOCX-to-Elementor Mapping
Non-repeat content is now mapped as ordered DOCX heading/body blocks instead of independent heading and paragraph streams. This prevents paragraphs from shifting or duplicating when the document contains many headings. `pNonRepeat` now adapts to the Elementor widget type: Text Editor receives the body of its mapped heading, Icon Box receives the heading plus body paragraph, and Toggle receives multiple heading/body pairs for FAQs. This matches the supplied residential roofing DOCX structure.


## v1.7.0 — Section Title Mapping
`sectionTitleNonRepeat` is now a first-class marker. It maps to DOCX Heading 2 content, while `h1NonRepeat` maps to Heading 1 and `hNonRepeat` maps to Heading 3. The six-card feature section can remain `pNonRepeat`, and the FAQ Toggle can continue using one `pNonRepeat` marker; individual FAQ item IDs are not required.


## Queue troubleshooting (v1.7.2)
If WP-Cron is delayed or disabled, the admin Queue panel includes **Process Queue Now** to process one queued DOCX job immediately. New jobs also schedule a near-immediate single WP-Cron event, while the recurring worker remains as a backup.


## v1.8.0 — Partial Repeatable Sections, Elementor CSS, and Reset

- Partial/final repeatable sections now remove unused prototype Columns, so background images from empty cards do not remain without text.
- After generated Elementor data is written, the plugin explicitly regenerates the Elementor post CSS and clears Elementor's file cache. This helps prevent newly generated pages from appearing extremely tall/narrow until they are manually opened in Elementor.
- Added **Reset Logs & Generated Pages**. It permanently deletes pages safely marked as created by the plugin, clears the generated-page To-Do list, and clears logs. Pages that were overwritten from an existing page are not automatically deleted.
