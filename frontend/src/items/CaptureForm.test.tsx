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

/**
 * Scope of "the typed text survives": the tab session.
 *
 * The draft lives in sessionStorage, so it survives a failed capture, a 401 bounce, an
 * explicit logout (see CaptureForm.401.test.tsx) and a refresh — but NOT closing the tab.
 * That boundary is a deliberate decision, not an oversight: moving to localStorage would
 * persist the app's most personal text indefinitely on a possibly-shared device. There is
 * deliberately no test for tab-close, because there is deliberately no guarantee.
 */
describe('CaptureForm — the typed text survives', () => {
  it('leaves the text in the field after a failure', async () => {
    respondToCaptureWith(
      HttpResponse.json({ message: 'The item could not be saved.' }, { status: 500 }),
    )

    await captureAndReadMessage('an idea worth keeping')

    expect(screen.getByLabelText(/catch an idea/i)).toHaveValue('an idea worth keeping')
  })

  it('says so, on every kind of failure, not just a dropped connection', async () => {
    // The reassurance moved out of the shared mapper and onto this screen in p4, which
    // widened it from transport-only to every failure. Nothing asserted it until now, and
    // the loose fragment matching used elsewhere could never have caught its removal.
    respondToCaptureWith(
      HttpResponse.json({ message: 'The title field is required.' }, { status: 422 }),
    )
    expect(await captureAndReadMessage('a 422 idea')).toMatch(/still here/i)

    cleanup()
    respondToCaptureWith(HttpResponse.error())
    expect(await captureAndReadMessage('a transport idea')).toMatch(/still here/i)
  })

  it('restores the draft when the form is mounted again', async () => {
    respondToCaptureWith(
      HttpResponse.json({ message: 'The item could not be saved.' }, { status: 500 }),
    )
    await captureAndReadMessage('survives a remount')

    // A remount is what the user gets after a 401 bounce, an explicit logout, or a refresh
    // — the component is rebuilt from scratch and the draft has to come back with it.
    //
    // cleanup() first, and this is load-bearing: the helper above left a form mounted that
    // still holds the text in useState. Without this the assertion read THAT form and
    // passed with draft restoration entirely disabled. Every input shares
    // id="capture-title", so label lookup resolves via document.getElementById and returns
    // only the first match — the remounted form was not even reachable.
    cleanup()
    render(<CaptureForm onCaptured={() => {}} />)

    expect(screen.getByLabelText(/catch an idea/i)).toHaveValue('survives a remount')
  })

  it('still works when the draft store is unavailable, as in a private window', async () => {
    // CaptureForm guards every sessionStorage call; prove the guard, not just its presence.
    //
    // vi.stubGlobal, NOT vi.spyOn. jsdom 30 wraps Storage in a Proxy whose defineProperty
    // trap silently refuses a spy — no error, no effect, spy call count zero — so a
    // spyOn-based version of this test ran the plain happy path and stayed green with every
    // try/catch deleted from the component. Replacing the whole global does reach it.
    //
    // Scoped to sessionStorage deliberately: replacing localStorage too would break
    // api.ts's getToken() and the request would never be sent, making this pass for a
    // second wrong reason.
    vi.stubGlobal('sessionStorage', {
      getItem: () => {
        throw new DOMException('SecurityError')
      },
      setItem: () => {
        throw new DOMException('QuotaExceededError')
      },
      removeItem: () => {
        throw new DOMException('SecurityError')
      },
      // Left harmless: setup.ts clears storage after every test.
      clear: () => {},
      key: () => null,
      length: 0,
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

  it('does not eat text typed while the capture is in flight', async () => {
    // The input stays enabled during the round trip on purpose (disabling it blurs the
    // field and drops keystrokes), so the clear at CaptureForm.tsx:68-73 is conditional on
    // the value still being the one that was submitted. Until now that guard had no test:
    // making the clear unconditional would silently destroy the next idea.
    let release!: () => void
    const inFlight = new Promise<void>((resolve) => {
      release = resolve
    })
    server.use(
      http.post('*/api/items', async () => {
        await inFlight

        return HttpResponse.json(makeItem({ id: 9, title: 'first idea' }), { status: 201 })
      }),
    )

    const { user } = renderForm()
    const field = screen.getByLabelText(/catch an idea/i)
    await user.type(field, 'first idea')
    await user.click(screen.getByRole('button', { name: /capture/i }))

    await user.type(field, ' and the next one')
    release()

    await screen.findByText(/saved to your inbox/i)
    expect(field).toHaveValue('first idea and the next one')
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
    // Focus must come back, or correcting the mistake costs the user a click.
    expect(screen.getByLabelText(/catch an idea/i)).toHaveFocus()
  })
})

describe('the input describes its own error state', () => {
  /**
   * These four attributes arrived as implementation-review fixes (ph3 F6c) and had no test
   * until the visual-design pass, which binds the input's error styling to `aria-invalid`.
   * That makes losing the attribute a silent VISUAL regression as well as an accessibility
   * one — and nothing in this project can see a visual regression.
   */
  it('is flagged invalid only while an error stands, and always names its live regions', async () => {
    const { user } = renderForm()
    const input = screen.getByLabelText(/catch an idea/i)

    // The wiring exists from the first render: the regions are mounted before they have
    // text, so `aria-describedby` can point at ids that already resolve.
    expect(input).toHaveAttribute('aria-invalid', 'false')
    expect(input).toHaveAttribute('aria-describedby', 'capture-error capture-status')
    expect(document.getElementById('capture-error')).not.toBeNull()
    expect(document.getElementById('capture-status')).not.toBeNull()

    await user.type(input, '   ')
    await user.click(screen.getByRole('button', { name: /capture/i }))
    await screen.findByRole('alert')

    expect(input).toHaveAttribute('aria-invalid', 'true')
  })
})
