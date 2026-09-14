import { ApiError } from './api'

/**
 * Turns a failed request into something a user can act on.
 *
 * Shared because both screens need it and both were getting it wrong in different ways:
 * CaptureForm branched properly but InboxPage rendered the raw error, so a load failure
 * showed "Failed to fetch" or the literal "HTTP 500" — distinguishable, but not advice.
 *
 * Deliberately context-neutral. The capture screen adds its own reassurance about the
 * draft, because "your text is still here" is meaningless on a list that failed to load.
 */
export function messageFor(err: unknown): string {
  if (!(err instanceof ApiError)) {
    // Not an ApiError means the request never produced a response at all — a dropped
    // connection, DNS failure, or CORS rejection.
    return 'Could not reach the server. Please try again.'
  }

  switch (err.status) {
    // No 401 arm: a 401 logs the app out and unmounts the caller in the same render, so
    // anything set here is discarded. The expiry is explained at the login screen instead
    // (see AuthContext's sessionExpired).
    case 422:
      // The backend writes these for the user; retrying unchanged would never work.
      return err.message
    case 429:
      return 'Too many requests. Wait a moment and try again.'
    default:
      return err.message
  }
}
