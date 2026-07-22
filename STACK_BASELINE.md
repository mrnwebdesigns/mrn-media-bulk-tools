# Stack Baseline - MRN Media Tools

## Baseline Snapshot
- Date pinned: 2026-07-22
- Plugin source path: `/Users/khofmeyer/Development/MRN/plugins/mrn-media-bulk-tools`
- Current plugin version: `0.12.1`
- Intended integration target: mrn-plugin-stack
- Current release model: in-repo standard plugin release unit

## Why This File Exists
This plugin follows MRN QA Engine discovery standards so it can be checked independently from unrelated stack or site work.

## Update Process
1. Update plugin release metadata and version headers.
2. Run `MRN_QA_CODE_ANALYSIS_SCOPE=all mrn-qa run --project-root /Users/khofmeyer/Development/MRN/plugins/mrn-media-bulk-tools`.
3. Run a separate runtime QA pass against the target site when validating live HTTP, admin, accessibility, or performance behavior.
4. Update `stack.lock` when baseline metadata changes.
