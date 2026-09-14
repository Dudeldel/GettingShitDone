import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'
import type { Item } from '../api'
import { makeItem, server } from '../test/server'
import { AttributesDialog } from './AttributesDialog'

/** Capture what the dialog actually PUT on the wire, and answer with a saved item. */
function captureSave(reply: Item = makeItem({ id: 1 })) {
  const sent: { body: Record<string, unknown> | null } = { body: null }

  server.use(
    http.post('*/api/items/1/attributes', async ({ request }) => {
      sent.body = (await request.json()) as Record<string, unknown>

      return HttpResponse.json(reply)
    }),
  )

  return sent
}

function open(item: Partial<Item> = {}) {
  const onSaved = vi.fn()
  const onCancel = vi.fn()

  return {
    onSaved,
    onCancel,
    user: userEvent.setup(),
    ...render(
      <AttributesDialog item={makeItem({ id: 1, ...item })} onSaved={onSaved} onCancel={onCancel} />,
    ),
  }
}

describe('the attributes editor (FR-011 + FR-013)', () => {
  it('opens showing the values the item already has', () => {
    open({ dueDate: '2026-09-30', tags: ['work', 'deep'], context: '@computer', important: true })

    expect(screen.getByLabelText('Due date')).toHaveValue('2026-09-30')
    expect(screen.getByLabelText('Tags')).toHaveValue('work, deep')
    expect(screen.getByLabelText('Context')).toHaveValue('@computer')
    // The flags are radio groups, so the current answer is the checked one.
    expect(screen.getByRole('group', { name: 'Important' })).toBeInTheDocument()
  })

  it('sends every field, including the ones the user did not touch', async () => {
    // THE test for full-replacement semantics. The endpoint REPLACES the attribute set, so a
    // dialog that sent only what changed would silently clear the rest. Asserting the request
    // succeeded would not see that — the assertion has to be on the body.
    const sent = captureSave()
    const { user } = open({ dueDate: '2026-09-30', tags: ['work'], context: '@computer' })

    await user.clear(screen.getByLabelText('Context'))
    await user.type(screen.getByLabelText('Context'), '@phone')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(sent.body).not.toBeNull())
    expect(sent.body).toEqual({
      dueDate: '2026-09-30',
      tags: ['work'],
      context: '@phone',
      important: null,
      urgent: null,
    })
  })

  it('sends null for a field the user emptied', async () => {
    const sent = captureSave()
    const { user } = open({ dueDate: '2026-09-30', context: '@computer' })

    await user.clear(screen.getByLabelText('Context'))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(sent.body).not.toBeNull())
    // Null is an instruction, not an absence — it is how the one verb also clears.
    expect(sent.body?.context).toBeNull()
  })

  it('can express all three states of a flag', async () => {
    const sent = captureSave()
    const { user } = open()

    const important = screen.getByRole('group', { name: 'Important' })
    // "Not judged" is the opening state and must stay reachable: S-08 derives four quadrants
    // from these, and a checkbox could only ever offer two of the three answers.
    expect(within(important).getByRole('radio', { name: 'Not judged' })).toBeChecked()

    await user.click(within(important).getByRole('radio', { name: 'No' }))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(sent.body).not.toBeNull())
    // false, not null: "judged, and no" is a different answer from "not judged yet".
    expect(sent.body?.important).toBe(false)
  })

  it('puts a validation error under the field it belongs to', async () => {
    server.use(
      http.post('*/api/items/1/attributes', () =>
        HttpResponse.json(
          {
            message: 'The due date field must match the format Y-m-d.',
            errors: { dueDate: ['The due date field must match the format Y-m-d.'] },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = open()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    const due = await screen.findByLabelText('Due date')
    // Marked on the control as well as printed, or the message is orphaned for anyone not
    // reading top to bottom.
    await waitFor(() => expect(due).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText(/must match the format/)).toBeInTheDocument()
    // The other fields are not implicated.
    expect(screen.getByLabelText('Context')).toHaveAttribute('aria-invalid', 'false')
  })

  it('surfaces an indexed tag error against the tags field', async () => {
    // The server reports a bad tag as `tags.0`. An error keyed to a field nobody renders is
    // the same as no validation at all.
    server.use(
      http.post('*/api/items/1/attributes', () =>
        HttpResponse.json(
          { message: 'Bad tag.', errors: { 'tags.0': ['The tags.0 field is too long.'] } },
          { status: 422 },
        ),
      ),
    )
    const { user } = open()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(screen.getByLabelText('Tags')).toHaveAttribute('aria-invalid', 'true'),
    )
    expect(screen.getByText(/too long/)).toBeInTheDocument()
  })

  it('falls back to one message when the failure carries no field detail', async () => {
    server.use(
      http.post('*/api/items/1/attributes', () => HttpResponse.json({}, { status: 500 })),
    )
    const { user, onSaved } = open()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/HTTP 500/)
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('cancels on Escape without saving', async () => {
    const sent = captureSave()
    const { user, onCancel } = open()

    await user.type(screen.getByLabelText('Context'), '@phone')
    await user.keyboard('{Escape}')

    expect(onCancel).toHaveBeenCalled()
    expect(sent.body).toBeNull()
  })

  it('hands the saved item back to the caller', async () => {
    captureSave(makeItem({ id: 1, context: '@phone' }))
    const { user, onSaved } = open()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onSaved).toHaveBeenCalled())
    expect(onSaved.mock.calls[0][0]).toMatchObject({ id: 1, context: '@phone' })
  })
})
