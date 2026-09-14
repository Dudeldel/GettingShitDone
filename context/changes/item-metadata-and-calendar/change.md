---
change_id: item-metadata-and-calendar
title: Item attributes, and what a date does to the Calendar bucket
status: implementing
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

give an item a date, tags, a context and the important/urgent flags, and make an item that has a date show up in the Calendar/Dates bucket. Roadmap S-06 with S-07 absorbed (FR-011 + FR-013). The five columns already exist on items, dormant and out of #[Fillable] since S-01. First edit-an-item surface in the product, and the codebase has twice refused a generic PATCH. Open question: what "shows up in Calendar" means given FR-008's exactly-one-bucket — working direction is a view over the date, not a second membership.
