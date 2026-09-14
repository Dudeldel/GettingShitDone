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
