import { type FormEvent, useRef, useState } from 'react'
import { ApiError, captureItem, type Item, TITLE_MAX_LENGTH } from '../api'

// The draft outlives the component on purpose. A 401 clears the token, flips the app to
// logged-out and unmounts this form before the error can even paint — so state kept only
// in useState would take the user's captured idea with it, breaking the PRD guardrail
// "capture never loses an entry". Session storage also survives a refresh or a tab crash.
const DRAFT_KEY = 'gsd_capture_draft'

function readDraft(): string {
  try {
    return sessionStorage.getItem(DRAFT_KEY) ?? ''
  } catch {
    return ''
  }
}

function writeDraft(value: string): void {
  try {
    if (value === '') {
      sessionStorage.removeItem(DRAFT_KEY)
    } else {
      sessionStorage.setItem(DRAFT_KEY, value)
    }
  } catch {
    // Private-mode storage can throw; the in-memory state still works.
  }
}

function messageFor(err: unknown): string {
  if (!(err instanceof ApiError)) {
    return 'Could not reach the server. Your text is still here — try again.'
  }

  switch (err.status) {
    case 401:
      return 'Your session expired. Sign in again — your text is saved here.'
    case 422:
      // The backend writes these for the user; retrying unchanged would never work.
      return err.message
    case 429:
      return 'Too many requests. Wait a moment and try again.'
    default:
      return err.message
  }
}

export function CaptureForm({ onCaptured }: { onCaptured: (item: Item) => void }) {
  const [title, setTitle] = useState(readDraft)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  function update(value: string): void {
    setTitle(value)
    writeDraft(value)
    setSaved(false)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()

    const submitted = title.trim()

    if (submitted === '') {
      setSaved(false)
      setError('Type something first.')
      inputRef.current?.focus()

      return
    }

    setError(null)
    setSaved(false)
    setSubmitting(true)

    try {
      const item = await captureItem(submitted)
      // Clear only what was actually sent: the input stays enabled during the round trip,
      // so the user may already have started the next idea.
      setTitle((current) => {
        const next = current === submitted ? '' : current
        writeDraft(next)

        return next
      })
      setSaved(true)
      onCaptured(item)
    } catch (err) {
      setError(messageFor(err))
    } finally {
      setSubmitting(false)
      inputRef.current?.focus()
    }
  }

  return (
    <form onSubmit={handleSubmit} style={{ margin: '1.5rem 0' }} aria-busy={submitting}>
      <label htmlFor="capture-title" style={{ display: 'block' }}>
        Catch an idea
      </label>
      <input
        id="capture-title"
        ref={inputRef}
        type="text"
        value={title}
        onChange={(e) => update(e.target.value)}
        maxLength={TITLE_MAX_LENGTH}
        required
        autoFocus
        aria-invalid={error !== null}
        aria-describedby="capture-error capture-status"
        placeholder="What just crossed your mind?"
        style={{ display: 'block', width: '100%', marginTop: '0.25rem' }}
      />
      {/* Both live regions are always mounted: adding aria-live at the same moment as the
          text is unreliable across screen readers. */}
      <button type="submit" disabled={submitting} style={{ marginTop: '0.5rem' }}>
        {submitting ? 'Saving…' : 'Capture'}
      </button>
      <p id="capture-error" role="alert" style={{ color: 'var(--error)' }}>
        {error ?? ''}
      </p>
      <p id="capture-status" role="status" style={{ color: 'var(--success)' }}>
        {error === null && saved ? 'Saved to your Inbox.' : ''}
      </p>
    </form>
  )
}
