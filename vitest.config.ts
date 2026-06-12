import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.ts'],
        environment: 'node', // parser needs no DOM; DecompressionStream is global in node 22
    },
});
