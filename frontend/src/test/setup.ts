// Registers the jest-dom matchers and their types — which is why tsconfig.app.json needs
// no "types" entry for them: importing this file into the program is enough.
import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { server } from './server'

beforeAll(() => {
  // onUnhandledRequest: 'error' is the load-bearing part. A test that forgets a handler
  // fails loudly here instead of silently reaching the network (or hanging), which is how
  // a transport mock quietly stops exercising the real error shape.
  server.listen({ onUnhandledRequest: 'error' })
})

afterEach(() => {
  server.resetHandlers()
  cleanup()
  // The app stores its bearer token in localStorage and the capture draft in
  // sessionStorage; both outlive a component, so leaking them between tests would let
  // one test's state decide another's outcome.
  localStorage.clear()
  sessionStorage.clear()
})

afterAll(() => {
  server.close()
})
