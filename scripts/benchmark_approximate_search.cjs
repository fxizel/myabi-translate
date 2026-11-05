// Browser-visible synthetic benchmark, five clients. Runtime state and reports must stay private.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {performance} = require('node:perf_hooks');
const root = path.resolve(__dirname, '..');
const directory = path.resolve(process.env.MYABI_BENCHMARK_DIRECTORY || path.join(root, '.runtime/recherche-approximative'));
assert(directory.startsWith(path.join(root, '.runtime') + path.sep));
const state = JSON.parse(fs.readFileSync(path.join(directory, 'connection.json'), 'utf8'));
const fixtures = JSON.parse(fs.readFileSync(path.join(directory, 'fixtures.json'), 'utf8'));
const cases = JSON.parse(fs.readFileSync(path.join(directory, 'corpus.json'), 'utf8'));
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || path.join(require('node:os').homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const base = `http://127.0.0.1:${state.front_port}`;
const phase = process.env.MYABI_BENCHMARK_PHASE || 'warm';
const hardLimitMilliseconds = 20000;
// Acceptance revised by the user on 2026-11-05; failures remain in the denominator.
const nominalLimitMilliseconds = 5000;
const requiredCompletionRatio = .65;
const chosen = ['fr', 'de', 'it', 'en'].flatMap(language => cases.filter(c => c.language === language && c.kind !== 'boundary').slice(0, 50));
const jobs = chosen.map((c, index) => ({...c, perPage:[25,50,100][index % 3]}));
// Deterministic interleaving prevents language order from determining a warm-up advantage.
jobs.sort((a,b) => require('node:crypto').createHash('sha256').update(a.id).digest('hex').localeCompare(require('node:crypto').createHash('sha256').update(b.id).digest('hex')));
// Assign scope after shuffling, so query kind cannot determine selectivity.
jobs.forEach((job,index) => { job.selective = index % 2 === 0; });
let cursor = 0;
const runs = [];
const implementationHash = () => {
    const hash = require('node:crypto').createHash('sha256');
    for (const file of ['app/Http/Controllers/TermController.php','app/Services/CatalogueSearch/Search.php','app/Services/CatalogueSearch/Profile.php','app/Services/CatalogueSearch/Alignment.php','app/Services/CatalogueSearch/Budget.php','app/Services/CatalogueSearch/SqlSpool.php','app/Services/CatalogueSearch/Projection.php','resources/views/terms/index.blade.php','resources/views/partials/catalogue-match.blade.php','public/js/catalogue-search.js','resources/search/lexical-families.php','config/catalogue-search.php','deploy/docker/nginx.conf.template','deploy/vps/nginx-https.conf','scripts/benchmark_approximate_search.cjs']) hash.update(fs.readFileSync(path.join(root,file)));
    return hash.digest('hex');
};
async function main() {
    const campaignStarted = new Date().toISOString();
    const initialHash = implementationHash();
    const browser = await chromium.launch({headless:true});
    try {
        const pages = await Promise.all(Array.from({length:5}, async () => {
            const context = await browser.newContext({viewport:{width:1440,height:1000},locale:'fr-CH'});
            const page = await context.newPage();
            page.setDefaultTimeout(40000);
            await page.goto(base + '/login');
            await page.locator('[name=email]').fill(fixtures.email);
            await page.locator('[name=password]').fill(fixtures.password);
            await Promise.all([page.waitForURL(url => url.pathname === '/'), page.locator('form').filter({has:page.locator('[name=password]')}).locator('button.primary').click()]);
            return page;
        }));
        await Promise.all(pages.map(async (page, worker) => {
            while (cursor < jobs.length) {
                const job = jobs[cursor++];
                const parameters = new URLSearchParams({approximate:'1',language:job.language,q:job.query,per_page:String(job.perPage)});
                if (job.selective) parameters.set('context', job.id);
                const started = performance.now();
                const startedAt = new Date().toISOString();
                let response;
                let headers = {};
                try {
                    response = await page.goto(base + '/terms?' + parameters, {waitUntil:'domcontentloaded'});
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    headers = await response.allHeaders();
                    const actual = new URL(page.url());
                    assert.equal(actual.pathname, '/terms');
                    assert.equal(actual.searchParams.get('q'), job.query);
                    assert.equal(actual.searchParams.get('language'), job.language);
                    assert.equal(await page.locator('[data-search-state]').count(), 1, 'Missing complete/fallback state');
                    const metadata = await page.locator('[data-search-state]').first().evaluate(el => ({status:el.dataset.searchState,usual:Number(el.dataset.usualCount),approximate:Number(el.dataset.approximateCount)}));
                    await page.locator('#catalogue-table').waitFor();
                    runs.push({case:job.id,language:job.language,selective:job.selective,worker,started_at:startedAt,finished_at:new Date().toISOString(),http:response.status(),milliseconds:performance.now()-started,...metadata,
                        server_ms:Number(headers['x-benchmark-server-ms']),sql_ms:Number(headers['x-benchmark-sql-ms']),peak_php_bytes:Number(headers['x-benchmark-peak-bytes'])});
                } catch (error) {
                    runs.push({case:job.id,language:job.language,selective:job.selective,worker,started_at:startedAt,finished_at:new Date().toISOString(),http:response?.status(),milliseconds:performance.now()-started,status:'error',error_type:error.name,
                        server_ms:headers['x-benchmark-server-ms']===undefined?null:Number(headers['x-benchmark-server-ms']),sql_ms:headers['x-benchmark-sql-ms']===undefined?null:Number(headers['x-benchmark-sql-ms']),peak_php_bytes:headers['x-benchmark-peak-bytes']===undefined?null:Number(headers['x-benchmark-peak-bytes'])});
                }
                if (runs.length % 25 === 0) process.stdout.write(JSON.stringify({phase,completed:runs.length})+'\n');
            }
        }));
        const times = runs.map(run=>run.milliseconds).sort((a,b)=>a-b);
        const successes = runs.filter(run=>run.status==='complete' && run.http===200 && run.milliseconds<=nominalLimitMilliseconds).length;
        const overHardLimit = runs.filter(run=>run.milliseconds>hardLimitMilliseconds).length;
        const report = {phase,started_at:campaignStarted,finished_at:new Date().toISOString(),implementation_sha256:initialHash,code_changed_during_run:initialHash!==implementationHash(),concurrent_clients:5,php_workers:3,visible_time_measured:true,terms:fixtures.terms,corpus_sha256:fixtures.corpus_sha256,
            environment_qualification:'Windows portable PHP/MariaDB, three PHP servers behind a local test proxy; Linux PHP-FPM/Nginx target not qualified',
            samples:runs.length,nominal_limit_ms:nominalLimitMilliseconds,required_complete_fraction:requiredCompletionRatio,hard_limit_ms:hardLimitMilliseconds,over_hard_limit:overHardLimit,complete_within_5s:successes,complete_within_5s_ratio:successes/runs.length,
            p50_ms:times[Math.ceil(times.length*.5)-1],p95_ms:times[Math.ceil(times.length*.95)-1],max_ms:Math.max(...times),
            fallbacks:runs.filter(r=>['timeout','unavailable'].includes(r.status)).length,errors:runs.filter(r=>r.status==='error').length,runs};
        fs.writeFileSync(path.join(directory, `visible-${phase}.json`), JSON.stringify(report,null,2));
        process.stdout.write(JSON.stringify({...report,runs:undefined})+'\n');
        process.exitCode = report.complete_within_5s_ratio >= requiredCompletionRatio && overHardLimit===0 && !report.code_changed_during_run ? 0 : 1;
    } finally {
        await browser.close();
    }
}
main().catch(error=>{process.stderr.write(error.name+': synthetic browser benchmark failed; inspect private runtime.\n');process.exitCode=1;});
