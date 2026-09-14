import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { Item } from '../api'
import { AuthProvider } from '../auth/AuthContext'
import { makeItem, server } from '../test/server'
import { InboxPage } from './InboxPage'

function renderPage() {
  return {
    user: userEvent.setup(),
    // MemoryRouter because InboxPage now renders BucketNav, whose <Link>s need a router.
    ...render(
      <MemoryRouter>
        <AuthProvider>
          <InboxPage />
        </AuthProvider>
      </MemoryRouter>,
    ),
  }
}

/**
 * A GET handler that will not answer until the returned release() is called. Ordering is
 * controlled by handler resolution rather than by sleeping, so the sequence is the same
 * under --parallel and on a loaded CI box.
 */
function gatedListHandler(body: Item[]) {
  let release!: () => void
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })

  server.use(
    http.get('*/api/items', async () => {
      await gate

      return HttpResponse.json(body)
    }),
  )

  return () => release()
}

async function captureIdea(user: ReturnType<typeof userEvent.setup>, title: string) {
  await user.type(await screen.findByLabelText(/catch an idea/i), title)
  await user.click(screen.getByRole('button', { name: /capture/i }))
  await screen.findByText(/saved to your inbox/i)
}

describe('a capture that lands while the Inbox is still loading', () => {
  /**
   * The regression guard for impl-review ph3 F1, which shipped as a CRITICAL and has never
   * had a test. The initial GET cannot contain an item created after it was issued, so
   * replacing the array with the server's answer erased the capture: "Saved to your Inbox."
   * rendered directly above "Your Inbox is empty." while the row sat in the database.
   */
  it('survives the initial load resolving afterwards with a pre-capture snapshot', async () => {
    const releaseList = gatedListHandler([makeItem({ id: 1, title: 'captured yesterday' })])
    server.use(
      http.post('*/api/items', () =>
        HttpResponse.json(makeItem({ id: 2, title: 'captured just now' }), { status: 201 }),
      ),
    )

    const { user } = renderPage()
    await captureIdea(user, 'captured just now')

    // Only now does the list answer — and it cannot know about the capture.
    releaseList()

    expect(await screen.findByText('captured yesterday')).toBeInTheDocument()
    expect(screen.getByText('captured just now')).toBeInTheDocument()
    expect(screen.queryByText(/your inbox is empty/i)).not.toBeInTheDocument()
  })

  it('shows the item once when the load does contain it', async () => {
    const both = [
      makeItem({ id: 2, title: 'captured just now' }),
      makeItem({ id: 1, title: 'captured yesterday' }),
    ]
    const releaseList = gatedListHandler(both)
    server.use(
      http.post('*/api/items', () =>
        HttpResponse.json(makeItem({ id: 2, title: 'captured just now' }), { status: 201 }),
      ),
    )

    const { user } = renderPage()
    await captureIdea(user, 'captured just now')
    releaseList()

    await screen.findByText('captured yesterday')
    expect(screen.getAllByText('captured just now')).toHaveLength(1)
  })
})

describe('when the Inbox fails to load', () => {
  it('keeps a captured item visible instead of hiding the whole list', async () => {
    server.use(
      http.get('*/api/items', () => HttpResponse.error()),
      http.post('*/api/items', () =>
        HttpResponse.json(makeItem({ id: 3, title: 'captured despite the error' }), {
          status: 201,
        }),
      ),
    )

    const { user } = renderPage()
    await screen.findByText(/could not load your inbox/i)
    await captureIdea(user, 'captured despite the error')

    // The confirmation must not point at an Inbox the user cannot see.
    await waitFor(() =>
      expect(screen.getByText('captured despite the error')).toBeInTheDocument(),
    )
  })

  it('never claims the Inbox is empty when it simply could not be read', async () => {
    server.use(http.get('*/api/items', () => HttpResponse.error()))

    renderPage()

    expect(await screen.findByText(/could not load your inbox/i)).toBeInTheDocument()
    // "an empty list means there is no data" is the inference this must not invite.
    expect(screen.queryByText(/your inbox is empty/i)).not.toBeInTheDocument()
  })

  it('explains the failure instead of echoing the raw transport error', async () => {
    server.use(http.get('*/api/items', () => HttpResponse.error()))

    renderPage()

    const error = await screen.findByText(/could not load your inbox/i)
    expect(error).toHaveTextContent(/could not reach the server/i)
    expect(error).not.toHaveTextContent(/failed to fetch/i)
  })

  it('explains a server failure using the message the backend wrote', async () => {
    server.use(
      http.get('*/api/items', () =>
        HttpResponse.json({ message: 'The Inbox is temporarily unavailable.' }, { status: 500 }),
      ),
    )

    renderPage()

    expect(
      await screen.findByText(/the inbox is temporarily unavailable/i),
    ).toBeInTheDocument()
    expect(screen.queryByText(/^HTTP 500$/)).not.toBeInTheDocument()
  })
})

describe('the Inbox on a clean load', () => {
  it('says the Inbox is empty only once the server has actually answered', async () => {
    const releaseList = gatedListHandler([])

    renderPage()

    // While the load is pending the screen must not assert emptiness.
    expect(screen.queryByText(/your inbox is empty/i)).not.toBeInTheDocument()

    releaseList()

    expect(await screen.findByText(/your inbox is empty/i)).toBeInTheDocument()
  })
})

describe('clarifying from the Inbox', () => {
  it('removes the item from the list once it has landed in its bucket', async () => {
    server.use(
      http.get('*/api/items', () =>
        HttpResponse.json([
          makeItem({ id: 1, title: 'ring the dentist' }),
          makeItem({ id: 2, title: 'read that article' }),
        ]),
      ),
      // Echo the id that was actually requested. A hardcoded id here made by-id and
      // by-index remove the same element, so the test passed with `slice(1)` in place of
      // the filter — it asserted nothing.
      http.post('*/api/items/:id/clarify', ({ params }) =>
        HttpResponse.json(makeItem({ id: Number(params.id), bucket: 'next_actions' })),
      ),
    )

    const { user } = renderPage()
    await screen.findByText('ring the dentist')

    // The SECOND row, so removing by index would take the wrong item.
    await user.click(screen.getAllByRole('button', { name: /clarify/i })[1])
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'Yes' }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /i will do it next/i }))

    await waitFor(() =>
      expect(screen.queryByText('read that article')).not.toBeInTheDocument(),
    )
    // The first row must survive — by-index removal would have taken this one instead.
    expect(screen.getByText('ring the dentist')).toBeInTheDocument()
  })

  it('keeps the item listed when clarify fails', async () => {
    server.use(
      http.get('*/api/items', () =>
        HttpResponse.json([makeItem({ id: 1, title: 'ring the dentist' })]),
      ),
      http.post('*/api/items/:id/clarify', () =>
        HttpResponse.json({ message: 'That item has already been clarified.' }, { status: 409 }),
      ),
    )

    const { user } = renderPage()
    await screen.findByText('ring the dentist')

    await user.click(screen.getByRole('button', { name: /clarify/i }))
    await user.click(screen.getByRole('button', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: /bin it/i }))

    // By text, not by role: the capture form also keeps an always-mounted role="alert",
    // so findByRole('alert') is ambiguous on this screen.
    expect(await screen.findByText(/already been clarified/i)).toBeInTheDocument()
    // Scoped to the list row: the dialog stays open on failure (so the user can retry or
    // cancel) and shows the same title in its heading, so a bare getByText is ambiguous.
    expect(within(screen.getByRole('listitem')).getByText('ring the dentist')).toBeInTheDocument()
  })
})
