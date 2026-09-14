import { type FormEvent, useEffect, useRef, useState } from 'react'
import {
  type ClarifyAnswers,
  clarifyItem,
  type GtdBucket,
  type Item,
  type NonActionableDestination,
} from '../api'
import { messageFor } from '../apiMessage'

/**
 * The GTD decision tree, one question at a time.
 *
 * The enforced order is not a UI preference — FR-003's resolution says it is exactly what
 * makes GTD correct out of the box, and collapsing it into a single form would reintroduce
 * the configuration burden the product exists to remove. So: one question per step, with a
 * way back, and no screen that shows them all at once.
 *
 * The `< 2 min?` question is absent on purpose; it arrives with the timer in S-03, between
 * "single step?" and "can it be delegated?".
 */
type Step =
  | 'actionable'
  | 'nonActionable'
  | 'singleStep'
  | 'delegable'
  | 'delegatedTo'
  | 'quickRoute'

/** FR-002: skip the tree and file the item directly. Inbox is not a destination. */
const QUICK_ROUTES: ReadonlyArray<{ value: Exclude<GtdBucket, 'inbox'>; label: string }> = [
  { value: 'next_actions', label: 'Next Actions' },
  { value: 'projects', label: 'Projects' },
  { value: 'delegation', label: 'Delegation' },
  { value: 'calendar', label: 'Calendar' },
  { value: 'someday_maybe', label: 'Someday / Maybe' },
  { value: 'reference', label: 'Reference' },
  { value: 'trash', label: 'Trash' },
]

const NON_ACTIONABLE: ReadonlyArray<{ value: NonActionableDestination; label: string }> = [
  { value: 'trash', label: 'Bin it' },
  { value: 'someday_maybe', label: 'Someday / Maybe' },
  { value: 'reference', label: 'Keep as reference' },
]

export function ClarifyDialog({
  item,
  onClarified,
  onCancel,
}: {
  item: Item
  onClarified: (clarified: Item) => void
  onCancel: () => void
}) {
  const [step, setStep] = useState<Step>('actionable')
  const [delegatedTo, setDelegatedTo] = useState('')
  const [quickRouteTarget, setQuickRouteTarget] = useState<GtdBucket | null>(null)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Focus has to enter the wizard, or a keyboard user clicks Clarify and is left on the
  // trigger with no announcement that anything opened, tabbing forward blind.
  useEffect(() => {
    headingRef.current?.focus()
  }, [])

  async function send(answers: ClarifyAnswers): Promise<void> {
    setError(null)
    setSubmitting(true)
    try {
      onClarified(await clarifyItem(item.id, answers))
    } catch (err) {
      // Through the shared mapper: a raw err.message here would read "Failed to fetch".
      setError(messageFor(err))
    } finally {
      setSubmitting(false)
    }
  }

  function handleDelegation(event: FormEvent): void {
    event.preventDefault()
    const who = delegatedTo.trim()

    if (who === '') {
      // FR-007: Delegation IS the note. Without it you know something is delegated but not
      // whom to chase, so the backend refuses it too — catching it here saves a round trip.
      setError('Say who you are waiting on.')

      return
    }

    void send(
      quickRouteTarget === null
        ? { actionable: true, singleStep: true, delegable: true, delegatedTo: who }
        : { quickRouteBucket: quickRouteTarget, delegatedTo: who },
    )
  }

  return (
    <section
      role="dialog"
      aria-modal="true"
      aria-labelledby="clarify-heading"
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !submitting) {
          onCancel()
        }
      }}
      style={{ marginTop: '1rem' }}
    >
      <h3 id="clarify-heading" ref={headingRef} tabIndex={-1} style={{ color: 'var(--text-h)' }}>
        Clarify: {item.title}
      </h3>

      {step === 'actionable' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Is it actionable?</legend>
          <button type="button" disabled={submitting} onClick={() => setStep('singleStep')}>
            Yes
          </button>
          <button type="button" disabled={submitting} onClick={() => setStep('nonActionable')}>
            No
          </button>
          <button type="button" disabled={submitting} onClick={() => setStep('quickRoute')}>
            Skip the questions
          </button>
        </fieldset>
      )}

      {step === 'quickRoute' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>File it directly</legend>
          {QUICK_ROUTES.map(({ value, label }) => (
            <button
              key={value}
              type="button"
              disabled={submitting}
              onClick={() => {
                if (value === 'delegation') {
                  // Delegation still needs its who/what note (FR-007) — the quick-route
                  // skips the questions, not the field that gives the bucket meaning.
                  setQuickRouteTarget(value)
                  setStep('delegatedTo')

                  return
                }
                void send({ quickRouteBucket: value })
              }}
            >
              {label}
            </button>
          ))}
        </fieldset>
      )}

      {step === 'nonActionable' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Where should it go?</legend>
          {NON_ACTIONABLE.map(({ value, label }) => (
            <button
              key={value}
              type="button"
              disabled={submitting}
              onClick={() => void send({ actionable: false, nonActionableDestination: value })}
            >
              {label}
            </button>
          ))}
        </fieldset>
      )}

      {step === 'singleStep' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Is it a single step?</legend>
          <button type="button" disabled={submitting} onClick={() => setStep('delegable')}>
            Yes
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={() => void send({ actionable: true, singleStep: false })}
          >
            No — it is a project
          </button>
        </fieldset>
      )}

      {step === 'delegable' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Can someone else do it?</legend>
          <button type="button" disabled={submitting} onClick={() => setStep('delegatedTo')}>
            Yes
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={() => void send({ actionable: true, singleStep: true, delegable: false })}
          >
            No — I will do it next
          </button>
        </fieldset>
      )}

      {step === 'delegatedTo' && (
        <form onSubmit={handleDelegation}>
          <label htmlFor="clarify-delegated-to" style={{ display: 'block' }}>
            Who are you waiting on?
          </label>
          <input
            id="clarify-delegated-to"
            type="text"
            value={delegatedTo}
            onChange={(e) => setDelegatedTo(e.target.value)}
            autoFocus
            aria-invalid={error !== null}
            aria-describedby="clarify-error"
            placeholder="Ania — sent the contract on Tuesday"
            style={{ display: 'block', width: '100%', marginTop: '0.25rem' }}
          />
          <button type="submit" disabled={submitting} style={{ marginTop: '0.5rem' }}>
            {submitting ? 'Filing…' : 'Delegate'}
          </button>
        </form>
      )}

      {/* Always mounted, like the capture form's regions: adding aria-live at the same
          moment as the text is unreliable across screen readers. */}
      <p id="clarify-error" role="alert" style={{ color: 'var(--error)' }}>
        {error ?? ''}
      </p>

      <button type="button" onClick={onCancel} disabled={submitting}>
        Cancel
      </button>
      {step !== 'actionable' && (
        <button type="button" disabled={submitting} onClick={() => setStep(previousStep(step))}>
          Back
        </button>
      )}
    </section>
  )
}

function previousStep(step: Exclude<Step, 'actionable'>): Step {
  switch (step) {
    case 'nonActionable':
    case 'singleStep':
    case 'quickRoute':
      return 'actionable'
    case 'delegable':
      return 'singleStep'
    case 'delegatedTo':
      return 'delegable'
  }
}
