# Word to Elementor WF

WordPress plugin that fills an Elementor page template from a `.docx` outline and creates a **draft** page.

## Install

1. Copy this folder to `wp-content/plugins/Word-to-Elementor-WF`.
2. Activate **Word to Elementor WF** (Elementor must be active).
3. Open **Word to Elementor WF** in the admin menu, upload a `.docx`, optionally set a page title, and create a draft.

## Word outline

Use Word heading styles (not just bold text):

- **Heading 1** — page title (hero). Extra Heading 1s are ignored.
- Intro paragraphs — hero body (`HeroP`). There is no subtitle widget.
- **Heading 2** — sections: Services, Why Choose Us, Process, FAQ, Closing
- **Heading 3** + a following paragraph — service cards, why-choose-us cards, process steps, or FAQ question/answer

The template uses the **first 4** services. Why Choose Us, Process, and FAQ each take up to **6** items. Process step numbers and the “What You Can Expect” line stay as they are in the layout.

## data-customid

Fillable widgets are matched by Elementor **Advanced → Attributes**:

`data-customid|HeroH1`

| data-customid | Word content |
|---|---|
| `HeroH1` | Heading 1 title |
| `HeroP` | Intro paragraphs |
| `Section2H2` | Services heading |
| `Section2Content1`–`4` | First four service icon boxes |
| `Section3H2` | Why Choose Us heading |
| `Section3Content1`–`6` | Why Choose Us cards |
| `Section4H2` | Process heading |
| `Section4Content{N}H3` / `Section4Content{N}Desc` | Process step title and body |
| `Section5H2` | FAQ heading |
| `Section5Content` | FAQ toggle |
| `Section6H2` / `Section6P` | Closing heading and paragraphs |

Native Elementor `data-id` values are not used.

## Custom template

On the plugin admin page, upload a new Elementor export `.json`. It is stored under `wp-content/uploads/word-to-elementor-wf/`. Use **Use bundled template** to revert.
