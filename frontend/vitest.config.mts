import { readFileSync } from 'node:fs';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Angular supplies virtual compiled modules; disk module caching can reuse stale templates.
    fsModuleCache: false,
    provide: {
      familyguardParentHttp:
        process.env['FAMILYGUARD_PARENT_HTTP'] === '1'
          ? {
              ...JSON.parse(readFileSync(process.env['FAMILYGUARD_PARENT_FIXTURE']!, 'utf8')),
              url: process.env['FAMILYGUARD_PARENT_URL'],
            }
          : null,
    },
  },
});
