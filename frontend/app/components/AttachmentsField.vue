<script setup lang="ts">
import type { Attachment } from '~/types/api'

const MAX_ATTACHMENTS = 10

const attachments = defineModel<Attachment[]>({ required: true })
const props = defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ busy: [busy: boolean] }>()

const api = useApi()
const toast = useToast()
const input = useTemplateRef<HTMLInputElement>('input')
const uploading = ref<{ key: number, name: string }[]>([])
const dragging = ref(false)
let nextKey = 0

watch(() => uploading.value.length, count => emit('busy', count > 0))

const totalSize = computed(() => attachments.value.reduce((sum, a) => sum + a.size, 0))

async function upload(files: FileList | File[]) {
  for (const file of Array.from(files)) {
    if (attachments.value.length + uploading.value.length >= MAX_ATTACHMENTS) {
      toast.add({ title: `${MAX_ATTACHMENTS} pièces jointes maximum`, color: 'warning' })
      return
    }

    const pending = { key: nextKey++, name: file.name }
    uploading.value.push(pending)
    try {
      const body = new FormData()
      body.append('file', file)
      attachments.value = [...attachments.value, await api<Attachment>('/api/attachments', { method: 'POST', body })]
    }
    catch (error) {
      toast.add({ title: `« ${file.name} » n'a pas pu être joint`, description: apiErrorMessage(error), color: 'error' })
    }
    finally {
      uploading.value = uploading.value.filter(u => u.key !== pending.key)
    }
  }
}

async function remove(attachment: Attachment) {
  attachments.value = attachments.value.filter(a => a.id !== attachment.id)
  try {
    await api(`/api/attachments/${attachment.id}`, { method: 'DELETE' })
  }
  catch {
    // Not blocking: unsent uploads are purged server-side anyway.
  }
}

function onPick(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.length) upload(target.files)
  target.value = ''
}

function onDrop(event: DragEvent) {
  dragging.value = false
  if (!props.disabled && event.dataTransfer?.files.length) upload(event.dataTransfer.files)
}

function iconFor(mimeType: string) {
  if (mimeType.startsWith('image/')) return 'i-lucide-file-image'
  if (mimeType === 'application/pdf') return 'i-lucide-file-text'
  if (mimeType.includes('spreadsheet') || mimeType.includes('excel') || mimeType === 'text/csv') return 'i-lucide-file-spreadsheet'
  if (mimeType.includes('zip') || mimeType.includes('compressed')) return 'i-lucide-file-archive'
  return 'i-lucide-file'
}

defineExpose({ upload })
</script>

<template>
  <div
    class="rounded-md border border-dashed p-3 transition-colors"
    :class="dragging ? 'border-primary bg-primary/5' : 'border-default'"
    @dragover.prevent="dragging = !disabled"
    @dragleave.prevent="dragging = false"
    @drop.prevent="onDrop"
  >
    <div class="flex flex-wrap items-center gap-2">
      <UButton
        icon="i-lucide-paperclip"
        label="Joindre des fichiers"
        color="neutral"
        variant="outline"
        size="sm"
        :disabled="disabled || attachments.length >= MAX_ATTACHMENTS"
        @click="input?.click()"
      />
      <span class="text-xs text-muted">
        ou glissez-déposez ici
        <template v-if="attachments.length"> · {{ attachments.length }} fichier(s), {{ formatSize(totalSize) }}</template>
      </span>
      <input ref="input" type="file" multiple class="hidden" data-testid="attachment-input" @change="onPick">
    </div>

    <ul v-if="attachments.length || uploading.length" class="mt-3 flex flex-wrap gap-2">
      <li
        v-for="attachment in attachments"
        :key="attachment.id"
        class="flex max-w-full items-center gap-2 rounded-md bg-elevated px-2 py-1 text-sm"
      >
        <UIcon :name="iconFor(attachment.mimeType)" class="size-4 shrink-0 text-muted" />
        <span class="truncate">{{ attachment.filename }}</span>
        <span class="shrink-0 text-xs text-dimmed">{{ formatSize(attachment.size) }}</span>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="link"
          size="xs"
          :aria-label="`Retirer ${attachment.filename}`"
          :disabled="disabled"
          @click="remove(attachment)"
        />
      </li>
      <li
        v-for="pending in uploading"
        :key="pending.key"
        class="flex items-center gap-2 rounded-md bg-elevated px-2 py-1 text-sm text-muted"
      >
        <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
        <span class="truncate">{{ pending.name }}</span>
      </li>
    </ul>
  </div>
</template>
