import { type FormEvent, useState } from 'react'
import { captureItem, type Item } from '../api'

export function CaptureForm({ onCaptured }: { onCaptured: (item: Item) => void }) {
  const [title, setTitle] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()

    if (title.trim() === '') {
      return
    }

    setError(null)
    setSaved(false)
    setSubmitting(true)

    try {
      const item = await captureItem(title.trim())
      // Clear only once the server has confirmed. A failed capture must never cost the
      // user the text they typed — that is the PRD guardrail this screen exists to keep.
      setTitle('')
      setSaved(true)
      onCaptured(item)
    } catch {
      setError('Could not save that. Your text is still here — try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} style={{ margin: '1.5rem 0' }}>
      <label style={{ display: 'block' }}>
        Catch an idea
        <input
          type="text"
          value={title}
          onChange={(e) => {
            setTitle(e.target.value)
            setSaved(false)
          }}
          disabled={submitting}
          autoFocus
          placeholder="What just crossed your mind?"
          style={{ display: 'block', width: '100%', marginTop: '0.25rem' }}
        />
      </label>
      <button type="submit" disabled={submitting} style={{ marginTop: '0.5rem' }}>
        {submitting ? 'Saving…' : 'Capture'}
      </button>
      {error !== null && <p style={{ color: 'crimson' }}>{error}</p>}
      {error === null && saved && <p style={{ color: 'seagreen' }}>Saved to your Inbox.</p>}
    </form>
  )
}
