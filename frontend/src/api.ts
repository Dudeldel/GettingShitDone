// Minimal API client for the walking-skeleton deploy.
// Same-origin by default (nginx proxies /api on the SPA's domain); override with VITE_API_BASE_URL.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''

export interface HealthStatus {
  status: string
  app: string
  environment: string
  time: string
}

export async function fetchHealth(): Promise<HealthStatus> {
  const res = await fetch(`${API_BASE_URL}/api/health`)
  if (!res.ok) {
    throw new Error(`Health check failed: HTTP ${res.status}`)
  }
  return (await res.json()) as HealthStatus
}
