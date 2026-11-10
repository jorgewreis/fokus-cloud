"""Read-only GitHub gate for manual publication of the exact validated main SHA."""
from dataclasses import dataclass
from datetime import datetime
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

WORKFLOW_PATH = '.github/workflows/security-homologation.yml'
SHA = re.compile(r'[0-9a-f]{40}')
REPOSITORY = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*')


class GateError(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise GateError(message)


def positive_integer(value):
    return type(value) is int and value > 0


def timestamp(value):
    require(isinstance(value, str), 'Missing run timestamp')
    try:
        return datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')
    except ValueError:
        raise GateError('Malformed run timestamp') from None


@dataclass(frozen=True)
class Context:
    repository: str
    repository_id: int
    sha: str


def context(environment, checkout_sha):
    require(environment.get('GITHUB_EVENT_NAME') == 'workflow_dispatch', 'Manual dispatch required')
    require(environment.get('GITHUB_REF') == 'refs/heads/main', 'Main ref required')
    candidate = environment.get('EXPECTED_CANDIDATE', '')
    require(bool(SHA.fullmatch(candidate)), 'Full lowercase candidate SHA required')
    require(candidate == environment.get('GITHUB_SHA') == environment.get('GITHUB_WORKFLOW_SHA')
            == checkout_sha, 'Candidate, workflow and checkout revisions must match')
    repository = environment.get('GITHUB_REPOSITORY', '')
    require(bool(REPOSITORY.fullmatch(repository)), 'Invalid repository identity')
    identifier = environment.get('GITHUB_REPOSITORY_ID', '')
    require(bool(re.fullmatch(r'[1-9][0-9]*', identifier)), 'Missing repository identifier')
    return Context(repository, int(identifier), candidate)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate JSON field')
        result[key] = value
    return result


class GitHub:
    """GET only, fixed API origin, bounded reads, no redirects or token logging."""
    def __init__(self, ctx, token):
        require(isinstance(token, str) and bool(token.strip()), 'GitHub read token required')
        self.prefix = f'https://api.github.com/repos/{ctx.repository}'
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect())

    def get(self, path):
        require(path.startswith('/') and not path.startswith('//'), 'Invalid API path')
        request = urllib.request.Request(self.prefix + path, headers={
            'Accept': 'application/vnd.github+json',
            'Authorization': 'Bearer ' + self.token,
            'X-GitHub-Api-Version': '2022-11-28',
        }, method='GET')
        try:
            with self.opener.open(request, timeout=20) as response:
                require(response.status == 200, 'Unexpected GitHub response status')
                require(response.headers.get_content_type() == 'application/json', 'Non-JSON GitHub response')
                data = response.read(4 * 1024 * 1024 + 1)
                require(len(data) <= 4 * 1024 * 1024, 'Oversized GitHub response')
            value = json.loads(data, object_pairs_hook=unique_object)
            require(isinstance(value, dict), 'Expected GitHub response object')
            return value
        except (urllib.error.URLError, OSError, TimeoutError, ValueError, UnicodeError):
            raise GateError('GitHub read failed; no deployment permitted') from None


def check_main(ctx, api):
    ref = api.get('/git/ref/heads/main')
    require(ref.get('ref') == 'refs/heads/main', 'Unexpected main ref')
    obj = ref.get('object')
    require(isinstance(obj, dict) and obj.get('type') == 'commit'
            and obj.get('sha') == ctx.sha, 'Current main differs from requested revision')


def checked_run(run, ctx, workflow_id):
    require(isinstance(run, dict), 'Malformed workflow run')
    for key in ('id', 'run_number', 'run_attempt'):
        require(positive_integer(run.get(key)), 'Missing run identity or attempt')
    require(positive_integer(run.get('workflow_id')) and run['workflow_id'] == workflow_id
            and run.get('path') == WORKFLOW_PATH,
            'Run belongs to a different workflow')
    require(run.get('head_sha') == ctx.sha and run.get('event') == 'workflow_dispatch',
            'Run does not validate the requested workflow revision')
    for key in ('repository', 'head_repository'):
        repository = run.get(key)
        require(isinstance(repository, dict) and positive_integer(repository.get('id'))
                and repository['id'] == ctx.repository_id
                and repository.get('full_name') == ctx.repository, 'Run repository identity mismatch')
    commit = run.get('head_commit')
    require(isinstance(commit, dict) and commit.get('id') == ctx.sha, 'Run commit identity mismatch')
    created = timestamp(run.get('created_at'))
    started = timestamp(run.get('run_started_at'))
    updated = timestamp(run.get('updated_at'))
    require(created <= started <= updated, 'Inconsistent run timestamps')
    status, conclusion = run.get('status'), run.get('conclusion')
    require(status in ('queued', 'in_progress', 'completed', 'waiting', 'requested', 'pending'),
            'Unknown workflow run status')
    require((status == 'completed' and conclusion in (
        'success', 'failure', 'neutral', 'cancelled', 'skipped', 'timed_out', 'action_required', 'stale'))
        or (status != 'completed' and conclusion is None), 'Inconsistent workflow conclusion')
    return (run['id'], run['run_number'], run['run_attempt'], created, started, updated, status, conclusion)


def runs_for_revision(ctx, api, workflow_id):
    runs = []
    total = None
    for page in range(1, 11):
        query = urllib.parse.urlencode({'head_sha': ctx.sha, 'per_page': 100, 'page': page})
        response = api.get(f'/actions/workflows/{workflow_id}/runs?{query}')
        count = response.get('total_count')
        batch = response.get('workflow_runs')
        require(type(count) is int and 0 < count <= 1000, 'No unambiguous bounded validation history')
        require(total is None or total == count, 'Validation history changed during pagination')
        total = count
        require(isinstance(batch, list) and 0 < len(batch) <= 100, 'Missing validation history page')
        runs.extend(checked_run(run, ctx, workflow_id) for run in batch)
        require(len({run[0] for run in runs}) == len(runs), 'Duplicate validation runs')
        require(len({run[1] for run in runs}) == len(runs), 'Duplicate workflow run numbers')
        require(len(runs) <= total, 'Inconsistent validation run count')
        if len(runs) == total:
            return sorted(runs)
    raise GateError('Validation history is incomplete')


def latest_success(runs):
    # An unfinished older execution can race the newest result too. Require it
    # to settle rather than treating a concurrent history as publication proof.
    require(all(run[6] == 'completed' for run in runs), 'Validation is still pending or running')
    by_number = max(runs, key=lambda run: run[1])
    created = max(run[3] for run in runs)
    started = max(run[4] for run in runs)
    updated = max(run[5] for run in runs)
    require(sum(run[3] == created for run in runs) == 1
            and sum(run[4] == started for run in runs) == 1
            and sum(run[5] == updated for run in runs) == 1,
            'Ambiguous latest validation execution')
    require(by_number[3] == created and by_number[4] == started and by_number[5] == updated,
            'A rerun or concurrent validation makes the latest execution ambiguous')
    require(by_number[7] == 'success', 'Latest validation did not succeed')
    return by_number


def verify(ctx, api):
    check_main(ctx, api)
    workflow = api.get('/actions/workflows/security-homologation.yml')
    workflow_id = workflow.get('id')
    require(positive_integer(workflow_id) and workflow.get('path') == WORKFLOW_PATH
            and workflow.get('state') == 'active', 'Expected validation workflow unavailable')
    history = runs_for_revision(ctx, api, workflow_id)
    selected = latest_success(history)
    detail = checked_run(api.get(f'/actions/runs/{selected[0]}'), ctx, workflow_id)
    require(detail == selected, 'Selected validation or attempt changed')
    # Repeat the complete query, without a success filter, to reject a newer run
    # or rerun appearing while details were checked. No retries hide instability.
    require(runs_for_revision(ctx, api, workflow_id) == history, 'Validation history changed during verification')
    check_main(ctx, api)
    return selected[0], selected[2]


def main():
    try:
        head = subprocess.check_output(['git', 'rev-parse', '--verify', 'HEAD'],
                                       text=True, stderr=subprocess.DEVNULL).strip()
        ctx = context(os.environ, head)
        run_id, attempt = verify(ctx, GitHub(ctx, os.environ.get('GH_TOKEN', '')))
    except (GateError, subprocess.SubprocessError, OSError) as error:
        message = str(error) if isinstance(error, GateError) else 'Local revision could not be verified'
        print('Publication gate refused deployment: ' + message, file=sys.stderr)
        return 1
    print(f'Publication gate passed for {ctx.sha}; validation run {run_id}, attempt {attempt}.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
