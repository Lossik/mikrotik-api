// Runs only in trusted schedule/dispatch jobs (never in a pull_request_target context).
const fs = require('node:fs');
const cp = require('node:child_process');

async function readFile(github, context, path) {
  const {data} = await github.rest.repos.getContent({...context.repo, path, ref: 'routeros-tested'});
  return Buffer.from(data.content, 'base64').toString('utf8');
}

async function branchSha(github, context) {
  try {
    return (await github.rest.git.getRef({...context.repo, ref: 'heads/routeros-tested'})).data.object.sha;
  } catch (error) {
    if (error.status !== 404) throw error;
    return undefined;
  }
}

async function discover({github, context, core}) {
  fs.mkdirSync('.routeros-responses', {recursive: true});
  for (const channel of ['stable', 'testing', 'long-term']) {
    cp.execFileSync('curl', ['--fail', '--silent', '--show-error', '--location', '--retry', '3',
      '--retry-all-errors', '--connect-timeout', '10', '--max-time', '20',
      '-o', `.routeros-responses/${channel}`, `https://upgrade.mikrotik.com/routeros/NEWESTa7.${channel}`]);
  }
  const parent = await branchSha(github, context);
  fs.writeFileSync('.routeros-tested.jsonl', parent ? await readFile(github, context, 'tested.jsonl') : '');
  const discovery = JSON.parse(cp.execFileSync('docker', ['run', '--rm', '-v', `${process.cwd()}:/app`, '-w', '/app',
    'php:8.2.35-cli', 'php', 'tools/chr/tested.php', 'discover', 'tools/chr/versions.txt',
    '.routeros-tested.jsonl', '.routeros-responses'], {encoding: 'utf8'}));
  core.setOutput('matrix', JSON.stringify({include: discovery.include}));
  core.setOutput('channel_heads', JSON.stringify(discovery.heads));
  core.setOutput('has_new', discovery.include.length > 0);
}

async function report({github, context}, version, unavailable, details = '') {
  const title = unavailable ? `RouterOS ${version}: image no longer available`
    : `RouterOS ${version}: live suite failed (${context.repo.repo})`;
  const run = `${context.serverUrl}/${context.repo.owner}/${context.repo.repo}/actions/runs/${context.runId}`;
  const body = unavailable
    ? `${run}\n\nMikroTik returned HTTP 404/410. Consider removing this version from versions.txt. For a boundary, document in README/CHANGELOG which behavior is no longer verified live. No backup image is used.`
    : `${run}\n\n${details.slice(0, 16000) || 'See the failed step and live log in the workflow run.'}`;
  const issues = await github.paginate(github.rest.issues.listForRepo, {...context.repo, state: 'open', per_page: 100});
  const existing = issues.find(issue => !issue.pull_request && issue.title === title);
  if (existing) await github.rest.issues.createComment({...context.repo, issue_number: existing.number, body});
  else await github.rest.issues.create({...context.repo, title, body});
}

async function publish({github, context}) {
  // Only this literal ref can be written. Never update the code/default branch.
  const ref = 'heads/routeros-tested';
  const parent = await branchSha(github, context);
  const previous = parent ? await readFile(github, context, 'tested.jsonl') : '';
  const records = previous.trim() ? previous.trim().split('\n').map(line => JSON.parse(line)) : [];
  const seen = new Set(records.map(row => row.version));
  for (const name of fs.existsSync('.routeros-results') ? fs.readdirSync('.routeros-results') : []) {
    if (!name.endsWith('.json')) continue;
    const row = JSON.parse(fs.readFileSync(`.routeros-results/${name}`, 'utf8'));
    if (!seen.has(row.version)) {
      records.push(row);
      seen.add(row.version);
    }
  }
  const now = new Date();
  const heartbeat = parent ? await readFile(github, context, 'last-check.txt') : '';
  const previousHeads = parent ? await readFile(github, context, 'channel-heads.json') : '';
  const heads = JSON.parse(process.env.CHR_CHANNEL_HEADS);
  for (const channel of ['stable', 'testing', 'long-term']) {
    if (!/^7\.[0-9]+(?:\.[0-9]+)?(?:(?:rc|beta)[0-9]+)?$/.test(heads[channel])) throw new Error('Invalid channel head');
  }
  const headContent = JSON.stringify(heads) + '\n';
  const content = records.map(row => JSON.stringify(row)).join('\n') + (records.length ? '\n' : '');
  if (content === previous && headContent === previousHeads && heartbeat && now - new Date(heartbeat.trim()) < 30 * 86400000) return;
  const tree = await github.rest.git.createTree({...context.repo, tree: [
    {path: 'tested.jsonl', mode: '100644', type: 'blob', content},
    {path: 'channel-heads.json', mode: '100644', type: 'blob', content: headContent},
    {path: 'last-check.txt', mode: '100644', type: 'blob', content: now.toISOString() + '\n'}
  ]});
  const commit = await github.rest.git.createCommit({...context.repo, message: 'Record RouterOS checks',
    tree: tree.data.sha, parents: parent ? [parent] : []});
  if (parent) await github.rest.git.updateRef({...context.repo, ref, sha: commit.data.sha, force: false});
  else await github.rest.git.createRef({...context.repo, ref: `refs/${ref}`, sha: commit.data.sha});
}

module.exports = {discover, report, publish};
