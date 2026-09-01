# MRN Media Tools

Extensible WordPress Media Library utilities. The canonical source lives in the independent `mrnwebdesigns/mrn-media-bulk-tools` repository; MRN uses a local checkout symlink for stack integration. The first module adds bulk
metadata updates to the Media Library list view.

## Current Modules

- **Attachment actions:** Hide WordPress's attachment-page View action and
  group all Media Library row actions in one keyboard-friendly menu. Destructive
  actions are kept at the bottom and styled separately.
- **Bulk metadata tools:** Update attachment titles, image alt text, captions,
  or all three for selected Media Library items.
- **Dimensions column:** Show image width and height in the Media Library list
  view from core metadata, with a bounded SVG-header fallback.
- **HappyFiles folders column:** Show every folder assigned to an attachment,
  including clickable parent paths for nested folders and an Uncategorized state.
- **File size column:** Show a human-readable attachment size from WordPress
  metadata, with a safe fallback to files stored inside the uploads directory.
- **List filters:** Narrow media by exact MIME/file type, minimum file size,
  and detected usage count using the file-size and usage indexes.
- **Generated image sizes:** Inspect the derivatives WordPress actually created,
  including size name, dimensions, file size, and a direct view link.
- **Column controls:** Resize shared boundaries by pointer or keyboard so the
  adjacent column compensates and the table width remains stable. Boundary
  handles sit on the left edge of the following column so they remain usable
  while horizontally scrolled. The last column has its own table-edge handle,
  and a synchronized scrollbar above the table keeps horizontal navigation
  available. Auto-fit a column to its largest item, save widths per WordPress
  user, and open native column visibility controls from a convenient Columns
  button.
- **Usage index:** Scan detected references in editable content, featured
  images, and media-like custom fields; refresh the index daily with WP-Cron;
  and inspect matching content in an accessible modal from the Used In column.
- **Filename tokens:** Build values from `{file_name}`, `{file_title}`,
  `{file_basename}`, `{file_extension}`, `{mime_type}`, and `{mime_subtype}`.

## Compatibility

The release unit intentionally retains the `mrn-media-bulk-tools` folder,
text domain, action identifiers, class name, and transient prefix. Existing
installations therefore continue to update in place while the displayed
product name and internal bootstrap support a broader media-tool scope.

Usage results are intentionally described as detected references. Media added
dynamically by theme/plugin code or stored in an unknown external format may
not be discoverable through a WordPress database scan.

## QA Engine

Run plugin-scoped QA with full static analysis:

```bash
MRN_QA_CODE_ANALYSIS_SCOPE=all mrn-qa run --project-root /Users/khofmeyer/Development/MRN-plugins/mrn-media-bulk-tools
```

Runtime browser, accessibility, API, and performance checks should be run separately against an explicit target site when this plugin change affects rendered output or live WordPress behavior.
