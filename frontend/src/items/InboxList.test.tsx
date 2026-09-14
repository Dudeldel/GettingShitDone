import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeItem } from '../test/server'
import { InboxList } from './InboxList'

/**
 * Harness smoke test. InboxList is pure and prop-driven, so this exercises jsdom, React
 * Testing Library, the jest-dom matchers and the tsc/eslint path — and nothing else.
 * If this file goes red, the problem is the toolchain, not the app.
 *
 * Explicit vitest imports rather than globals: true. That is what keeps tsconfig.app.json
 * and eslint.config.js untouched by this phase.
 */
describe('InboxList', () => {
  it('renders every captured item', () => {
    render(
      <InboxList
        items={[
          makeItem({ id: 1, title: 'ring the dentist' }),
          makeItem({ id: 2, title: 'buy cat food', note: 'the expensive one' }),
        ]}
      />,
    )

    expect(screen.getByText('ring the dentist')).toBeInTheDocument()
    expect(screen.getByText('buy cat food')).toBeInTheDocument()
    expect(screen.getByText('the expensive one')).toBeInTheDocument()
    expect(screen.queryByText(/your inbox is empty/i)).not.toBeInTheDocument()
  })

  it('tells the user the Inbox is empty when it is', () => {
    render(<InboxList items={[]} />)

    expect(screen.getByText(/your inbox is empty/i)).toBeInTheDocument()
  })
})

describe('an item finished under the two-minute rule (FR-006)', () => {
  it('shows it as done rather than hiding it', () => {
    render(
      <InboxList
        items={[
          makeItem({
            id: 1,
            title: 'reply to the landlord',
            bucket: 'next_actions',
            completedAt: '2026-09-14T10:05:00+00:00',
          }),
          makeItem({ id: 2, title: 'ring the dentist', bucket: 'next_actions' }),
        ]}
      />,
    )

    // Still listed: there is no Done bucket, and an item vanishing the moment the user
    // finishes it is indistinguishable from one that was lost.
    expect(screen.getByText('reply to the landlord')).toBeInTheDocument()
    expect(screen.getByText('✓ Done')).toBeInTheDocument()
  })

  it('does not mark an unfinished item as done', () => {
    render(<InboxList items={[makeItem({ id: 1, title: 'ring the dentist' })]} />)

    // The marker has to depend on completedAt. One rendered unconditionally would look
    // right on the screen above and label every open action as finished.
    expect(screen.queryByText('✓ Done')).not.toBeInTheDocument()
  })
})

describe('item attributes on the row (FR-011 + FR-013)', () => {
  it('shows a due date as a date rather than a timestamp or a raw ISO string', () => {
    render(<InboxList items={[makeItem({ id: 1, dueDate: '2026-09-30' })]} />)

    const time = screen.getByText(/^Due/).querySelector('time')

    // The machine-readable value stays the ISO date, which is what the <time> element is for.
    expect(time).toHaveAttribute('datetime', '2026-09-30')
    // The VISIBLE text must differ from it (so the raw string is not just echoed) and must
    // carry no clock (so toLocaleString has not been used where a calendar day was meant).
    // The exact formatting is the viewer's locale and deliberately not pinned here.
    expect(time?.textContent).not.toBe('2026-09-30')
    expect(time?.textContent).not.toMatch(/:/)
  })

  it('marks an overdue date in text, not by colour alone', () => {
    render(<InboxList items={[makeItem({ id: 1, dueDate: '2020-01-01' })]} />)

    // A red tint is not announced and is not available to every reader, so the word has to
    // be there. The accent in this app is reserved for primary actions besides.
    expect(screen.getByText(/Overdue/)).toBeInTheDocument()
  })

  it('does not call a future date overdue', () => {
    render(<InboxList items={[makeItem({ id: 1, dueDate: '2099-12-31' })]} />)

    // A marker rendered unconditionally would look right on the test above and then label
    // every scheduled item in the product as late.
    expect(screen.queryByText(/Overdue/)).not.toBeInTheDocument()
  })

  it('shows tags, context and the two flags when they are set', () => {
    render(
      <InboxList
        items={[
          makeItem({
            id: 1,
            tags: ['work', 'deep'],
            context: '@computer',
            important: true,
            urgent: true,
          }),
        ]}
      />,
    )

    expect(screen.getByText(/work, deep/)).toBeInTheDocument()
    expect(screen.getByText(/@computer/)).toBeInTheDocument()
    expect(screen.getByText('Important')).toBeInTheDocument()
    expect(screen.getByText('Urgent')).toBeInTheDocument()
  })

  it('renders nothing extra for an item with no attributes', () => {
    render(<InboxList items={[makeItem({ id: 1, title: 'ring the dentist' })]} />)

    // The default row must look exactly as it did before this slice. Labels rendered
    // unconditionally would put empty "Tags:" and "Context:" lines under every item.
    expect(screen.queryByText(/^Due/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Tags:/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Context:/)).not.toBeInTheDocument()
    expect(screen.queryByText('Important')).not.toBeInTheDocument()
    expect(screen.queryByText('Urgent')).not.toBeInTheDocument()
  })

  it('does not flag an item judged NOT important', () => {
    // false is "judged, and no"; null is "not judged yet". Neither is news on a list, and a
    // marker driven by `!== null` would announce both as priorities.
    render(<InboxList items={[makeItem({ id: 1, important: false, urgent: false })]} />)

    expect(screen.queryByText('Important')).not.toBeInTheDocument()
    expect(screen.queryByText('Urgent')).not.toBeInTheDocument()
  })

  it('names the home bucket only where the list asks for it', () => {
    const dated = makeItem({ id: 1, bucket: 'next_actions', dueDate: '2026-09-30' })

    const { rerender } = render(<InboxList items={[dated]} />)
    expect(screen.queryByText(/In Next Actions/)).not.toBeInTheDocument()

    // The Calendar view sets it, because most of its rows are not filed in the bucket the
    // URL names — without this that screen reads as a bug.
    rerender(<InboxList items={[dated]} showBucket />)
    expect(screen.getByText(/In Next Actions/)).toBeInTheDocument()
  })
})

describe('the edit affordance', () => {
  it('offers Edit only when the caller passes a handler', () => {
    const item = makeItem({ id: 1, title: 'ring the dentist' })

    const { rerender } = render(<InboxList items={[item]} />)
    // Absent rather than present-and-doomed-to-422 — the same rule the completion checkbox
    // and the Move button already follow. The Trash is where the server refuses this.
    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument()

    rerender(<InboxList items={[item]} onEdit={() => {}} />)
    expect(screen.getByRole('button', { name: 'Edit "ring the dentist"' })).toBeInTheDocument()
  })
})
