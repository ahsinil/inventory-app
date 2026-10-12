<template>
  <div class="app-container">
    <router-view></router-view>
  </div>
</template>

<script setup lang="ts">
// Root app component
</script>

<style>
/* Base styles or import a design system like Tailwind/Vanilla CSS */
body {
  margin: 0;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background-color: #f3f4f6;
}
.app-container {
  min-height: 100vh;
}
</style>
