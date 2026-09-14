import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import type { GtdBucket, Item } from '../api'
import { makeItem, server } from '../test/server'
import { ClarifyDialog } from './ClarifyDialog'

/**
 * Every expected destination below comes from the PRD requirement named in the test, not
 * from reading ClarifyDialog or ClarifyDecision. The backend owns the routing; what this
 * file pins is that the UI asks the questions in the enforced order and sends the answers
 * that describe the branch the user actually took.
 */
function renderDialog(item: Item = makeItem({ id: 5, title: 'ring the dentist' })) {
  const clarified: Item[] = []
  const cancelled = { count: 0 }

  return {
    user: userEvent.setup(),
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

  it('does not ask about the two-minute rule — that question belongs to S-03', async () => {
    const { user } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))

    expect(screen.queryByText(/2 min|two minute/i)).not.toBeInTheDocument()
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

    // Three Yes answers to reach the note: actionable → single step → delegable.
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.type(screen.getByLabelText(/who are you waiting on/i), 'Ania — the contract')
    await user.click(screen.getByRole('button', { name: /delegate/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({
      actionable: true,
      singleStep: true,
      delegable: true,
      delegatedTo: 'Ania — the contract',
    })
  })

  it('routes a single step nobody else can take to Next Actions (FR-008)', async () => {
    const sent = captureClarifyRequest('next_actions')
    const { user, clarified } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: /i will do it next/i }))

    await waitFor(() => expect(clarified).toHaveLength(1))
    expect(sent[0]).toEqual({ actionable: true, singleStep: true, delegable: false })
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
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    // Focus must enter the wizard, or a keyboard user is left on the trigger with no
    // signal that anything opened.
    await waitFor(() => expect(screen.getByRole('heading', { level: 3 })).toHaveFocus())
  })

  it('closes on Escape', async () => {
    const { user, cancelled } = renderDialog()

    await user.keyboard('{Escape}')

    expect(cancelled.count).toBe(1)
  })
})
