import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Item } from '../api'
import { makeItem, server } from '../test/server'
import { CaptureForm } from './CaptureForm'

const DRAFT_KEY = 'gsd_capture_draft'

function renderForm(onCaptured: (item: Item) => void = () => {}) {
  return {
    user: userEvent.setup(),
    ...render(<CaptureForm onCaptured={onCaptured} />),
  }
}

/** Every failure below answers POST /api/items; nothing else is overridden. */
function respondToCaptureWith(response: Response) {
  server.use(http.post('*/api/items', () => response))
}

async function captureAndReadMessage(text: string): Promise<string> {
  const { user } = renderForm()
  await user.type(screen.getByLabelText(/catch an idea/i), text)
  await user.click(screen.getByRole('button', { name: /capture/i }))

  const alert = await screen.findByRole('alert')
  await waitFor(() => expect(alert).not.toBeEmptyDOMElement())

  return alert.textContent ?? ''
}

beforeEach(() => {
  sessionStorage.clear()
})

describe('CaptureForm — failures reach the user', () => {
  it('surfaces the backend message on a 422, because retrying unchanged cannot work', async () => {
    respondToCaptureWith(
      HttpResponse.json(
        {
          message: 'The title field must not be greater than 255 characters.',
          errors: { title: ['The title field must not be greater than 255 characters.'] },
        },
        { status: 422 },
      ),
    )

    expect(await captureAndReadMessage('far too long')).toContain(
      'must not be greater than 255 characters',
    )
  })

  it('tells the user to wait on a 429 rather than to retry', async () => {
    respondToCaptureWith(HttpResponse.json({ message: 'Too Many Attempts.' }, { status: 429 }))

    // Actionable means telling the user to wait; "try again" alone would be advice that
    // fails for as long as the limiter is still counting.
    expect(await captureAndReadMessage('one idea too many')).toMatch(/wait a moment/i)
  })

  it('surfaces the backend failure message on a 500', async () => {
    // The exact string bootstrap/app.php returns, pinned backend-side by
    // tests/Feature/Item/CaptureItemTest.php. This is the contract between the two halves.
    respondToCaptureWith(
      HttpResponse.json(
        { message: 'The item could not be saved. Please try again.' },
        { status: 500 },
      ),
    )

    expect(await captureAndReadMessage('a doomed idea')).toContain(
      'The item could not be saved.',
    )
  })

  it('says the server is unreachable when the transport itself fails', async () => {
    respondToCaptureWith(HttpResponse.error())

    expect(await captureAndReadMessage('offline idea')).toMatch(/could not reach the server/i)
  })

  /**
   * The property Risk #2 actually needs: not any particular wording, but that a user
   * cannot confuse one failure for another. Asserting the strings themselves would be a
   * snapshot — this asserts they stay distinguishable however they are worded.
   *
   * 408 is covered by src/test/abort-signal.probe.test.ts, which pays the real
   * ten-second timeout once rather than in every case here; it reaches the user through
   * the same ApiError branch as the 500 above.
   */
  it('gives every failure a different message', async () => {
    const responses: Array<[string, Response]> = [
      ['422', HttpResponse.json({ message: 'The title field is required.' }, { status: 422 })],
      ['429', HttpResponse.json({ message: 'Too Many Attempts.' }, { status: 429 })],
      ['500', HttpResponse.json({ message: 'The item could not be saved.' }, { status: 500 })],
      ['network', HttpResponse.error()],
    ]

    const seen = new Map<string, string>()
    for (const [label, response] of responses) {
      respondToCaptureWith(response)
      seen.set(label, await captureAndReadMessage(`idea for ${label}`))
      // Each iteration mounts its own form; without this they accumulate in the document
      // and the next findByRole('alert') matches more than one.
      cleanup()
      sessionStorage.clear()
    }

    expect(new Set(seen.values()).size).toBe(responses.length)
  })
})

describe('CaptureForm — the typed text survives', () => {
  it('leaves the text in the field after a failure', async () => {
    respondToCaptureWith(
      HttpResponse.json({ message: 'The item could not be saved.' }, { status: 500 }),
    )

    await captureAndReadMessage('an idea worth keeping')

    expect(screen.getByLabelText(/catch an idea/i)).toHaveValue('an idea worth keeping')
  })

  it('restores the draft when the form is mounted again', async () => {
    respondToCaptureWith(
      HttpResponse.json({ message: 'The item could not be saved.' }, { status: 500 }),
    )
    await captureAndReadMessage('survives a remount')

    // A remount is what the user gets after a 401 bounce, an explicit logout, or a refresh
    // — the component is rebuilt from scratch and the draft has to come back with it.
    const { unmount } = render(<CaptureForm onCaptured={() => {}} />)
    unmount()
    render(<CaptureForm onCaptured={() => {}} />)

    expect(screen.getAllByLabelText(/catch an idea/i)[0]).toHaveValue('survives a remount')
  })

  it('still works when the draft store is unavailable, as in a private window', async () => {
    // CaptureForm guards every sessionStorage call; prove the guard, not just its presence.
    //
    // Scoped to sessionStorage deliberately. Stubbing Storage.prototype also breaks
    // localStorage, and api.ts's getToken() reads that WITHOUT a guard — so the request
    // would never be sent and this test would "pass" for the wrong reason. That unguarded
    // read is a real (if narrow) gap, noted for a later change rather than widened into
    // this one.
    vi.spyOn(sessionStorage, 'setItem').mockImplementation(() => {
      throw new DOMException('QuotaExceededError')
    })
    vi.spyOn(sessionStorage, 'getItem').mockImplementation(() => {
      throw new DOMException('SecurityError')
    })

    const captured: Item[] = []
    const { user } = renderForm((item) => captured.push(item))
    await user.type(screen.getByLabelText(/catch an idea/i), 'private mode idea')
    await user.click(screen.getByRole('button', { name: /capture/i }))

    await waitFor(() => expect(captured).toHaveLength(1))
    expect(captured[0].title).toBe('private mode idea')
  })
})

describe('CaptureForm — the confirmation', () => {
  it('announces the save, clears the field and drops the draft', async () => {
    server.use(
      http.post('*/api/items', () => HttpResponse.json(makeItem({ id: 7 }), { status: 201 })),
    )

    const captured: Item[] = []
    const { user } = renderForm((item) => captured.push(item))
    await user.type(screen.getByLabelText(/catch an idea/i), 'a good idea')
    await user.click(screen.getByRole('button', { name: /capture/i }))

    expect(await screen.findByText(/saved to your inbox/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/catch an idea/i)).toHaveValue('')
    // Only a confirmed 201 may drop the draft.
    expect(sessionStorage.getItem(DRAFT_KEY)).toBeNull()
    expect(captured).toHaveLength(1)
  })

  it('refuses a whitespace-only capture without calling the API', async () => {
    let called = false
    server.use(
      http.post('*/api/items', () => {
        called = true

        return HttpResponse.json(makeItem(), { status: 201 })
      }),
    )

    const { user } = renderForm()
    await user.type(screen.getByLabelText(/catch an idea/i), '   ')
    await user.click(screen.getByRole('button', { name: /capture/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/type something first/i)
    expect(called).toBe(false)
    expect(screen.queryByText(/saved to your inbox/i)).not.toBeInTheDocument()
  })
})
