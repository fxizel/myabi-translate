import type { Server } from 'node:http';

export function createMockServer(options?: {
    slowMs?: number;
    log?: (line: string) => void;
}): Server & { requests: Array<Record<string, unknown>> };
