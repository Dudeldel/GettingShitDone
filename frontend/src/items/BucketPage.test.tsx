import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { GtdBucket } from '../api'
import { AuthProvider } from '../auth/AuthContext'
import { makeItem, server } from '../test/server'
import { BucketPage } from './BucketPage'
import { BUCKETS, bucketLabel } from './buckets'

/**
 * Mounts the real route so `useParams` reads a real `:bucket`, and an invalid URL exercises
 * the real redirect rather than a stub.
 */
function renderBucket(path: string) {
  return {
    user: userEvent.setup(),
    ...render(
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <Routes>
            <Route path="/" element={<p>Inbox screen</p>} />
            <Route path="/bucket/:bucket" element={<BucketPage />} />
          </Routes>
        </AuthProvider>
      </MemoryRouter>,
    ),
  }
}

/** Answers the list endpoint only for the bucket asked for, so a wrong query shows nothing. */
function listOnlyFor(bucket: GtdBucket, items: ReturnType<typeof makeItem>[]) {
  server.use(
    http.get('*/api/items', ({ request }) => {
      const asked = new URL(request.url).searchParams.get('bucket')

      return HttpResponse.json(asked === bucket ? items : [])
    }),
  )
}

describe('the eight bucket views (FR-009)', () => {
  // All eight are the "GTD out-of-the-box" promise, so none may be unreachable or blank.
  it.each(BUCKETS.filter((b) => b !== 'inbox'))('renders the %s bucket', async (bucket) => {
    listOnlyFor(bucket, [makeItem({ id: 1, title: 'an item here', bucket })])

    renderBucket(`/bucket/${bucket}`)

    expect(await screen.findByText('an item here')).toBeInTheDocument()
    // The heading must name the bucket you are actually in: mislabelling Next Actions as
    // "Trash" is not cosmetic when the only destructive control lives one page away.
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(bucketLabel(bucket))
    // aria-current, so the nav says which list is open rather than only looking different.
    expect(screen.getByRole('link', { name: bucketLabel(bucket) })).toHaveAttribute(
      'aria-current',
      'page',
    )
  })

  it('points each nav link at its own bucket', () => {
    listOnlyFor('reference', [])

    renderBucket('/bucket/reference')

    // A link COUNT is not reachability: every link could point at the Inbox and still count
    // eight. Assert the targets.
    for (const bucket of BUCKETS) {
      expect(screen.getByRole('link', { name: bucketLabel(bucket) })).toHaveAttribute(
        'href',
        bucket === 'inbox' ? '/' : `/bucket/${bucket}`,
      )
    }
  })

  it('offers every bucket from any bucket', async () => {
    listOnlyFor('reference', [])

    renderBucket('/bucket/reference')

    const nav = await screen.findByRole('navigation', { name: /buckets/i })
    expect(nav).toBeInTheDocument()
    // Eight links, one per bucket — a missing one is a list you can only reach by typing.
    expect(screen.getAllByRole('link')).toHaveLength(BUCKETS.length)
  })

  it('sends an unknown bucket back to the Inbox rather than showing an empty list', async () => {
    // A typo in the URL must not read as "you have nothing here" — that is a different and
    // alarming claim than "that bucket does not exist".
    renderBucket('/bucket/not_a_bucket')

    expect(await screen.findByText('Inbox screen')).toBeInTheDocument()
  })

  it('never offers a Clarify action from a destination', async () => {
    // Re-filing an already-bucketed item is FR-010, parked for v2.
    listOnlyFor('next_actions', [makeItem({ id: 1, title: 'already routed', bucket: 'next_actions' })])

    renderBucket('/bucket/next_actions')
    await screen.findByText('already routed')

    expect(screen.queryByRole('button', { name: /clarify/i })).not.toBeInTheDocument()
  })

  it('explains a failed load instead of echoing the raw transport error', async () => {
    server.use(http.get('*/api/items', () => HttpResponse.error()))

    renderBucket('/bucket/projects')

    const error = await screen.findByText(/could not load this bucket/i)
    expect(error).toHaveTextContent(/could not reach the server/i)
    expect(error).not.toHaveTextContent(/failed to fetch/i)
  })
})

describe('emptying the Trash', () => {
  it('is offered only in the Trash, never anywhere else', async () => {
    // The whole point of the slice's design: one destructive path, reachable from one view.
    for (const bucket of BUCKETS.filter((b) => b !== 'inbox' && b !== 'trash')) {
      listOnlyFor(bucket, [makeItem({ id: 1, title: 'an item', bucket })])
      const { unmount } = renderBucket(`/bucket/${bucket}`)
      await screen.findByText('an item')

      expect(screen.queryByRole('button', { name: /empty the trash/i })).not.toBeInTheDocument()
      unmount()
    }

    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    renderBucket('/bucket/trash')
    await screen.findByText('junk')

    expect(screen.getByRole('button', { name: /empty the trash/i })).toBeInTheDocument()
  })

  it('is not offered when the Trash is already empty', async () => {
    listOnlyFor('trash', [])

    renderBucket('/bucket/trash')
    // The Trash says it is the Trash that is empty — not the Inbox.
    await waitFor(() => expect(screen.getByText('Nothing in Trash.')).toBeInTheDocument())
    expect(screen.queryByText(/your inbox is empty/i)).not.toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /empty the trash/i })).not.toBeInTheDocument()
  })

  it('says how many and that it cannot be undone before discarding', async () => {
    listOnlyFor('trash', [
      makeItem({ id: 1, title: 'junk one', bucket: 'trash' }),
      makeItem({ id: 2, title: 'junk two', bucket: 'trash' }),
    ])

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk one')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))

    const warning = await screen.findByRole('alert', { name: /discard confirmation/i })
    expect(warning).toHaveTextContent(/2 items/i)
    expect(warning).toHaveTextContent(/cannot be undone/i)
  })

  it('discards nothing when the user backs out', async () => {
    let called = false
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    server.use(
      http.delete('*/api/trash', () => {
        called = true

        return HttpResponse.json({ deleted: 1 })
      }),
    )

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))
    await user.click(screen.getByRole('button', { name: /keep them/i }))

    expect(called).toBe(false)
    expect(screen.getByText('junk')).toBeInTheDocument()
  })

  it('empties the list once the discard is confirmed', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    server.use(http.delete('*/api/trash', () => HttpResponse.json({ deleted: 1 })))

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))
    await user.click(screen.getByRole('button', { name: /yes, discard them/i }))

    await waitFor(() => expect(screen.queryByText('junk')).not.toBeInTheDocument())
  })

  it('keeps the items and explains itself when the discard fails', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    server.use(
      http.delete('*/api/trash', () =>
        HttpResponse.json({ message: 'The Trash could not be emptied.' }, { status: 500 }),
      ),
    )

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))
    await user.click(screen.getByRole('button', { name: /yes, discard them/i }))

    // A failed purge must never look like a successful one.
    // Through the label the region carries, not its message text — that label exists so this
    // assertion does not have to know the copy.
    expect(await screen.findByRole('alert', { name: /trash purge error/i })).toHaveTextContent(
      /could not be emptied/i,
    )
    expect(screen.getByText('junk')).toBeInTheDocument()
  })
})

describe('the destructive control', () => {
  it('reports the count the SERVER discarded, not the stale client one', async () => {
    // The list was fetched on mount; anything reaching the Trash since makes items.length
    // stale. On an operation with no undo the reported number must be the real one.
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    server.use(http.delete('*/api/trash', () => HttpResponse.json({ deleted: 4 })))

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))
    await user.click(screen.getByRole('button', { name: /yes, discard them/i }))

    expect(await screen.findByText(/discarded 4 items/i)).toBeInTheDocument()
  })

  it('focuses the safe choice, not the destructive one', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))

    // Revealing the confirmation unmounts the trigger; without an explicit move focus falls to
    // <body>. It must land on "Keep them" so a reflexive Enter keeps the items.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /keep them/i })).toHaveFocus(),
    )
  })

  it('backs out on Escape and clears a stale failure message', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'junk', bucket: 'trash' })])
    server.use(
      http.delete('*/api/trash', () =>
        HttpResponse.json({ message: 'The Trash could not be emptied.' }, { status: 500 }),
      ),
    )

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('junk')
    await user.click(screen.getByRole('button', { name: /empty the trash/i }))
    await user.click(screen.getByRole('button', { name: /yes, discard them/i }))
    await screen.findByRole('alert', { name: /trash purge error/i })

    await user.keyboard('{Escape}')

    expect(screen.queryByRole('button', { name: /yes, discard them/i })).not.toBeInTheDocument()
    // The error described an attempt that is over; it must not outlive it.
    expect(screen.getByRole('alert', { name: /trash purge error/i })).toBeEmptyDOMElement()
  })
})

describe('the Delegation view (FR-007)', () => {
  it('shows who you are waiting on', async () => {
    // The note IS the bucket's value — without it you know something is delegated but not whom
    // to chase. S-02 stored it; nothing displayed it until now.
    listOnlyFor('delegation', [
      makeItem({
        id: 1,
        title: 'chase the invoice',
        bucket: 'delegation',
        delegatedTo: 'Ania — sent the contract on Tuesday',
      }),
    ])

    renderBucket('/bucket/delegation')

    expect(await screen.findByText(/ania — sent the contract on tuesday/i)).toBeInTheDocument()
  })
})

describe('a completed item in Next Actions (FR-006)', () => {
  it('is listed in its bucket, marked done', async () => {
    listOnlyFor('next_actions', [
      makeItem({
        id: 1,
        title: 'reply to the landlord',
        bucket: 'next_actions',
        completedAt: '2026-09-14T10:05:00+00:00',
      }),
    ])

    renderBucket('/bucket/next_actions')

    // The decision this slice recorded, asserted end to end: done is state, not a ninth
    // bucket. The item stays where it was filed and says it is finished.
    expect(await screen.findByText('reply to the landlord')).toBeInTheDocument()
    expect(screen.getByText(/done/i)).toBeInTheDocument()
  })
})
