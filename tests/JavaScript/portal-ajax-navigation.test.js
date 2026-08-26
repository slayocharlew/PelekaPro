import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { isPortalDestination } from '../../resources/js/portal.js';

test('portal navigation accepts only same-origin portal destinations', () => {
    const origin = 'https://portal.pelekapro.test';
    const base = `${origin}/portal`;

    assert.equal(isPortalDestination(`${base}/deliveries`, base, origin), true);
    assert.equal(isPortalDestination(`${base}/drivers?page=2`, base, origin), true);
    assert.equal(isPortalDestination(`${origin}/logout`, base, origin), false);
    assert.equal(isPortalDestination(`${origin}/tracking`, base, origin), false);
    assert.equal(isPortalDestination('https://attacker.test/portal', base, origin), false);
    assert.equal(isPortalDestination('javascript:alert(1)', base, origin), false);
});

test('portal forms use one CSRF-protected partial request without browser storage', async () => {
    const source = await readFile(
        new URL('../../resources/js/portal.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /credentials: 'same-origin'/);
    assert.match(source, /cache: 'no-store'/);
    assert.match(source, /'X-CSRF-TOKEN'/);
    assert.match(source, /'X-PelekaPro-Partial': '1'/);
    assert.match(source, /new FormData\(form/);
    assert.match(source, /portalMutationInFlight/);
    assert.match(source, /event\.submitter/);
    assert.match(source, /data-no-portal-ajax/);
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
    assert.equal(source.includes('indexedDB'), false);
    assert.equal(source.includes('Authorization'), false);
});

test('partial navigation replaces portal content and reinitializes page behavior', async () => {
    const [portal, layout, app, onboarding] = await Promise.all([
        '../../resources/js/portal.js',
        '../../resources/views/layouts/portal.blade.php',
        '../../resources/js/app.js',
        '../../resources/js/business-onboarding.js',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')));

    assert.match(portal, /currentMain\.replaceWith\(nextMain\)/);
    assert.match(portal, /window\.history\.pushState/);
    assert.match(portal, /window\.addEventListener\('popstate'/);
    assert.match(portal, /pelekapro:portal-rendered/);
    assert.match(layout, /request\(\)->header\('X-PelekaPro-Partial'\) === '1'/);
    assert.match(layout, /data-portal-main/);
    assert.match(layout, /data-portal-navigation-state/);
    assert.match(app, /pelekapro:portal-rendered/);
    assert.match(onboarding, /businessOnboardingReady/);
});
