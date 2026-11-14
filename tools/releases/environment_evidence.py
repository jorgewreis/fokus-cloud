#!/usr/bin/env python3
"""Experimental read-only GitHub environment evidence, not an activation gate.

Inputs must originate from a verified OIDC token and a server-owned policy.
This module alone does not authenticate the caller or establish atomic approval.
"""
from datetime import datetime
import json
import re
import urllib.error
import urllib.request

import github_oidc


def require(condition, reason):
    if not condition: raise ValueError(reason)


def timestamp(value):
    require(isinstance(value,str), 'invalid_timestamp')
    try: return datetime.strptime(value,'%Y-%m-%dT%H:%M:%SZ')
    except ValueError as error: raise ValueError('invalid_timestamp') from error


class GitHubRead:
    """GET-only fixed origin; no redirects, proxy inheritance or secret logging."""
    def __init__(self, repository, token):
        require(isinstance(repository,str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*',repository), 'invalid_repository')
        require(isinstance(token,str) and token.strip(), 'read_token_required')
        self.prefix='https://api.github.com/repos/'+repository
        self.token=token
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),
             urllib.request.HTTPSHandler(context=github_oidc.issuer_tls_context()),github_oidc.NoRedirect())
    def get(self,path):
        require(isinstance(path,str) and path.startswith('/') and not path.startswith('//') and '..' not in path, 'invalid_api_path')
        request=urllib.request.Request(self.prefix+path,headers={'Accept':'application/vnd.github+json',
                'Authorization':'Bearer '+self.token,'X-GitHub-Api-Version':'2022-11-28'},method='GET')
        try:
            with self.opener.open(request,timeout=20) as response:
                require(response.status==200 and response.headers.get_content_type()=='application/json','invalid_response')
                body=response.read(1048577)
                require(len(body)<=1048576,'oversized_response')
            return json.loads(body,object_pairs_hook=github_oidc.unique)
        except (OSError,ValueError,UnicodeError,urllib.error.URLError) as error:
            raise ValueError('github_read_failed') from error


def check(identity, policy, api):
    """Check only first-attempt synthetic evidence; reject ambiguous review history."""
    require(type(identity) is github_oidc.VerifiedIdentity and isinstance(policy,dict),'invalid_input')
    require(set(policy)=={'repository','repository_id','environment_id','reviewer_ids'},'invalid_policy')
    repo=policy['repository']; repo_id=policy['repository_id']; env_id=policy['environment_id']; reviewers=policy['reviewer_ids']
    require(isinstance(repo_id,str) and re.fullmatch(r'[1-9][0-9]*',repo_id),'invalid_policy')
    require(type(env_id) is int and env_id>0 and isinstance(reviewers,list) and 0<len(reviewers)<=6
            and all(type(x) is int and x>0 for x in reviewers) and len(set(reviewers))==len(reviewers),'invalid_policy')
    require(identity.repository==repo and identity.repository_id==repo_id,'identity_repository_mismatch')
    for key in ('run_id','run_attempt','check_run_id','actor_id'):
        value=getattr(identity,key)
        require(isinstance(value,str) and re.fullmatch(r'[1-9][0-9]*',value),'invalid_identity')
    require(identity.run_attempt=='1','rerun_approval_ambiguous')
    sha=identity.sha;require(isinstance(sha,str) and re.fullmatch(r'[a-f0-9]{40}',sha),'invalid_identity')
    env_path='/environments/production'; run_path='/actions/runs/'+identity.run_id
    check_path='/check-runs/'+identity.check_run_id; reviews_path=run_path+'/approvals'
    paths=('/git/ref/heads/main','/actions/workflows/deploy.yml',env_path,run_path,check_path,reviews_path)
    first=[api.get(path) for path in paths]
    ref, workflow, environment, run, job, approvals=first
    require(isinstance(ref,dict) and ref.get('ref')=='refs/heads/main' and isinstance(ref.get('object'),dict)
            and ref['object'].get('type')=='commit' and ref['object'].get('sha')==sha,'main_changed')
    require(isinstance(workflow,dict) and type(workflow.get('id')) is int and workflow.get('id')>0
            and workflow.get('path')=='.github/workflows/deploy.yml' and workflow.get('state')=='active','workflow_mismatch')
    require(isinstance(run,dict) and run.get('id')==int(identity.run_id) and run.get('run_attempt')==1
            and run.get('workflow_id')==workflow['id'] and run.get('head_sha')==sha and run.get('head_branch')=='main'
            and run.get('event')=='workflow_dispatch' and run.get('status')=='in_progress','run_mismatch')
    require(isinstance(run.get('actor'),dict) and run['actor'].get('id')==int(identity.actor_id),'actor_mismatch')
    for key in ('repository','head_repository'):
        item=run.get(key);require(isinstance(item,dict) and item.get('id')==int(repo_id) and item.get('full_name')==repo,'repository_mismatch')
    suite=run.get('check_suite_id');require(type(suite) is int and suite>0,'missing_check_suite')
    require(isinstance(job,dict) and job.get('id')==int(identity.check_run_id) and job.get('head_sha')==sha
            and job.get('name')=='Publish Laravel application' and job.get('status')=='in_progress'
            and isinstance(job.get('check_suite'),dict) and job['check_suite'].get('id')==suite
            and isinstance(job.get('app'),dict) and job['app'].get('slug')=='github-actions','job_mismatch')
    require(isinstance(environment,dict) and environment.get('id')==env_id and environment.get('name')=='production'
            and environment.get('can_admins_bypass') is False,'environment_mismatch')
    require(timestamp(environment.get('updated_at')) < timestamp(run.get('created_at')),'environment_changed_during_run')
    rules=environment.get('protection_rules');require(isinstance(rules,list),'rules_missing')
    required=[rule for rule in rules if isinstance(rule,dict) and rule.get('type')=='required_reviewers']
    require(len(required)==1 and required[0].get('prevent_self_review') is True,'review_rule_missing')
    configured=required[0].get('reviewers');require(isinstance(configured,list) and configured,'reviewers_missing')
    ids=[]
    for item in configured:
        require(isinstance(item,dict) and item.get('type')=='User' and isinstance(item.get('reviewer'),dict),'reviewer_type_unsupported')
        ident=item['reviewer'].get('id');require(type(ident) is int and ident in reviewers,'reviewer_not_allowed');ids.append(ident)
    require(isinstance(approvals,list) and len(approvals)==1,'approval_history_ambiguous')
    approval=approvals[0];require(isinstance(approval,dict) and approval.get('state')=='approved'
            and isinstance(approval.get('user'),dict) and approval['user'].get('id') in ids
            and approval['user']['id']!=int(identity.actor_id),'approval_missing')
    environments=approval.get('environments');require(isinstance(environments,list) and len(environments)==1
            and isinstance(environments[0],dict) and environments[0].get('id')==env_id
            and environments[0].get('name')=='production','approval_environment_mismatch')
    require([api.get(path) for path in paths]==first,'github_evidence_changed')
    return {'state':'evidence_consistent','run_id':identity.run_id,'check_run_id':identity.check_run_id}
