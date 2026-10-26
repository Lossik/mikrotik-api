const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {publish, report} = require('../github.cjs');

const context = {repo: {owner: 'owner', repo: 'repo'}, serverUrl: 'https://github.com', runId: 123};
const initialHeads = {stable: '7.25', testing: '7.25', 'long-term': '7.23.8'};
process.env.CHR_CHANNEL_HEADS = JSON.stringify(initialHeads);

// GitHub is an external service. The fake records the requests that would change remote state.
function service(files = {}, parent) {
  if (parent) files = {'channel-heads.json': JSON.stringify(initialHeads) + '\n', ...files};
  const writes = [];
  const github = {rest: {repos: {getContent: async ({path: name}) => {
    if (!(name in files)) throw Object.assign(new Error('missing'), {status: 404});
    return {data: {content: Buffer.from(files[name]).toString('base64')}};
  }}, git: {
    getRef: async () => {
      if (!parent) throw Object.assign(new Error('missing'), {status: 404});
      return {data: {object: {sha: parent}}};
    },
    createTree: async request => {writes.push(['tree', request]); return {data: {sha: 'tree'}};},
    createCommit: async request => {writes.push(['commit', request]); return {data: {sha: 'commit'}};},
    createRef: async request => {writes.push(['create', request]);},
    updateRef: async request => {writes.push(['update', request]);}
  }, issues: {
    listForRepo: () => {},
    create: async request => {writes.push(['issue', request]);},
    createComment: async request => {writes.push(['comment', request]);}
  }}, paginate: async () => []};
  return {github, writes};
}

(async () => {
  const original = process.cwd();
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'routeros-github-'));
  process.chdir(tmp);
  try {
    // With nothing new, recent heartbeat means no remote write at all.
    let api = service({'tested.jsonl': '', 'last-check.txt': new Date().toISOString()}, 'old');
    await publish({...api, context});
    assert.deepEqual(api.writes, []);

    // A quiet repository still receives a heartbeat on the data branch, never a code branch.
    api = service({'tested.jsonl': '', 'last-check.txt': '2000-01-01T00:00:00Z'}, 'old');
    await publish({...api, context});
    assert.deepEqual(api.writes.at(-1), ['update', {...context.repo, ref: 'heads/routeros-tested', sha: 'commit', force: false}]);
    assert.deepEqual(api.writes[1][1].parents, ['old']);
    assert.deepEqual(api.writes[0][1].tree.map(row => row.path), ['tested.jsonl', 'channel-heads.json', 'last-check.txt']);

    // First successful release creates an orphan; another publication must retain failures and old successes.
    fs.mkdirSync('.routeros-results');
    const passed = {version: '7.25', result: 'passed'};
    const failed = {version: '7.26beta1', result: 'failed'};
    fs.writeFileSync('.routeros-results/one.json', JSON.stringify(passed));
    api = service();
    await publish({...api, context});
    assert.deepEqual(api.writes[1][1].parents, []);
    assert.deepEqual(api.writes.at(-1), ['create', {...context.repo, ref: 'refs/heads/routeros-tested', sha: 'commit'}]);
    assert.equal(api.writes[0][1].tree[0].content, JSON.stringify(passed) + '\n');
    fs.writeFileSync('.routeros-results/two.json', JSON.stringify(failed));
    api = service({'tested.jsonl': JSON.stringify(passed) + '\n', 'last-check.txt': new Date().toISOString()}, 'old');
    await publish({...api, context});
    assert.equal(api.writes[0][1].tree[0].content, [passed, failed].map(row => JSON.stringify(row) + '\n').join(''));

    // A known green version can become long-term without another live artifact or heartbeat being due.
    fs.rmSync('.routeros-results', {recursive: true});
    const previous = JSON.stringify(passed) + '\n';
    api = service({'tested.jsonl': previous, 'last-check.txt': new Date().toISOString()}, 'old');
    const promoted = {...initialHeads, 'long-term': '7.25'};
    process.env.CHR_CHANNEL_HEADS = JSON.stringify(promoted);
    await publish({...api, context});
    assert.equal(api.writes[0][1].tree[0].content, previous);
    assert.equal(api.writes[0][1].tree[1].content, JSON.stringify(promoted) + '\n');
    process.env.CHR_CHANNEL_HEADS = JSON.stringify(initialHeads);

    // Missing auth/server access must fail, not overwrite the branch as if it did not exist.
    api = service();
    api.github.rest.git.getRef = async () => {throw Object.assign(new Error('auth'), {status: 403});};
    await assert.rejects(() => publish({...api, context}));
    assert.deepEqual(api.writes, []);
    api = service({'last-check.txt': new Date().toISOString()}, 'old');
    await assert.rejects(() => publish({...api, context}));
    assert.deepEqual(api.writes, []);

    // An existing issue for the version receives a comment; other versions don't suppress a new issue.
    api = service();
    await report({...api, context}, '7.25', false, 'failed test');
    const title = api.writes[0][1].title;
    assert.equal(api.writes[0][0], 'issue');
    api.github.paginate = async () => [{title, number: 42}];
    await report({...api, context}, '7.25', false, 'retry failed');
    assert.equal(api.writes[1][0], 'comment');
    assert.equal(api.writes[1][1].issue_number, 42);
    await report({...api, context}, '7.26', true);
    assert.equal(api.writes[2][0], 'issue');
    assert.match(api.writes[2][1].body, /removing this version/);
    console.log('github: publication, heartbeat, errors and issue deduplication OK');
  } finally {
    process.chdir(original);
    fs.rmSync(tmp, {recursive: true, force: true});
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
