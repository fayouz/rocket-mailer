<script setup lang="ts">
import grapesjs, { type Editor, type ProjectData } from 'grapesjs'
import newsletterModule from 'grapesjs-preset-newsletter'
import 'grapesjs/dist/css/grapes.min.css'
// Bundled instead of GrapesJS' default CDN link (cssIcons).
import 'font-awesome/css/font-awesome.min.css'

// UMD bundle: depending on CJS interop the plugin is the default export or nested under it.
const newsletterPreset = (newsletterModule as unknown as { default?: typeof newsletterModule }).default ?? newsletterModule

const props = defineProps<{
  projectData: Record<string, unknown> | null
  /** Used when a template has HTML but no GrapesJS project (e.g. created through the API). */
  html?: string
}>()

const emit = defineEmits<{ change: [] }>()

const container = ref<HTMLElement>()
let editor: Editor | null = null

onMounted(() => {
  editor = grapesjs.init({
    container: container.value!,
    height: '100%',
    width: 'auto',
    storageManager: false,
    cssIcons: '',
    fromElement: false,
    plugins: [editor => newsletterPreset(editor, { inlineCss: true })],
    ...(props.projectData
      ? { projectData: props.projectData as unknown as ProjectData }
      : { components: props.html || '<table style="width:100%"><tr><td style="padding:24px;font-family:Arial,sans-serif">Votre contenu</td></tr></table>' }),
  })
  // Loading the project also fires "update": only report user changes.
  editor.on('load', () => editor?.on('update', () => emit('change')))
})

onBeforeUnmount(() => {
  editor?.destroy()
  editor = null
})

/** Email-ready HTML (CSS inlined) plus the project to reload the editor later. */
function getData(): { html: string, projectData: Record<string, unknown> } {
  if (!editor) throw new Error('Editor not ready')
  return {
    html: editor.runCommand('gjs-get-inlined-html') as string,
    projectData: editor.getProjectData() as unknown as Record<string, unknown>,
  }
}

defineExpose({ getData })
</script>

<template>
  <div ref="container" class="h-full min-h-[600px]" />
</template>
