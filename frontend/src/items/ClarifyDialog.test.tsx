import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { GtdBucket, Item } from '../api'
import { makeItem, server } from '../test/server'
import { ClarifyDialog } from './ClarifyDialog'

/**
 * Every expected destination below comes from the PRD requirement named in the test, not
 * from reading ClarifyDialog or ClarifyDecision. The backend owns the routing; what this
 * file pins is that the UI asks the questions in the enforced order and sends the answers
 * that describe the branch the user actually took.
 */
function renderDialog(
  item: Item = makeItem({ id: 5, title: 'ring the dentist' }),
  userOptions: Parameters<typeof userEvent.setup>[0] = {},
) {
  const clarified: Item[] = []
  const cancelled = { count: 0 }

  return {
    user: userEvent.setup(userOptions),
    clarified,
    cancelled,
    ...render(
      <ClarifyDialog
        item={item}
        onClarified={(i) => clarified.push(i)}
        onCancel={() => {
          cancelled.count += 1
        }}
      />,
    ),
  }
}

/** Captures the body the dialog posts, and answers with the bucket the backend would. */
function captureClarifyRequest(bucket: GtdBucket) {
  const sent: Array<Record<string, unknown>> = []

  server.use(
    http.post('*/api/items/:id/clarify', async ({ request }) => {
      sent.push((await request.json()) as Record<string, unknown>)

      return HttpResponse.json(makeItem({ id: 5, bucket }))
    }),
  )

  return sent
}

describe('the question order (FR-003)', () => {
  it('asks one question at a time, never the whole form at once', async () => {
    const { user } = renderDialog()

    // The enforced order is what FR-003 says makes GTD correct out of the box, so the
    // later questions must not be reachable before the earlier ones are answered.
    expect(screen.getByText('Is it actionable?')).toBeInTheDocument()
    expect(screen.queryByText('Is it a single step?')).not.toBeInTheDocument()
    expect(screen.queryByText('Can someone else do it?')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    expect(screen.getByText('Is it a single step?')).toBeInTheDocument()
    expect(screen.queryByText('Is it actionable?')).not.toBeInTheDocument()
  })

  it('lets the user walk back up the tree', async () => {
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /back/i }))

    expect(screen.getByText('Is it actionable?')).toBeInTheDocument()
  })

  it('does not ask about the two-minute rule before the item is a single step', async () => {
    // This replaces an S-02 test that asserted the question is never asked at all. It kept
    // passing after S-03 shipped it, because it looked one step too early — green for a
    // feature it claimed did not exist. What is worth pinning is its POSITION in the order.
    const { user } = renderDialog()

    expect(screen.queryByText(/two minutes/i)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    expect(screen.queryByText(/two minutes/i)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    expect(screen.getByText(/less than two minutes/i)).toBeInTheDocument()
  })
})

describe('each branch sends the answers for the path taken', () => {
  it('routes a non-actionable item to the chosen destination (FR-004)', async () => {
    const sent = captureClarifyRequest('reference')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /keep as reference/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({ actionable: false, nonActionableDestination: 'reference' })
  })

  it('routes a multi-step item to a project (FR-005)', async () => {
    const sent = captureClarifyRequest('projects')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /it is a project/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({ actionable: true, singleStep: false })
  })

  it('routes a delegable item with its who/what note (FR-007)', async () => {
    const sent = captureClarifyRequest('delegation')
    const { user, clarified } = renderDialog()

    // actionable → single step → NOT under two minutes → delegable.
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.type(screen.getByLabelText(/who are you waiting on/i), 'Ania — the contract')
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: false,
      delegable: true,
      delegatedTo: 'Ania — the contract',
    })
  })

  it('routes a single step nobody else can take to Next Actions (FR-008)', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /i will do it next/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: false,
      delegable: false,
    })
  })
})

describe('when clarify cannot complete', () => {
  it('refuses an empty delegation note without calling the API', async () => {
    let called = false
    server.use(
      http.post('*/api/items/:id/clarify', () => {
        called = true

        return HttpResponse.json(makeItem())
      }),
    )
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/who you are waiting on/i)
    expect(called).toBe(false)
    expect(clarified).toHaveLength(0)
  })

  it('surfaces the backend message and keeps the item unclarified', async () => {
    server.use(
      http.post('*/api/items/:id/clarify', () =>
        HttpResponse.json({ message: 'That item has already been clarified.' }, { status: 409 }),
      ),
    )
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /bin it/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/already been clarified/i)
    // The caller must not be told the item moved when it did not.
    expect(clarified).toHaveLength(0)
  })

  it('says the server is unreachable rather than echoing the raw transport error', async () => {
    server.use(http.post('*/api/items/:id/clarify', () => HttpResponse.error()))
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /bin it/i }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/could not reach the server/i)
    expect(alert).not.toHaveTextContent(/failed to fetch/i)
  })
})

describe('the quick-route (FR-002)', () => {
  it('files the item directly, skipping the questions', async () => {
    const sent = captureClarifyRequest('reference')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: /skip the questions/i }))
    await user.click(screen.getByRole('button', { name: 'Reference' }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({ quickRouteBucket: 'reference' })
  })

  it('still asks who you are waiting on when the target is Delegation', async () => {
    // The quick-route skips the questions, not the field that makes Delegation meaningful.
    const sent = captureClarifyRequest('delegation')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: /skip the questions/i }))
    await user.click(screen.getByRole('button', { name: 'Delegation' }))
    await user.type(screen.getByLabelText(/who are you waiting on/i), 'Piotr — the quote')
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({ quickRouteBucket: 'delegation', delegatedTo: 'Piotr — the quote' })
  })

  it('does not offer the Inbox as a destination', async () => {
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: /skip the questions/i }))

    // Routing to the Inbox is not a clarification; it is the absence of one.
    expect(screen.queryByRole('button', { name: /^inbox$/i })).not.toBeInTheDocument()
  })
})

describe('the wizard as a dialog', () => {
  it('announces itself and takes focus', async () => {
    renderDialog()

    const dialog = screen.getByRole('dialog')
    // Named by its own heading, so a screen reader says which item is being clarified.
    expect(dialog).toHaveAccessibleName(/clarify: ring the dentist/i)
    // And deliberately NOT modal. The wizard renders inline with no overlay, no backdrop
    // and no focus trap — everything behind it stays on screen and in the tab order — so
    // claiming aria-modal told a screen reader something that was not true. Asserted as an
    // absence so the claim cannot quietly come back without the behaviour behind it.
    expect(dialog).not.toHaveAttribute('aria-modal')
    // Focus must still enter the wizard, or a keyboard user is left on the trigger with no
    // signal that anything opened.
    await waitFor(() => expect(screen.getByRole('heading', { level: 3 })).toHaveFocus())
  })

  it('closes on Escape', async () => {
    const { user, cancelled } = renderDialog()

    await user.keyboard('{Escape}')

    expect(cancelled.count).toBe(1)
  })
})

/**
 * FR-006. The timer is the one stateful step in clarify, so these drive it on fake timers
 * rather than waiting out two real minutes — the countdown is the thing under test, not the
 * wall clock.
 */
describe('the two-minute rule (FR-006)', () => {
  beforeEach(() => {
    // shouldAdvanceTime keeps the rest of the world moving (userEvent's own scheduling, the
    // MSW round trip) while the countdown stays under the test's control. Without it the
    // awaited request never settles and every test here times out.
    vi.useFakeTimers({ shouldAdvanceTime: true })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  /** actionable → single step → the two-minute question. */
  async function reachTheTwoMinuteQuestion() {
    const rendered = renderDialog(makeItem({ id: 5, title: 'ring the dentist' }), {
      advanceTimers: vi.advanceTimersByTime,
    })

    await rendered.user.click(screen.getByRole('button', { name: 'Yes' }))
    await rendered.user.click(screen.getByRole('button', { name: 'Yes' }))

    return rendered
  }

  /**
   * The countdown as a number of seconds.
   *
   * Assertions go through this and compare DELTAS rather than exact strings, because
   * `shouldAdvanceTime` ticks the fake clock along with real time: an exact "0:59" had only
   * about a second of wall-clock headroom between mounting the interval and the assertion.
   * A delta cancels whatever drift accrued before the measurement started.
   */
  function readClock(): number {
    const [minutes, seconds] = screen
      .getByText(/^\d:\d{2}$/)
      .textContent!.split(':')
      .map(Number)

    return minutes * 60 + seconds
  }

  async function startTheTimer() {
    const rendered = await reachTheTwoMinuteQuestion()
    await rendered.user.click(screen.getByRole('button', { name: /do it now/i }))

    return rendered
  }

  it('asks about two minutes before asking about delegation', async () => {
    await reachTheTwoMinuteQuestion()

    // FR-003's order: asking "can someone else do it?" first would let the user hand off
    // something they were about to finish in a minute.
    expect(screen.getByText(/less than two minutes/i)).toBeInTheDocument()
    expect(screen.queryByText('Can someone else do it?')).not.toBeInTheDocument()
  })

  it('puts two minutes on the clock, not some other number', async () => {
    await startTheTimer()

    // Literal seconds, never the imported constant: an assertion written against the
    // constant would follow it if someone changed 120 to 60, and prove nothing.
    expect(readClock()).toBeGreaterThan(115)
    expect(readClock()).toBeLessThanOrEqual(120)
  })

  it('counts down in real time', async () => {
    await startTheTimer()
    const before = readClock()

    await act(async () => {
      vi.advanceTimersByTime(61_000)
    })

    // Exactly 61 seconds of clock for 61 seconds of time — a clock that renders the constant
    // and never moves would pass an assertion on the starting value alone.
    expect(readClock()).toBe(before - 61)
  })

  it('stops at zero and says so, instead of running negative', async () => {
    await startTheTimer()

    await act(async () => {
      vi.advanceTimersByTime(200_000)
    })

    // Clamped, so this one needs no tolerance: past the end there is nothing to drift.
    expect(readClock()).toBe(0)
    expect(screen.getByRole('status')).toHaveTextContent(/time is up/i)
  })

  it('files the item as done when the user finishes inside the timer', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = await startTheTimer()

    await user.click(screen.getByRole('button', { name: 'Done' }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: true,
      twoMinuteOutcome: 'done',
      twoMinuteLoops: 0,
    })
  })

  it('never asks about delegating something the user has already done', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = await startTheTimer()

    await user.click(screen.getByRole('button', { name: 'Done' }))
    await waitFor(() => expect(clarified).toHaveLength(1))

    // The done branch terminates. A delegation answer riding along would tell the backend
    // the user is waiting on someone for work that is already finished.
    expect(sent[0]).not.toHaveProperty('delegable')
    expect(sent[0]).not.toHaveProperty('delegatedTo')
  })

  it('loops the timer and reports how many times more time was asked for', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = await startTheTimer()

    const before = readClock()
    await act(async () => {
      vi.advanceTimersByTime(90_000)
    })
    const runDown = readClock()
    expect(runDown).toBe(before - 90)

    await user.click(screen.getByRole('button', { name: /need more time/i }))
    // A loop restarts the clock. Continuing from where it was would be "more time" in name
    // only, so what matters is that the clock jumped back UP to a fresh two minutes.
    expect(readClock()).toBeGreaterThan(runDown)
    expect(readClock()).toBeGreaterThan(115)

    await user.click(screen.getByRole('button', { name: /need more time/i }))
    await user.click(screen.getByRole('button', { name: 'Done' }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: true,
      twoMinuteOutcome: 'done',
      twoMinuteLoops: 2,
    })
  })

  it('returns a deferred item to the tree rather than filing it', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = await startTheTimer()

    await user.click(screen.getByRole('button', { name: /need more time/i }))
    await user.click(screen.getByRole('button', { name: /file it instead/i }))

    // Deferring is not a destination: the item is still unclarified, so nothing has been
    // sent and the last question is now on screen.
    expect(sent).toHaveLength(0)
    expect(clarified).toHaveLength(0)
    expect(screen.getByText('Can someone else do it?')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /i will do it next/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: true,
      twoMinuteOutcome: 'deferred',
      twoMinuteLoops: 1,
      delegable: false,
    })
  })

  it('still lets a deferred item be delegated', async () => {
    const sent = captureClarifyRequest('delegation')
    const { user, clarified } = await startTheTimer()

    await user.click(screen.getByRole('button', { name: /file it instead/i }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.type(screen.getByLabelText(/who are you waiting on/i), 'Ania — the contract')
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: true,
      twoMinuteOutcome: 'deferred',
      twoMinuteLoops: 0,
      delegable: true,
      delegatedTo: 'Ania — the contract',
    })
  })

  it('forgets a timer the user stepped back out of', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = await startTheTimer()

    await user.click(screen.getByRole('button', { name: /need more time/i }))
    await user.click(screen.getByRole('button', { name: /file it instead/i }))
    // Back to the two-minute question, and this time answer it the other way.
    await user.click(screen.getByRole('button', { name: 'Back' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /i will do it next/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    // No timer ran on the path the user ended up taking, so none may be reported — a stale
    // deferral here would record a loop against a question that was answered "no".
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: false,
      delegable: false,
    })
  })
})

describe('an error message outlives only its own attempt', () => {
  it('is cleared when the user moves to another question', async () => {
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /delegate/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/who you are waiting on/i)

    await user.click(screen.getByRole('button', { name: 'Back' }))

    // The message described an attempt that is over; carrying it into "can someone else do
    // it?" reads as a complaint about that question instead.
    expect(screen.getByRole('alert')).toBeEmptyDOMElement()
  })
})

describe('backing out of the quick-route', () => {
  it('does not let an abandoned destination hijack a later tree submit', async () => {
    // Found in implementation review. The quick-route target survived a walk back through
    // "is it actionable?", so a user who changed their mind and then answered the questions
    // properly had their answers replaced by {quickRouteBucket, delegatedTo}. The item
    // still landed in Delegation with the right note — nothing looked wrong — while the
    // deferral, the loop count and the FR-006 log line were all silently dropped.
    const sent = captureClarifyRequest('delegation')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: /skip the questions/i }))
    await user.click(screen.getByRole('button', { name: 'Delegation' }))
    await user.click(screen.getByRole('button', { name: 'Back' }))
    await user.click(screen.getByRole('button', { name: 'Back' }))

    // Now the real tree, all the way through a deferred timer.
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /do it now/i }))
    await user.click(screen.getByRole('button', { name: /file it instead/i }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.type(screen.getByLabelText(/who are you waiting on/i), 'Ania')
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      twoMinutes: true,
      twoMinuteOutcome: 'deferred',
      twoMinuteLoops: 0,
      delegable: true,
      delegatedTo: 'Ania',
    })
    // The answers the user actually gave — not a quick-route they abandoned.
    expect(sent[0]).not.toHaveProperty('quickRouteBucket')
  })
})

describe('the delegation input describes its own error state', () => {
  it('is flagged invalid only while an error stands, and names its live region', async () => {
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))

    const input = screen.getByLabelText(/who are you waiting on/i)
    expect(input).toHaveAttribute('aria-invalid', 'false')
    expect(input).toHaveAttribute('aria-describedby', 'clarify-error')
    expect(document.getElementById('clarify-error')).not.toBeNull()

    await user.click(screen.getByRole('button', { name: /delegate/i }))
    await screen.findByRole('alert')

    expect(input).toHaveAttribute('aria-invalid', 'true')
  })
})
