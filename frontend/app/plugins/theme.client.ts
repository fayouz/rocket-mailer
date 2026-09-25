/** Applies the project's palette (or, in the embedded composer, the application's one) before the first page. */
export default defineNuxtPlugin(async () => {
  const route = useRoute()
  const application = route.path.startsWith('/embed') && typeof route.query.app === 'string' ? route.query.app : null
  await useTheme().load(application)
})
