import { Link } from 'react-router-dom'
import type { GtdBucket } from '../api'
import { BUCKETS, bucketLabel } from './buckets'

/**
 * Every bucket reachable from every bucket. All eight are the product's promise, so none may
 * be a dead end you can only reach by typing a URL.
 */
export function BucketNav({ current }: { current: GtdBucket }) {
  return (
    <nav aria-label="Buckets" className="my-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
      {BUCKETS.map((bucket) => (
        <Link
          key={bucket}
          to={bucket === 'inbox' ? '/' : `/bucket/${bucket}`}
          aria-current={bucket === current ? 'page' : undefined}
          className={
            bucket === current
              ? 'font-semibold text-accent'
              : 'text-body hover:text-ink'
          }
        >
          {bucketLabel(bucket)}
        </Link>
      ))}
    </nav>
  )
}
