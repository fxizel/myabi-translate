import fixtureData from '../../tests/Fixtures/browser-extension-text.json';
import { describe, expect, it } from 'vitest';
import { normalizeText, searchKey } from '../src/shared/text';

const fixtures = fixtureData as { normalizationVersion: number; cases: Array<{ name: string; input: string; literal: string; searchKey: string }> };

describe('fixtures partagées PHP / TypeScript de normalisation v2', () => {
    it('emploie la version commune', () => expect(fixtures.normalizationVersion).toBe(2));
    it.each(fixtures.cases)('$name', fixture => {
        expect(normalizeText(fixture.input)).toBe(fixture.literal);
        expect(searchKey(fixture.input)).toBe(fixture.searchKey);
    });
});
