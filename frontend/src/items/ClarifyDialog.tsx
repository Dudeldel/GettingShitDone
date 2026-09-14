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
  //
  // `expired` rather than `secondsLeft` in the deps: a boolean flips once, so the effect
  // re-runs exactly once at zero and then stops rescheduling. Depending on the number itself
  // would rebuild the interval every second and reset its phase on each tick.
  const expired = secondsLeft === 0

  useEffect(() => {
    if (step !== 'timer' || expired) {
      return
    }

    const id = setInterval(() => {
      setSecondsLeft((left) => (left <= 1 ? 0 : left - 1))
    }, 1000)

    return () => clearInterval(id)
  }, [step, loops, expired])

  /**
   * A message describes the attempt that produced it. Moving to another question ends that
   * attempt, so the error and the `aria-invalid` it drives must not follow the user into a
   * different one. Done here rather than in an effect: calling setState from inside an
   * effect is what react-hooks/set-state-in-effect forbids, and the handler is where the
   * intent actually lives.
   */
  function goToStep(next: Step): void {
    setError(null)
    setStep(next)
  }

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
      // aria-modal is deliberately absent. It was here while this rendered as a plain inline
      // section with no overlay, no backdrop and no focus trap — everything behind it stayed
      // on screen and in the tab order, so the attribute told a screen reader something that
      // was not true. The wizard now reads as an elevated panel; making it genuinely modal is
      // a behaviour change, and lying about it was the worse of the two options.
      aria-labelledby="clarify-heading"
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !submitting) {
          onCancel()
        }
      }}
      className="panel mt-4 p-4"
    >
      <h3 id="clarify-heading" ref={headingRef} tabIndex={-1}>
        Clarify: {item.title}
      </h3>

      {step === 'actionable' && (
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Is it actionable?</legend>
          <button className="btn btn-quiet mt-2 mr-2" type="button" disabled={submitting} onClick={() => goToStep('singleStep')}>
            Yes
          </button>
          <button className="btn btn-quiet mt-2 mr-2" type="button" disabled={submitting} onClick={() => goToStep('nonActionable')}>
            No
          </button>
          <button className="btn btn-quiet mt-2 mr-2" type="button" disabled={submitting} onClick={() => goToStep('quickRoute')}>
            Skip the questions
          </button>
        </fieldset>
      )}

      {step === 'quickRoute' && (
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">File it directly</legend>
          {QUICK_ROUTES.map(({ value, label }) => (
            <button
              key={value}
              className="btn btn-quiet mt-2 mr-2"
              type="button"
              disabled={submitting}
              onClick={() => {
                if (value === 'delegation') {
                  // Delegation still needs its who/what note (FR-007) — the quick-route
                  // skips the questions, not the field that gives the bucket meaning.
                  setQuickRouteTarget(value)
                  goToStep('delegatedTo')

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
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Where should it go?</legend>
          {NON_ACTIONABLE.map(({ value, label }) => (
            <button
              key={value}
              className="btn btn-quiet mt-2 mr-2"
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
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Is it a single step?</legend>
          <button className="btn btn-quiet mt-2 mr-2" type="button" disabled={submitting} onClick={() => goToStep('twoMinutes')}>
            Yes
          </button>
          <button
            className="btn btn-quiet mt-2 mr-2"
            type="button"
            disabled={submitting}
            onClick={() => void send({ actionable: true, singleStep: false })}
          >
            No — it is a project
          </button>
        </fieldset>
      )}

      {step === 'twoMinutes' && (
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Will it take less than two minutes?</legend>
          <button
            className="btn btn-quiet mt-2 mr-2"
            type="button"
            disabled={submitting}
            onClick={() => {
              restartTwoMinuteRule()
              goToStep('timer')
            }}
          >
            Yes — do it now
          </button>
          <button
            className="btn btn-quiet mt-2 mr-2"
            type="button"
            disabled={submitting}
            onClick={() => {
              // Not "no" plus a stale timer: this path must report that no timer ran.
              restartTwoMinuteRule()
              goToStep('delegable')
            }}
          >
            No
          </button>
        </fieldset>
      )}

      {step === 'timer' && (
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Do it now — the clock is running</legend>
          {/* Not a live region: a countdown announced every second would drown out
              everything else on the screen. The status line below announces the one
              transition that matters. */}
          {/* leading-[1.2] rather than text-4xl's default 40px: the glyph box of 36px digits is
              43px tall, so the default line box let it bleed a pixel. Small, but it is the
              exact defect class this slice exists to remove. */}
          <p className="my-1 text-4xl leading-[1.2] font-semibold tabular-nums text-ink">
            {formatClock(secondsLeft)}
          </p>
          <p role="status" className="text-sm text-muted">
            {expired
              ? 'Time is up. Finish it, take another two minutes, or file it instead.'
              : 'Two minutes on the clock.'}
          </p>
          <button
            className="btn btn-primary mt-2 mr-2"
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
            className="btn btn-quiet mt-2 mr-2"
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
            className="btn btn-quiet mt-2 mr-2"
            type="button"
            disabled={submitting}
            onClick={() => {
              // Deferring is not a destination — the item is still unclarified, so it goes
              // back into the tree at the question it had not reached yet.
              setDeferred(true)
              goToStep('delegable')
            }}
          >
            File it instead
          </button>
        </fieldset>
      )}

      {step === 'delegable' && (
        <fieldset className="mt-4">
          <legend className="text-sm font-medium text-ink">Can someone else do it?</legend>
          <button className="btn btn-quiet mt-2 mr-2" type="button" disabled={submitting} onClick={() => goToStep('delegatedTo')}>
            Yes
          </button>
          <button
            className="btn btn-quiet mt-2 mr-2"
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
          <label htmlFor="clarify-delegated-to" className="block text-sm font-medium text-ink">
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
            className="field mt-1.5"
          />
          <button className="btn btn-primary mt-2" type="submit" disabled={submitting}>
            {submitting ? 'Filing…' : 'Delegate'}
          </button>
        </form>
      )}

      {/* Always mounted, like the capture form's regions: adding aria-live at the same
          moment as the text is unreliable across screen readers. */}
      <div className="live-line mt-3">
        <p id="clarify-error" role="alert" className="text-danger">
          {error ?? ''}
        </p>
      </div>

      <button className="btn btn-ghost mt-2 mr-2" type="button" onClick={onCancel} disabled={submitting}>
        Cancel
      </button>
      {step !== 'actionable' && (
        <button
          className="btn btn-quiet mt-2 mr-2"
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
            // Returning to the fork drops the quick-route target as well. Without this a
            // destination picked earlier survives a walk back through "is it actionable?"
            // and hijacks the delegation submit below: the item still lands in Delegation
            // with the right note, so nothing looks wrong, while the server is told the
            // user skipped the questions and the whole two-minute record is discarded.
            if (previous === 'actionable') {
              setQuickRouteTarget(null)
            }
            goToStep(previous)
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
