import { Link } from 'react-router-dom'
import type { GtdBucket } from '../api'
import { BUCKETS, bucketLabel } from './buckets'

/**
 * Every bucket reachable from every bucket. All eight are the product's promise, so none may
 * be a dead end you can only reach by typing a URL.
 */
export function BucketNav({ current }: { current: GtdBucket }) {
  return (
    <nav aria-label="Buckets" style={{ margin: '1rem 0', lineHeight: 2 }}>
      {BUCKETS.map((bucket) => (
        <Link
          key={bucket}
          to={bucket === 'inbox' ? '/' : `/bucket/${bucket}`}
          aria-current={bucket === current ? 'page' : undefined}
          style={{
            marginRight: '0.75rem',
            color: bucket === current ? 'var(--text-h)' : 'var(--text)',
            fontWeight: bucket === current ? 600 : 400,
          }}
        >
          {bucketLabel(bucket)}
        </Link>
      ))}
    </nav>
  )
}
