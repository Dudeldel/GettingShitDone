import type { Destination, GtdBucket } from '../api'

/**
 * The eight GTD lists, in the order the workflow moves through them.
 *
 * `Record<GtdBucket, string>` rather than an array of pairs: omitting a bucket is then a
 * compile error, not a view nobody can reach. The full set IS the "GTD out-of-the-box"
 * promise (FR-009), so none may be quietly dropped for UI economy.
 */
const LABELS: Record<GtdBucket, string> = {
  inbox: 'Inbox',
  next_actions: 'Next Actions',
  projects: 'Projects',
  calendar: 'Calendar',
  delegation: 'Delegation',
  someday_maybe: 'Someday / Maybe',
  reference: 'Reference',
  trash: 'Trash',
}

export const BUCKETS = Object.keys(LABELS) as GtdBucket[]

export function bucketLabel(bucket: GtdBucket): string {
  return LABELS[bucket]
}

export function isGtdBucket(value: string | undefined): value is GtdBucket {
  return value !== undefined && value in LABELS
}

/**
 * Mirrors GtdBucket::isActionBucket() on the backend, which is the authority — the server
 * refuses a completion outside these four with a 422 regardless of what this says. The copy
 * exists so the UI does not offer a control that is guaranteed to fail.
 */
const ACTION_BUCKETS: ReadonlySet<GtdBucket> = new Set<GtdBucket>([
  'next_actions',
  'projects',
  'calendar',
  'delegation',
])

export function isActionBucket(bucket: GtdBucket): boolean {
  return ACTION_BUCKETS.has(bucket)
}

/** The seven legal re-file targets — every bucket but the Inbox. */
export const DESTINATIONS = BUCKETS.filter((bucket) => bucket !== 'inbox') as Destination[]
