import type { ApplicationSender } from '~/types/api'

/**
 * Sender settings of every application (GET /api/application_senders), shared by the cells of the "Expéditeur"
 * column of the applications page and refreshed by its form section once saved.
 */
export function useApplicationSenders() {
  const api = useApi()
  return useAsyncData('application-senders', () => api<ApplicationSender[]>('/api/application_senders', { query: { itemsPerPage: 200 } }), { default: () => [] })
}
