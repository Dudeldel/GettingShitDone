import { render, screen, waitFor, within } from '@testing-library/react'
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
    // Clarify stays Inbox-only; re-filing a bucketed item has its own verb and its own
    // button. This asserts the boundary, not the absence of any action.
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
  it('is out of the default view but still in its bucket, marked done', async () => {
    // S-03 kept completed items visible, reasoning that vanishing is indistinguishable from
    // being lost. This slice hides them by default instead — so what is asserted here is BOTH
    // halves of that reversal: they are gone from the default view, the empty state says so
    // rather than claiming the bucket is empty, and turning the toggle on brings them back
    // exactly where they were filed. "Done is state, not a ninth bucket" still holds.
    listOnlyFor('next_actions', [
      makeItem({
        id: 1,
        title: 'reply to the landlord',
        bucket: 'next_actions',
        completedAt: '2026-09-14T10:05:00+00:00',
      }),
    ])

    const { user } = renderBucket('/bucket/next_actions')

    expect(await screen.findByText(/1 completed and hidden/i)).toBeInTheDocument()
    expect(screen.queryByText('reply to the landlord')).not.toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: /show completed/i }))

    expect(screen.getByText('reply to the landlord')).toBeInTheDocument()
    expect(screen.getByText('✓ Done')).toBeInTheDocument()
  })

  it('never claims the bucket is empty when it only looks empty', async () => {
    // The distinction S-05 already fought for on the load-error path: a list that is hiding
    // things and a list that has nothing must not read the same.
    listOnlyFor('projects', [
      makeItem({ id: 1, title: 'zaplanować urlop', bucket: 'projects', completedAt: '2026-09-14T10:05:00+00:00' }),
    ])

    renderBucket('/bucket/projects')

    expect(await screen.findByText(/1 completed and hidden/i)).toBeInTheDocument()
    expect(screen.queryByText('Nothing in Projects.')).not.toBeInTheDocument()
  })
})

describe('marking an item done from its bucket', () => {
  it('ticks the box, tells the user where it went, and persists', async () => {
    const sent: Array<Record<string, unknown>> = []
    listOnlyFor('next_actions', [makeItem({ id: 1, title: 'oddzwonić do Marka', bucket: 'next_actions' })])
    server.use(
      http.post('*/api/items/:id/complete', async ({ request }) => {
        sent.push((await request.json()) as Record<string, unknown>)

        return HttpResponse.json(
          makeItem({ id: 1, title: 'oddzwonić do Marka', bucket: 'next_actions', completedAt: '2026-09-14T12:00:00+00:00' }),
        )
      }),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await screen.findByText('oddzwonić do Marka')

    await user.click(screen.getByRole('checkbox', { name: /mark "oddzwonić do marka" done/i }))

    // The compensation for hiding completed items: the row leaves the default view, so the
    // screen has to say so. Vanishing silently is what S-03 refused, and hiding without a
    // word would be the same thing wearing a toggle.
    expect(await screen.findByRole('status', { name: /item action status/i })).toHaveTextContent(
      /marked "oddzwonić do marka" done.*show completed/i,
    )
    expect(sent[0]).toEqual({ completed: true })
  })

  it('un-ticks it again', async () => {
    const sent: Array<Record<string, unknown>> = []
    listOnlyFor('next_actions', [
      makeItem({ id: 1, title: 'oddzwonić', bucket: 'next_actions', completedAt: '2026-09-14T12:00:00+00:00' }),
    ])
    server.use(
      http.post('*/api/items/:id/complete', async ({ request }) => {
        sent.push((await request.json()) as Record<string, unknown>)

        return HttpResponse.json(makeItem({ id: 1, title: 'oddzwonić', bucket: 'next_actions' }))
      }),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await user.click(await screen.findByRole('checkbox', { name: /show completed/i }))
    await user.click(screen.getByRole('checkbox', { name: /mark "oddzwonić" done/i }))

    expect(sent[0]).toEqual({ completed: false })
    expect(await screen.findByRole('status', { name: /item action status/i })).toHaveTextContent(
      /no longer marked done/i,
    )
  })

  it('puts the tick back when the write fails', async () => {
    // A box that stays ticked after a failed write is a lie about persisted state — and the
    // optimistic update is what makes that failure mode possible in the first place.
    listOnlyFor('next_actions', [makeItem({ id: 1, title: 'oddzwonić', bucket: 'next_actions' })])
    server.use(
      http.post('*/api/items/:id/complete', () =>
        HttpResponse.json({ message: 'The item could not be saved. Please try again.' }, { status: 500 }),
      ),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await screen.findByText('oddzwonić')

    await user.click(screen.getByRole('checkbox', { name: /mark "oddzwonić" done/i }))

    expect(await screen.findByRole('alert', { name: /item action error/i })).toHaveTextContent(
      /could not be saved/i,
    )
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /mark "oddzwonić" done/i })).not.toBeChecked(),
    )
  })

  it('is not offered where done would mean nothing', async () => {
    // FR-004 split actionable from not. The backend refuses these with a 422 anyway, so a
    // checkbox here would be a control guaranteed to fail.
    for (const bucket of ['reference', 'someday_maybe', 'trash'] as const) {
      listOnlyFor(bucket, [makeItem({ id: 1, title: 'nie-zobowiązanie', bucket })])
      const { unmount } = renderBucket(`/bucket/${bucket}`)
      await screen.findByText('nie-zobowiązanie')

      expect(screen.queryByRole('checkbox', { name: /done/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('checkbox', { name: /show completed/i })).not.toBeInTheDocument()
      unmount()
    }
  })
})

describe('moving an item to another bucket', () => {
  it('offers every destination except the Inbox and the one it is already in', async () => {
    listOnlyFor('someday_maybe', [makeItem({ id: 1, title: 'kurs hiszpańskiego', bucket: 'someday_maybe' })])

    const { user } = renderBucket('/bucket/someday_maybe')
    await screen.findByText('kurs hiszpańskiego')

    await user.click(screen.getByRole('button', { name: /move "kurs hiszpańskiego"/i }))

    const dialog = await screen.findByRole('dialog')
    // Items arrive in the Inbox; they are not filed there. And offering the bucket you are
    // already looking at is an action that does nothing.
    expect(within(dialog).queryByRole('button', { name: /^inbox$/i })).not.toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: /^someday \/ maybe$/i })).not.toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Next Actions' })).toBeInTheDocument()
  })

  it('moves it, drops it from this list, and says where it went', async () => {
    const sent: Array<Record<string, unknown>> = []
    listOnlyFor('someday_maybe', [makeItem({ id: 1, title: 'kurs hiszpańskiego', bucket: 'someday_maybe' })])
    server.use(
      http.post('*/api/items/:id/refile', async ({ request }) => {
        sent.push((await request.json()) as Record<string, unknown>)

        return HttpResponse.json(makeItem({ id: 1, title: 'kurs hiszpańskiego', bucket: 'next_actions' }))
      }),
    )

    const { user } = renderBucket('/bucket/someday_maybe')
    await screen.findByText('kurs hiszpańskiego')
    await user.click(screen.getByRole('button', { name: /move "kurs hiszpańskiego"/i }))
    await user.click(await screen.findByRole('button', { name: 'Next Actions' }))

    expect(sent[0]).toEqual({ bucket: 'next_actions' })
    await waitFor(() =>
      expect(screen.queryByText('kurs hiszpańskiego')).not.toBeInTheDocument(),
    )
    // It belongs to another list now. Without this line that is indistinguishable from the
    // item having been destroyed.
    expect(screen.getByRole('status', { name: /item action status/i })).toHaveTextContent(
      /moved "kurs hiszpańskiego" to next actions/i,
    )
  })

  it('keeps the item here and explains itself when the move fails', async () => {
    listOnlyFor('reference', [makeItem({ id: 1, title: 'artykuł', bucket: 'reference' })])
    server.use(
      http.post('*/api/items/:id/refile', () =>
        HttpResponse.json({ message: 'That item is still in the Inbox.' }, { status: 422 }),
      ),
    )

    const { user } = renderBucket('/bucket/reference')
    await screen.findByText('artykuł')
    await user.click(screen.getByRole('button', { name: /move "artykuł"/i }))
    await user.click(await screen.findByRole('button', { name: 'Trash' }))

    expect(await screen.findByRole('alert', { name: /move error/i })).toHaveTextContent(
      /still in the inbox/i,
    )
    expect(screen.getByText('artykuł')).toBeInTheDocument()
  })

  it('sends one write per row even when the box is clicked twice', async () => {
    // Only reachable with "Show completed" on: in the default view the optimistic write hides
    // the row immediately, taking the checkbox with it. With the row still on screen, a second
    // click lands while the first write is in flight — and two writes resolve in arrival order,
    // not send order, so the box could settle on the stale answer, while the rollback would
    // restore the timestamp the FIRST optimistic write invented rather than the real one.
    let sent = 0
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    listOnlyFor('projects', [makeItem({ id: 1, title: 'wymienić piec', bucket: 'projects' })])
    server.use(
      http.post('*/api/items/:id/complete', async () => {
        sent += 1
        await held

        return HttpResponse.json(makeItem({
          id: 1, title: 'wymienić piec', bucket: 'projects', completedAt: '2026-09-14T10:00:00+00:00',
        }))
      }),
    )

    const { user } = renderBucket('/bucket/projects')
    await screen.findByText('wymienić piec')
    await user.click(screen.getByRole('checkbox', { name: 'Show completed' }))
    const box = screen.getByRole('checkbox', { name: /mark "wymienić piec" done/i })

    await user.click(box)
    await waitFor(() => expect(box).toBeDisabled())
    await user.click(box)

    expect(sent).toBe(1)

    release()
    await waitFor(() => expect(box).not.toBeDisabled())
    expect(sent).toBe(1)
    expect(box).toBeChecked()
  })

  it('keeps focus on the page when completing a row hides it', async () => {
    // The same defect the picker had, on the path the picker fix did not cover: with completed
    // items hidden by default, ticking the box unmounts the checkbox the user is standing on.
    listOnlyFor('next_actions', [makeItem({ id: 1, title: 'babababa', bucket: 'next_actions' })])
    server.use(
      http.post('*/api/items/:id/complete', () =>
        HttpResponse.json(makeItem({
          id: 1, title: 'babababa', bucket: 'next_actions', completedAt: '2026-09-14T10:00:00+00:00',
        })),
      ),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await screen.findByText('babababa')

    await user.click(screen.getByRole('checkbox', { name: /mark "babababa" done/i }))

    await waitFor(() => expect(screen.queryByText('babababa')).not.toBeInTheDocument())
    expect(screen.getByRole('heading', { name: 'Next Actions', level: 1 })).toHaveFocus()
  })

  it('offers no Move button in the Inbox, where every destination is refused', async () => {
    // Reachable only by typing /bucket/inbox, but the rule is the checkbox's rule: a control
    // whose request the server always refuses should be absent, not present and doomed to 422.
    listOnlyFor('inbox', [makeItem({ id: 1, title: 'nieprzemyślany pomysł', bucket: 'inbox' })])

    renderBucket('/bucket/inbox')
    await screen.findByText('nieprzemyślany pomysł')

    expect(screen.queryByRole('button', { name: /move "nieprzemyślany pomysł"/i })).not.toBeInTheDocument()
  })

  it('hands focus back to the row it came from when the picker is dismissed', async () => {
    // Found by walking the app, not by a test: Escape closed the panel and left focus on
    // <body>, so a keyboard user was dropped at the top of the document and had to tab
    // through the entire list to get back to the row they were standing on.
    listOnlyFor('reference', [makeItem({ id: 1, title: 'artykuł', bucket: 'reference' })])

    const { user } = renderBucket('/bucket/reference')
    await screen.findByText('artykuł')
    const trigger = screen.getByRole('button', { name: /move "artykuł"/i })
    await user.click(trigger)
    await screen.findByRole('dialog')

    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(trigger).toHaveFocus()
  })

  it('falls back to the heading when the row vanishes while its picker is open', async () => {
    // Reachable: the list stays live behind the picker, so checking the row done filters it
    // out of the default view and detaches the button that opened the panel. Focusing a
    // detached node puts focus back on <body> — the very thing this is here to prevent.
    listOnlyFor('next_actions', [makeItem({ id: 1, title: 'babababa', bucket: 'next_actions' })])
    server.use(
      http.post('*/api/items/:id/complete', () =>
        HttpResponse.json(makeItem({
          id: 1, title: 'babababa', bucket: 'next_actions', completedAt: '2026-09-14T10:00:00+00:00',
        })),
      ),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await screen.findByText('babababa')
    const trigger = screen.getByRole('button', { name: /move "babababa"/i })
    await user.click(trigger)
    await screen.findByRole('dialog')

    await user.click(screen.getByRole('checkbox', { name: /mark "babababa" done/i }))
    await waitFor(() => expect(trigger.isConnected).toBe(false))

    // Cancel rather than Escape: the row took focus with it when it unmounted, so a keypress
    // no longer lands inside the panel. The button is what is still reachable from here.
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /cancel/i }))

    expect(screen.getByRole('heading', { name: 'Next Actions', level: 1 })).toHaveFocus()
  })

  it('moves focus to the heading when the item it was moving leaves the list', async () => {
    // The trigger unmounts with its row, so there is nothing to restore to. Focus must still
    // land somewhere that names where the user is, rather than on <body>.
    listOnlyFor('reference', [makeItem({ id: 1, title: 'artykuł', bucket: 'reference' })])
    server.use(
      http.post('*/api/items/:id/refile', () =>
        HttpResponse.json(makeItem({ id: 1, title: 'artykuł', bucket: 'trash' })),
      ),
    )

    const { user } = renderBucket('/bucket/reference')
    await screen.findByText('artykuł')
    await user.click(screen.getByRole('button', { name: /move "artykuł"/i }))
    await user.click(await screen.findByRole('button', { name: 'Trash' }))

    await waitFor(() => expect(screen.queryByText('artykuł')).not.toBeInTheDocument())
    expect(screen.getByRole('heading', { name: 'Reference', level: 1 })).toHaveFocus()
  })

  it('can take an item back out of the Trash, because only emptying it is final', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'stary newsletter', bucket: 'trash' })])
    server.use(
      http.post('*/api/items/:id/refile', () =>
        HttpResponse.json(makeItem({ id: 1, title: 'stary newsletter', bucket: 'reference' })),
      ),
    )

    const { user } = renderBucket('/bucket/trash')
    await screen.findByText('stary newsletter')
    await user.click(screen.getByRole('button', { name: /move "stary newsletter"/i }))
    await user.click(await screen.findByRole('button', { name: 'Reference' }))

    await waitFor(() => expect(screen.queryByText('stary newsletter')).not.toBeInTheDocument())
  })
})

describe('the Calendar view is derived, not a bucket query (FR-011)', () => {
  it('names each row\'s home bucket, because most are not filed in Calendar', async () => {
    // Without this the screen shows a Next Action under a heading saying "Calendar" with no
    // explanation, which reads as a bug rather than as a view.
    listOnlyFor('calendar', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
      makeItem({ id: 2, title: 'dentysta', bucket: 'calendar' }),
    ])

    renderBucket('/bucket/calendar')

    expect(await screen.findByText('wyslac raport')).toBeInTheDocument()
    expect(screen.getByText(/In Next Actions/)).toBeInTheDocument()
    expect(screen.getByText(/In Calendar/)).toBeInTheDocument()
  })

  it('does not name the home bucket on an ordinary bucket screen', async () => {
    listOnlyFor('next_actions', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
    ])

    renderBucket('/bucket/next_actions')

    expect(await screen.findByText('wyslac raport')).toBeInTheDocument()
    // Every row here IS filed here, so the label would be noise on all eight other screens.
    expect(screen.queryByText(/In Next Actions/)).not.toBeInTheDocument()
  })

  it('explains the rule in its empty state instead of claiming the list is a bucket', async () => {
    listOnlyFor('calendar', [])

    renderBucket('/bucket/calendar')

    expect(await screen.findByText(/items you file here, plus anything actionable with a date/i))
      .toBeInTheDocument()
  })

  it('keeps a dated item on screen when it is moved to another action bucket', async () => {
    // The row's membership here is DERIVED. A dated Next Action moved to Projects is still an
    // actionable commitment with a date, so it belongs on this screen afterwards — filtering
    // it out (which every other bucket correctly does) would disagree with the server.
    listOnlyFor('calendar', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
    ])
    server.use(
      http.post('*/api/items/1/refile', () =>
        HttpResponse.json(
          makeItem({ id: 1, title: 'wyslac raport', bucket: 'projects', dueDate: '2026-09-30' }),
        ),
      ),
    )

    const { user } = renderBucket('/bucket/calendar')
    await user.click(await screen.findByRole('button', { name: /Move "wyslac raport"/ }))
    await user.click(screen.getByRole('button', { name: 'Projects' }))

    await waitFor(() => expect(screen.getByText(/In Projects/)).toBeInTheDocument())
    expect(screen.getByText('wyslac raport')).toBeInTheDocument()
  })

  it('drops a dated item from the calendar when it is moved somewhere dates mean nothing', async () => {
    listOnlyFor('calendar', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
    ])
    server.use(
      http.post('*/api/items/1/refile', () =>
        HttpResponse.json(
          makeItem({ id: 1, title: 'wyslac raport', bucket: 'reference', dueDate: '2026-09-30' }),
        ),
      ),
    )

    const { user } = renderBucket('/bucket/calendar')
    await user.click(await screen.findByRole('button', { name: /Move "wyslac raport"/ }))
    await user.click(screen.getByRole('button', { name: 'Reference' }))

    await waitFor(() => expect(screen.queryByText('wyslac raport')).not.toBeInTheDocument())
  })

  it('offers Calendar as a destination for a row that only appears here because of its date', async () => {
    // The picker excludes the ITEM's bucket, not the page's. Passing the page's would hide
    // Calendar — a perfectly legal move — and offer Next Actions, where the item already is.
    listOnlyFor('calendar', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
    ])

    const { user } = renderBucket('/bucket/calendar')
    await user.click(await screen.findByRole('button', { name: /Move "wyslac raport"/ }))

    const picker = screen.getByRole('dialog')
    expect(within(picker).getByRole('button', { name: 'Calendar' })).toBeInTheDocument()
    expect(within(picker).queryByRole('button', { name: 'Next Actions' })).not.toBeInTheDocument()
  })
})

describe('editing an item from a bucket view', () => {
  it('does not offer Edit in the Trash, where the server refuses it', async () => {
    listOnlyFor('trash', [makeItem({ id: 1, title: 'stary newsletter', bucket: 'trash' })])

    renderBucket('/bucket/trash')

    expect(await screen.findByText('stary newsletter')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument()
  })

  it('splices the saved item into the list without refetching', async () => {
    listOnlyFor('next_actions', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions' }),
    ])
    server.use(
      http.post('*/api/items/1/attributes', () =>
        HttpResponse.json(
          makeItem({
            id: 1,
            title: 'wyslac raport',
            bucket: 'next_actions',
            context: '@computer',
          }),
        ),
      ),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await user.click(await screen.findByRole('button', { name: 'Edit "wyslac raport"' }))
    await user.type(screen.getByLabelText('Context'), '@computer')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    // The row shows the server's answer, not the optimistic guess — and no second GET runs.
    expect(await screen.findByText(/@computer/)).toBeInTheDocument()
    expect(screen.getByRole('status', { name: 'Item action status' })).toHaveTextContent(
      /Saved changes/,
    )
  })

  it('returns focus to the Edit button when the editor is dismissed', async () => {
    // S-11's F5 was exactly this defect on another control: focus dropping to <body> leaves a
    // keyboard user tabbing from the top of the document.
    listOnlyFor('next_actions', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions' }),
    ])

    const { user } = renderBucket('/bucket/next_actions')
    const edit = await screen.findByRole('button', { name: 'Edit "wyslac raport"' })
    await user.click(edit)
    await user.keyboard('{Escape}')

    await waitFor(() => expect(edit).toHaveFocus())
  })

  it('takes a row off the calendar when its date is cleared', async () => {
    // Membership here is derived, so an edit can remove a row the same way a re-file can —
    // and the status line has to say so, or it reads as the item having been lost.
    listOnlyFor('calendar', [
      makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: '2026-09-30' }),
    ])
    server.use(
      http.post('*/api/items/1/attributes', () =>
        HttpResponse.json(
          makeItem({ id: 1, title: 'wyslac raport', bucket: 'next_actions', dueDate: null }),
        ),
      ),
    )

    const { user } = renderBucket('/bucket/calendar')
    await user.click(await screen.findByRole('button', { name: 'Edit "wyslac raport"' }))
    await user.clear(screen.getByLabelText('Due date'))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(screen.queryByText('wyslac raport')).not.toBeInTheDocument())
    expect(screen.getByRole('status', { name: 'Item action status' })).toHaveTextContent(
      /no longer on the calendar/,
    )
  })

  it('does not carry one item\'s values into the editor for another', async () => {
    // A found-in-review defect, kept as a regression test. The panel seeds its fields with
    // useState, which runs only on mount, and every row's Edit button stays live while it is
    // open. Without a key React reuses the instance: the heading follows the new item while
    // the inputs keep the old one's values, and Save writes those onto the new item's id —
    // silent cross-item corruption with no error anywhere.
    listOnlyFor('next_actions', [
      makeItem({ id: 1, title: 'item A', bucket: 'next_actions', context: '@AAA' }),
      makeItem({ id: 2, title: 'item B', bucket: 'next_actions', context: '@BBB' }),
    ])
    const sent: { id?: string; body?: Record<string, unknown> } = {}
    server.use(
      http.post('*/api/items/:id/attributes', async ({ params, request }) => {
        sent.id = String(params.id)
        sent.body = (await request.json()) as Record<string, unknown>

        return HttpResponse.json(makeItem({ id: Number(params.id), bucket: 'next_actions' }))
      }),
    )

    const { user } = renderBucket('/bucket/next_actions')
    await user.click(await screen.findByRole('button', { name: 'Edit "item A"' }))
    expect(screen.getByLabelText('Context')).toHaveValue('@AAA')

    // Switch rows WITHOUT closing: this is the path that reuses the instance.
    await user.click(screen.getByRole('button', { name: 'Edit "item B"' }))
    expect(screen.getByLabelText('Context')).toHaveValue('@BBB')

    // And the write must carry B's values to B, not A's.
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(sent.id).toBe('2'))
    expect(sent.body?.context).toBe('@BBB')
  })
})
