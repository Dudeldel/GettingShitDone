import { type Item } from '../api'

function capturedAt(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

export function InboxList({ items }: { items: Item[] }) {
  if (items.length === 0) {
    return <p style={{ color: 'var(--muted)' }}>Your Inbox is empty. Type an idea above to capture it.</p>
  }

  return (
    // Server-ordered, newest first — no client-side re-sort, so the list cannot disagree
    // with what the API considers the order.
    <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
      {items.map((item) => (
        <li key={item.id} style={{ borderTop: '1px solid var(--border)', padding: '0.6rem 0' }}>
          <div style={{ color: 'var(--text-h)' }}>{item.title}</div>
          {item.note !== null && item.note !== '' && (
            <div style={{ color: 'var(--text)', fontSize: '0.9em', whiteSpace: 'pre-wrap' }}>
              {item.note}
            </div>
          )}
          <time dateTime={item.createdAt} style={{ color: 'var(--muted)', fontSize: '0.8em' }}>
            {capturedAt(item.createdAt)}
          </time>
        </li>
      ))}
    </ul>
  )
}
