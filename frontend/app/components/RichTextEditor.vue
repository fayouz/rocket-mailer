<script setup lang="ts">
import { Ckeditor } from '@ckeditor/ckeditor5-vue'
import {
  Alignment,
  Autoformat,
  BlockQuote,
  Bold,
  ClassicEditor,
  Essentials,
  FontBackgroundColor,
  FontColor,
  FontFamily,
  FontSize,
  FullPage,
  GeneralHtmlSupport,
  Heading,
  HorizontalLine,
  HtmlComment,
  Image,
  ImageInsertViaUrl,
  ImageResize,
  ImageStyle,
  ImageToolbar,
  Italic,
  Link,
  List,
  Paragraph,
  PasteFromOffice,
  RemoveFormat,
  SourceEditing,
  Strikethrough,
  Table,
  TableCellProperties,
  TableProperties,
  TableToolbar,
  Underline,
  type EditorConfig,
} from 'ckeditor5'
import 'ckeditor5/ckeditor5.css'

const model = defineModel<string>({ required: true })
defineProps<{ disabled?: boolean }>()

const config: EditorConfig = {
  licenseKey: 'GPL',
  plugins: [
    Essentials, Paragraph, Heading, Bold, Italic, Underline, Strikethrough, RemoveFormat, Autoformat,
    Link, List, BlockQuote, HorizontalLine, Alignment,
    FontColor, FontBackgroundColor, FontSize, FontFamily,
    Table, TableToolbar, TableProperties, TableCellProperties,
    Image, ImageToolbar, ImageStyle, ImageResize, ImageInsertViaUrl,
    PasteFromOffice, SourceEditing,
    // Keep the markup of GrapesJS templates (tables, inline styles, <style>, MSO comments) intact.
    GeneralHtmlSupport, HtmlComment, FullPage,
  ],
  toolbar: {
    items: [
      'undo', 'redo', '|',
      'heading', '|',
      'bold', 'italic', 'underline', 'strikethrough', 'removeFormat', '|',
      'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor', '|',
      'alignment', 'bulletedList', 'numberedList', 'blockQuote', '|',
      'link', 'insertImageViaUrl', 'insertTable', 'horizontalLine', '|',
      'sourceEditing',
    ],
    shouldNotGroupWhenFull: false,
  },
  htmlSupport: {
    allow: [{ name: /.*/, attributes: true, classes: true, styles: true }],
    disallow: [{ name: /^(script|iframe|object|embed)$/ }, { attributes: [{ key: /^on.*/i, value: true }] }],
  },
  link: { addTargetToExternalLinks: true, defaultProtocol: 'https://' },
  image: { toolbar: ['imageStyle:inline', 'imageStyle:block', 'imageStyle:side', '|', 'toggleImageCaption', 'imageTextAlternative'] },
  table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells', 'tableProperties', 'tableCellProperties'] },
}
</script>

<template>
  <div class="rich-text-editor">
    <Ckeditor v-model="model" :editor="ClassicEditor" :config="config" :disabled="disabled" />
  </div>
</template>

<style>
.rich-text-editor .ck-editor__editable {
  min-height: 360px;
}
.rich-text-editor .ck.ck-editor__main > .ck-editor__editable {
  background: white;
  color: #111;
}
</style>
