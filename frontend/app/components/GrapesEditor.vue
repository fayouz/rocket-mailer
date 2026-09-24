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

const emit = defineEmits<{
  change: []
  /** The "{x}" button of the text toolbar was clicked: the parent picks a variable, then calls insertVariable(). */
  variableRequest: []
}>()

const container = ref<HTMLElement>()
let editor: Editor | null = null
// Text being edited when "{x}" was clicked, and its caret: the picker takes the focus away from the canvas.
interface Rte { el: HTMLElement, doc: Document, insertHTML: (html: string) => void }
// GrapesJS may replace the text nodes meanwhile: keep the caret as a character offset, not as a Range.
let variableTarget: { rte: Rte, offset: number | null } | null = null

function caretOffset(el: HTMLElement, doc: Document): number | null {
  const selection = doc.getSelection()
  if (!selection?.rangeCount || !el.contains(selection.focusNode)) return null
  const range = doc.createRange()
  range.selectNodeContents(el)
  range.setEnd(selection.focusNode!, selection.focusOffset)
  return range.toString().length
}

function placeCaret(el: HTMLElement, doc: Document, offset: number) {
  const walker = doc.createTreeWalker(el, NodeFilter.SHOW_TEXT)
  let remaining = offset
  let node = walker.nextNode()
  while (node) {
    const length = node.textContent?.length ?? 0
    if (remaining <= length) {
      const range = doc.createRange()
      range.setStart(node, remaining)
      range.collapse(true)
      doc.getSelection()?.removeAllRanges()
      doc.getSelection()?.addRange(range)
      return
    }
    remaining -= length
    node = walker.nextNode()
  }
}

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
      : { components: props.html || '<table style="width:100%"><tr><td style="padding:24px;font-family:Arial,sans-serif"><div>Votre contenu</div></td></tr></table>' }),
  })
  // Loading the project also fires "update": only report user changes.
  editor.on('load', () => editor?.on('update', () => emit('change')))

  // "{x}" in the text toolbar: insert a "{{ variable }}" placeholder at the caret.
  editor.RichTextEditor.add('variable', {
    icon: '<span style="font:600 12px monospace">{x}</span>',
    attributes: { 'title': 'Insérer une variable', 'data-testid': 'rte-variable' },
    result: (instance) => {
      const rte = instance as unknown as Rte
      variableTarget = { rte, offset: caretOffset(rte.el, rte.doc) }
      emit('variableRequest')
    },
  })
})

onBeforeUnmount(() => {
  editor?.destroy()
  editor = null
})

/** Text being edited is only merged into the project when leaving it: merge it now. */
async function syncEditing() {
  const view = editor?.getEditing()?.getView() as { syncContent?: (opts?: object) => Promise<void> } | undefined
  await view?.syncContent?.({ force: true })
}

/** Email-ready HTML (CSS inlined) plus the project to reload the editor later. */
async function getData(): Promise<{ html: string, projectData: Record<string, unknown> }> {
  if (!editor) throw new Error('Editor not ready')
  await syncEditing()
  return {
    html: editor.runCommand('gjs-get-inlined-html') as string,
    projectData: editor.getProjectData() as unknown as Record<string, unknown>,
  }
}

function insertVariable(name: string) {
  if (!variableTarget) return
  const { rte, offset } = variableTarget
  rte.el.focus()
  if (offset !== null) placeCaret(rte.el, rte.doc, offset)
  rte.insertHTML(`{{ ${name} }}`)
  // Opening the picker may already have closed the text editing: merge the DOM into the project explicitly.
  const component = rte.el.id ? editor?.getWrapper()?.find(`#${rte.el.id}`)[0] : undefined
  const view = component?.getView() as { syncContent?: (opts?: object) => Promise<void> } | undefined
  view?.syncContent?.({ force: true })
  variableTarget = null
  emit('change')
}

/** Current HTML, to detect the variables used so far. */
async function getHtml(): Promise<string> {
  await syncEditing()
  return editor?.getHtml() ?? ''
}

defineExpose({ getData, getHtml, insertVariable })
</script>

<template>
  <div ref="container" class="h-full min-h-[600px]" />
</template>
