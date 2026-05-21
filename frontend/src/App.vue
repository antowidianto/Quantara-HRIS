<script setup>
import { RouterView } from 'vue-router'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PwaBanner from '@/components/PwaBanner.vue'
</script>

<template>
  <RouterView />
  <ConfirmDialog />
  <PwaBanner />
</template>