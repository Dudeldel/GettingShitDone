import { useEffect, useRef, useState } from 'react'
import { ApiError, type Item, type ItemAttributes, updateItemAttributes } from '../api'
import { messageFor } from '../apiMessage'

/**
 * Edit an item's due date, tags, context and Eisenhower flags (FR-011 + FR-013).
 *
 * The product's first edit-an-item surface. Shaped like RefileDialog rather than as a modal:
 * a role="dialog" section rendered inline, no portal and no backdrop, and deliberately no
 * aria-modal — nothing behind it is hidden or removed from the tab order, so claiming modality
 * would be a lie to a screen reader. Escape cancels; focus enters at the heading and the
 * caller hands it back on close.
 *
 * Every field is submitted every time. The endpoint REPLACES the attribute set, so clearing
 * the date is the same request as setting it, with a different value.
 */
export function AttributesDialog({
  item,
  onSaved,
  onCancel,
}: {
  item: Item
  onSaved: (updated: Item) => void
  onCancel: () => void
}) {
  const headingRef = useRef<HTMLHeadingElement>(null)
  const [dueDate, setDueDate] = useState(item.dueDate ?? '')
  const [tags, setTags] = useState(item.tags?.join(', ') ?? '')
  const [context, setContext] = useState(item.context ?? '')
  const [important, setImportant] = useState<boolean | null>(item.important)
  const [urgent, setUrgent] = useState<boolean | null>(item.urgent)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]> | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Focus has to enter the panel, or a keyboard user clicks Edit and is left standing on a
  // trigger that just unmounted, tabbing forward blind.
  useEffect(() => {
    headingRef.current?.focus()
  }, [])

  /**
   * The first message for a field, including its indexed children — the server reports a bad
   * tag as `tags.0`, and an error nobody renders is the same as no validation at all.
   */
  function errorFor(field: string): string | null {
    if (fieldErrors === null) {
      return null
    }
    const key = Object.keys(fieldErrors).find((k) => k === field || k.startsWith(`${field}.`))

    return key === undefined ? null : (fieldErrors[key][0] ?? null)
  }

  async function save(): Promise<void> {
    setError(null)
    setFieldErrors(null)
    setSubmitting(true)
    const payload: ItemAttributes = {
      dueDate: dueDate === '' ? null : dueDate,
      // Split and hand over; trimming, de-duplication and the empty-entry rule live on the
      // server, so this does not grow a second copy of them that can drift.
      tags: tags.trim() === '' ? null : tags.split(','),
      context: context.trim() === '' ? null : context,
      important,
      urgent,
    }
    try {
      onSaved(await updateItemAttributes(item.id, payload))
    } catch (err) {
      if (err instanceof ApiError && err.errors !== null) {
        setFieldErrors(err.errors)
      } else {
        // No per-field detail — a 500, a timeout, a dropped connection. The single alert is
        // still the right place for those.
        setError(messageFor(err))
      }
    } finally {
      setSubmitting(false)
    }
  }

  /** Three states, so "not judged yet" stays reachable — a checkbox could only offer two. */
  function flag(
    name: string,
    legend: string,
    value: boolean | null,
    set: (next: boolean | null) => void,
  ) {
    return (
      <fieldset className="mt-3">
        <legend className="text-sm font-medium text-ink">{legend}</legend>
        {(
          [
            ['Not judged', null],
            ['Yes', true],
            ['No', false],
          ] as const
        ).map(([label, option]) => (
          <label key={label} className="mr-4 inline-flex items-center gap-1 text-sm text-body">
            <input
              type="radio"
              name={name}
              className="size-4 accent-accent"
              checked={value === option}
              disabled={submitting}
              onChange={() => set(option)}
            />
            {label}
          </label>
        ))}
      </fieldset>
    )
  }

  return (
    <section
      role="dialog"
      aria-labelledby="attributes-heading"
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !submitting) {
          onCancel()
        }
      }}
      className="panel mt-4 p-4"
    >
      <h3 id="attributes-heading" ref={headingRef} tabIndex={-1}>
        Edit: {item.title}
      </h3>

      <div className="mt-4">
        <label htmlFor="attr-due" className="block text-sm font-medium text-ink">
          Due date
        </label>
        <input
          id="attr-due"
          type="date"
          className="field mt-1"
          value={dueDate}
          disabled={submitting}
          aria-invalid={errorFor('dueDate') !== null}
          aria-describedby={errorFor('dueDate') !== null ? 'attr-due-error' : undefined}
          onChange={(e) => setDueDate(e.target.value)}
        />
        {errorFor('dueDate') !== null && (
          <p id="attr-due-error" className="mt-1 text-sm text-danger">
            {errorFor('dueDate')}
          </p>
        )}
      </div>

      <div className="mt-3">
        <label htmlFor="attr-tags" className="block text-sm font-medium text-ink">
          Tags
        </label>
        <input
          id="attr-tags"
          type="text"
          className="field mt-1"
          placeholder="work, deep"
          value={tags}
          disabled={submitting}
          aria-invalid={errorFor('tags') !== null}
          aria-describedby={errorFor('tags') !== null ? 'attr-tags-error' : undefined}
          onChange={(e) => setTags(e.target.value)}
        />
        {errorFor('tags') !== null && (
          <p id="attr-tags-error" className="mt-1 text-sm text-danger">
            {errorFor('tags')}
          </p>
        )}
      </div>

      <div className="mt-3">
        <label htmlFor="attr-context" className="block text-sm font-medium text-ink">
          Context
        </label>
        <input
          id="attr-context"
          type="text"
          className="field mt-1"
          placeholder="@computer"
          value={context}
          disabled={submitting}
          aria-invalid={errorFor('context') !== null}
          aria-describedby={errorFor('context') !== null ? 'attr-context-error' : undefined}
          onChange={(e) => setContext(e.target.value)}
        />
        {errorFor('context') !== null && (
          <p id="attr-context-error" className="mt-1 text-sm text-danger">
            {errorFor('context')}
          </p>
        )}
      </div>

      {flag('important', 'Important', important, setImportant)}
      {flag('urgent', 'Urgent', urgent, setUrgent)}

      <div className="mt-4">
        <button
          type="button"
          className="btn btn-primary mr-2"
          disabled={submitting}
          onClick={() => void save()}
        >
          Save
        </button>
        <button type="button" className="btn btn-quiet" disabled={submitting} onClick={onCancel}>
          Cancel
        </button>
      </div>

      <div className="live-line mt-3">
        <p role="alert" className="text-danger">
          {error ?? ''}
        </p>
      </div>
    </section>
  )
}
