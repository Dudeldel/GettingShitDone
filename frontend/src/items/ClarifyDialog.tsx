import { type FormEvent, useEffect, useRef, useState } from 'react'
import {
  type ClarifyAnswers,
  clarifyItem,
  type GtdBucket,
  type Item,
  type NonActionableDestination,
  TWO_MINUTE_SECONDS,
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
 * The `< 2 min?` question (FR-006) sits between "single step?" and "can it be delegated?",
 * and answering yes starts the timer rather than filing the item — the one stateful step in
 * the whole flow.
 */
type Step =
  | 'actionable'
  | 'nonActionable'
  | 'singleStep'
  | 'twoMinutes'
  | 'timer'
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

/** m:ss — a bare seconds count reads as a number, not as time running out. */
function formatClock(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

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
  // The two-minute rule's state. `loops` counts how many times the user asked for more
  // time; `deferred` records that the timer ended without the work being done, which is
  // what sends them back into the tree instead of filing the item.
  const [secondsLeft, setSecondsLeft] = useState(TWO_MINUTE_SECONDS)
  const [loops, setLoops] = useState(0)
  const [deferred, setDeferred] = useState(false)

  // Focus has to enter the wizard, or a keyboard user clicks Clarify and is left on the
  // trigger with no announcement that anything opened, tabbing forward blind.
  useEffect(() => {
    headingRef.current?.focus()
  }, [])

  // Only ticks while the timer step is on screen, and `loops` is a dependency so asking for
  // more time tears the interval down and starts a fresh one — otherwise the restarted
  // countdown would inherit whatever fraction of a second was left on the old tick.
  useEffect(() => {
    if (step !== 'timer') {
      return
    }

    const id = setInterval(() => {
      setSecondsLeft((left) => (left <= 1 ? 0 : left - 1))
    }, 1000)

    return () => clearInterval(id)
  }, [step, loops])

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

  /**
   * The tail of the tree, shared by both ways of reaching it: the item either never went
   * near the timer, or a timer ran and the user deferred. The two produce different answer
   * sets, and getting this wrong would report a timer that never ran (or lose one that did).
   */
  function withTwoMinuteAnswers(
    delegation: { delegable: false } | { delegable: true; delegatedTo: string },
  ): ClarifyAnswers {
    return deferred
      ? {
          actionable: true,
          singleStep: true,
          twoMinutes: true,
          twoMinuteOutcome: 'deferred',
          twoMinuteLoops: loops,
          ...delegation,
        }
      : { actionable: true, singleStep: true, twoMinutes: false, ...delegation }
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
        ? withTwoMinuteAnswers({ delegable: true, delegatedTo: who })
        : { quickRouteBucket: quickRouteTarget, delegatedTo: who },
    )
  }

  /** Re-answering "< 2 min?" must discard whatever the previous timer recorded. */
  function restartTwoMinuteRule(): void {
    setSecondsLeft(TWO_MINUTE_SECONDS)
    setLoops(0)
    setDeferred(false)
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
          <button type="button" disabled={submitting} onClick={() => setStep('twoMinutes')}>
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

      {step === 'twoMinutes' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Will it take less than two minutes?</legend>
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              restartTwoMinuteRule()
              setStep('timer')
            }}
          >
            Yes — do it now
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              // Not "no" plus a stale timer: this path must report that no timer ran.
              restartTwoMinuteRule()
              setStep('delegable')
            }}
          >
            No
          </button>
        </fieldset>
      )}

      {step === 'timer' && (
        <fieldset style={{ border: 0, padding: 0 }}>
          <legend>Do it now — the clock is running</legend>
          {/* Not a live region: a countdown announced every second would drown out
              everything else on the screen. The status line below announces the one
              transition that matters. */}
          <p style={{ fontSize: '2rem', color: 'var(--text-h)', margin: '0.25rem 0' }}>
            {formatClock(secondsLeft)}
          </p>
          <p role="status" style={{ color: 'var(--muted)' }}>
            {secondsLeft === 0
              ? 'Time is up. Finish it, take another two minutes, or file it instead.'
              : 'Two minutes on the clock.'}
          </p>
          <button
            type="button"
            disabled={submitting}
            onClick={() =>
              void send({
                actionable: true,
                singleStep: true,
                twoMinutes: true,
                twoMinuteOutcome: 'done',
                twoMinuteLoops: loops,
              })
            }
          >
            {submitting ? 'Filing…' : 'Done'}
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              // FR-006's "need more time loops the timer". Counting the loop here is the
              // only record that the user underestimated the job.
              setSecondsLeft(TWO_MINUTE_SECONDS)
              setLoops((current) => current + 1)
            }}
          >
            I need more time
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              // Deferring is not a destination — the item is still unclarified, so it goes
              // back into the tree at the question it had not reached yet.
              setDeferred(true)
              setStep('delegable')
            }}
          >
            File it instead
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
            onClick={() => void send(withTwoMinuteAnswers({ delegable: false }))}
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
        <button
          type="button"
          disabled={submitting}
          onClick={() => {
            // Back out of the quick-route's delegation note to the quick-route itself, not
            // into the tree: the user never answered "can someone else do it?", and landing
            // there let them file the item somewhere they never chose.
            const previous =
              step === 'delegatedTo' && quickRouteTarget !== null
                ? 'quickRoute'
                : previousStep(step)

            // Stepping back out of the two-minute rule drops what it recorded. Keeping a
            // deferral alive behind a re-answered question would send "a timer ran" for a
            // question the user has just answered differently.
            if (previous === 'singleStep' || previous === 'twoMinutes') {
              restartTwoMinuteRule()
            }
            setStep(previous)
          }}
        >
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
    case 'twoMinutes':
      return 'singleStep'
    case 'timer':
      return 'twoMinutes'
    case 'delegable':
      // Back from here always re-asks "< 2 min?", whether the user arrived by answering no
      // or by deferring a timer. Both are answers to that question.
      return 'twoMinutes'
    case 'delegatedTo':
      return 'delegable'
  }
}
