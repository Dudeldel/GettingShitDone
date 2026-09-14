import { useAuth } from './auth/context'

/**
 * The frame every screen carries.
 *
 * Until now it existed only on the Inbox, so leaving the Inbox meant losing both the app's
 * name and the only way to sign out — a bucket view gave no clue which product you were in.
 *
 * The app name is plain text on purpose, and that is a constraint rather than a preference:
 * it is not a heading, because a bucket page's single `<h1>` has to name the bucket (seven
 * tests assert exactly one, by level, with no name), and it is not a link, because a bucket
 * page has to carry exactly eight of those.
 */
export function AppHeader() {
  const { user, logout } = useAuth()

  return (
    <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line pb-3">
      <span className="font-semibold text-ink">Getting Shit Done</span>
      <span className="text-sm text-muted">
        {user !== null && <>Signed in as {user.email} · </>}
        <button type="button" className="btn btn-ghost text-sm" onClick={() => void logout()}>
          Log out
        </button>
      </span>
    </header>
  )
}
