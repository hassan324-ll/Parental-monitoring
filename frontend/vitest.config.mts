import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Angular supplies virtual compiled modules; disk module caching can reuse stale templates.
    fsModuleCache: false,
  },
});
