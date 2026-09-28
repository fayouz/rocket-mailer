<script setup lang="ts">
/**
 * HTML of a received message. Already sanitized by the server (no scripts, forms, style sheets…); rendered in
 * a sandbox without scripts, under a CSP that only lets remote images load when the reader asked for them.
 */
const props = defineProps<{ html: string, images: boolean }>()

const frame = useTemplateRef<HTMLIFrameElement>('frame')
const height = ref(120)

const srcdoc = computed(() => {
  const csp = `default-src 'none'; style-src 'unsafe-inline'; img-src ${props.images ? 'https: http: data:' : 'data:'}; font-src data:`
  return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank">`
    + '<style>body{margin:0;font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;color:#18181b;word-break:break-word}img{max-width:100%;height:auto}</style>'
    + `</head><body>${props.html}</body></html>`
})

function resize() {
  const body = frame.value?.contentDocument?.body
  if (body) height.value = Math.min(Math.max(body.scrollHeight + 16, 60), 4000)
}
</script>

<template>
  <!-- allow-same-origin without allow-scripts: nothing runs, the parent only measures the height. -->
  <iframe
    ref="frame"
    :srcdoc="srcdoc"
    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
    referrerpolicy="no-referrer"
    title="Contenu du message"
    class="w-full rounded-md bg-white"
    :style="{ height: `${height}px` }"
    @load="resize"
  />
</template>
